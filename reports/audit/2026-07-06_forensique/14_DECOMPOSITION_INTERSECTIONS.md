# FoodKing — Décomposition ultra-profonde : matrice d'intersections & causes racines

> Complément à l'audit forensique. **Décomposition fonctionnalité × fonctionnalité** : où le défaut émerge *à l'intersection* de deux fonctions (pas dans la fonction isolée), + **arbres de cause racine** (fault-trees).
> Orchestration : 12 fonctions-pivots × ~21 autres → **112 cellules d'intersection**, dont **27 vérifiées adversarialement** ; 6 arbres de cause racine. Audit statique, chaque cellule ancrée `fichier:ligne`. **Aucune modification de code.**

---

## 0. Ce que la décomposition révèle

La décomposition révèle que les 40 findings ne sont pas 40 bugs indépendants mais la projection de 6 causes racines structurelles sur un petit nombre de HUBS. Trois zones concentrent les intersections dangereuses.

1) L'AGRÉGAT FISCAL (ZReportService::aggregate, l.210-229) est le point de convergence maximal : il croise le pricing (HT calculé pré-remise, TVA sur prix plein, total_ht jamais peuplé), le scellement (destroy scelle, changeStatus non), l'annulation (PAID jamais remis à REFUNDED → CA fantôme), le paiement différé (fenêtre created_at + exclusion UNPAID → CA orphelin) et l'affectation de séquence (seul le POS reçoit fiscal_sequence_no → ventes kiosk/table invisibles). Toute vente remisée, annulée ou non-POS casse l'égalité HT+TVA=TTC : le Z signé NF525 est faux par construction.

2) LES CHEMINS ARGENT sans atomicité ni sceau : Credit::success (read-modify-write du solde sans lockForUpdate), cashBack/changeStatus (OrderStateMachine::allows renvoie true si from===to → CANCELED→CANCELED rejouable, crédit wallet N fois), Stripe (cast (int) tronque les euros). L'invariant monétaire n'existe qu'en couche applicative, contournable et non testé (sqlite ignore FOR UPDATE).

3) LA FRONTIÈRE PUBLIQUE sans principal authentifié : identité, branch_id, customer_id, propriété et fait « payé » sont LUS dans le body, la seule barrière étant une x-api-key statique livrée au navigateur. La sentinelle branch_id==0 (double sens « sans branche » ET « super-admin ») transforme tout client/employé mal initialisé en god-mode inter-branches.

Fonctions-HUBS par fréquence d'apparition : ZReportService::aggregate, OrderService::changeStatus/cashBack, OrderStateMachine::allows, Credit::success, et les FormRequests de remise (Pos/Table). La sécurité est partout opt-in : posée à la main route par route, absente par défaut. La CI (sqlite + QUEUE=sync + Pusher vide) masque activement 15-20 de ces invariants, d'où des tests verts sur un système fictif.


## 1. Points de levier (1 fix → N cellules effondrées)

> Classés par ROI : le changement structurel, pas le patch symptomatique.

1. 1. Inverser la posture d'accès au Kernel : auth:sanctum (+ scope branche) porté par le groupe racine `api`, authorize() par défaut. Chaque route publique devient une exception d'une allowlist unique. Effondre ~20 findings ET neutralise la régression future. ROI maximal.
2. 2. Établir un principal authentifié serveur et en DÉRIVER l'identité (branch_id, customer_id, ownership, fait « payé » via webhook PSP) au lieu de la lire du body ; QR = token de table signé. Effondre la strate endpoints publics (~12-15) : cross-branch, faux paiement, IDOR.
3. 3. Descendre l'invariant argent en base : UNIQUE sur transactions.order_id (+ clé d'idempotence) rendant double-paiement/remboursement impossible, servi par un seul MoneyMutationService (transaction + FOR UPDATE agnostique driver). Effondre le cluster atomicité/argent (~8-12).
4. 4. Matérialiser le scellement en état persistant (colonne sealed_at) via une garde globale Order::updating/deleting rejetant toute mutation d'une ligne scellée (couvre save, mass-update, changeStatus). Effondre ~11-13 findings : falsification rétroactive du Z, ledger, audit.
5. 5. Détruire la surcharge branch_id==0 : colonne NOT NULL sans default(0), FK réelle ; « voir toutes branches » = capacité explicite ($user->canSeeAllBranches()) jamais inférée d'un entier magique. Effondre l'escalade god-mode sur 3 sites et ~4-5 findings.
6. 6. Corriger l'assiette fiscale à la source : total_ht/total_tva calculés APRÈS remise dans une primitive unique, fiscal_sequence_no affecté sur TOUS canaux (kiosk/table), payment_status remis à REFUNDED sur cashBack. Rétablit HT+TVA=TTC et la complétude NF525.
7. 7. Instaurer un tier CI « prod-parité » obligatoire : MySQL réel (verrous ligne), file async réelle, broadcaster réel, tests à requêtes parallèles sur le même agrégat. Ne répare rien mais cesse de cacher : les invariants argent/async/canal cassent enfin la CI.


## 2. Fichiers-hubs de risque (apparaissent le plus aux intersections)

- app/Services/Fiscal/ZReportService.php::aggregate (l.210, 217, 228, 229) — HUB #1, converge pricing, scellement, annulation, paiement différé, séquence fiscale (7+ cellules)
- app/Services/OrderService.php::changeStatus / cashBack (l.1435-1494, 534, 862) — HUB argent+fiscal : rejeu cashBack, pas de remise à REFUNDED, seal absent, dispatch hors transaction
- app/Services/OrderStateMachine::allows (l.29) — from===to renvoie true : porte d'entrée de tous les rejeux CANCELED→CANCELED
- app/Http/PaymentGateways/Gateways/Credit.php (l.33, 79) — token rand() devinable + solde sans lockForUpdate : route non authentifiée sur chemin argent
- branch_id==0 (sentinelle) — BranchScope:33, DefaultAccessModelTrait:21, channels.php:33, EmployeeRequest:56 : escalade admin structurelle multi-sites
- FormRequests de remise — PosOrderRequest:143 (pct sur subtotal client), TableOrderRequest:36 (aucun gate, route non auth) : remise = pivot d'effondrement
- app/Http/PaymentGateways/Gateways/Stripe.php:47 — cast (int) tronque les euros : montant chargé != comptabilisé
- Routes publiques — routes/web.php:40 (payment.success sans auth), api.php:1007/929 (table-QR, loyalty/register), RefreshTokenController:24 (borne→token '*')
- app/Services/Menu/AvailabilityService.php:124::decrementForOrder — read-modify-write sans lock : survente + auto-86 jamais déclenché
- app/Jobs/DispatchDomainEventsJob.php:96 — dispatched_at=now() même sans broadcast : masque l'invariant de livraison sous CI


**Notes :**
- La remise est le PIVOT de pricing : un seul montant client non contraint casse simultanément auth, fiscal, fidélité, idempotence, isolation-branche et remboursement. Six cellules critiques partagent cette racine unique.
- Les leviers 1 et 2 se recouvrent (contrôle d'accès) mais sont complémentaires : le 1 ferme par défaut, le 2 établit QUI agit. Appliquer les deux, pas l'un OU l'autre.
- Le levier 7 (CI prod-parité) est un MULTIPLICATEUR, pas un correctif : sans lui, les leviers 3/4/6 peuvent régresser sans détection. À prioriser tôt malgré son ROI direct nul.
- Deux familles de fuite fiscale : (a) assiette fausse (HT pré-remise) et (b) périmètre incomplet (ventes non-POS, CA annulé encore PAID). Corriger l'une laisse le Z faux ; traiter les deux.
- IDOR de lecture (show, transactions inter-branches) et Transaction sans BranchScope ne sont PAS couverts par les leviers argent/sceau : exigent le principal authentifié (levier 2) + scope branche systématique.
- Ordre recommandé : 7 (démasquer) → 1+2 (fermer la frontière) → 3+4 (verrouiller argent/sceau) → 5 (branch_id) → 6 (assiette fiscale). Chaque étape rend la suivante testable.


---

## 3. Matrice d'intersections (par fonction-pivot)

> Chaque ligne = un croisement où un défaut réel émerge. `★` = confirmé par vérification adversariale.


### ⛓️ Pricing / SSOT prix

| Sév | Croisé avec | Mode de défaillance à l'intersection | Emplacement |
|:--:|---|---|---|
| 🔴 | Fiscal / Z ★ | TVA calculee sur prix PLEIN (PricingService:147/221), remise soustraite apres. Le Z agrege total_ht=subtotal PRE-remise vs total_ttc POST-remise; total_ht n'est peuple nulle part. Donc HT+TVA != TTC d | `app/Services/Fiscal/ZReportService.php:229` |
| 🔴 | Especes / Kiosk / Table-QR ★ | Seul posOrderStore assigne fiscal_sequence_no (OrderService:862). Les commandes kiosk cash PAID et table-QR (FrontendOrder) n'en recoivent jamais. Or l'agregat Z exige whereNotNull('fiscal_sequence_no | `app/Services/Fiscal/ZReportService.php:210` |
| 🔴 | Annulation / Remboursement ★ | cashBack et refundPoints ne remettent jamais payment_status a un etat rembourse (OrderService:1494). Une commande PAID puis CANCELED reste PAID : son total est toujours somme dans total_ttc; cancel_co | `app/Services/Fiscal/ZReportService.php:217` |
| 🟠 | Disponibilite / 86 / stock | PricingService charge le prix via Item::select('id','price','tax_id') sans jamais consulter ItemBranchAvailability. Un article 86 (mis indisponible via AvailabilityService) reste tarife et vendable su | `app/Services/Pricing/PricingService.php:35` |
| 🟠 | Fidelite (annulation concurrente) | FrontendOrderService::changeStatus n'a ni transaction ni verrou autour du garde de statut. Deux cancel concurrents (double-tap) lisent status<seuil, passent le TOCTOU, appellent chacun cashBack + refu | `app/Services/FrontendOrderService.php:656` |
| 🟠 | Fidelite (endpoint redeem) | LoyaltyController::redeem ecrit une transaction 'redeem' avec order_id=null. refundPoints ne cherche QUE where order_id=$order->id : les points debites via redeem() ne sont jamais rembourses a l'annul | `app/Services/LoyaltyService.php:27` |
| 🟠 | Fidelite (double deduction) | Aucune garde structurelle contre la double deduction : redeem() decremente loyalty_points (order_id=null) ET le bloc inline kiosk redecremente a la creation. Seul un commentaire de politique (Frontend | `app/Services/FrontendOrderService.php:476` |
| 🟠 | Remise manuelle / Auth | DiscountCalculator::manualDiscount accepte n'importe quel montant jusqu'au subtotal complet, sans controle de role ni plafond %. PricingService l'applique des context in ['pos','table']. Un POS Operat | `app/Services/Pricing/DiscountCalculator.php:22` |
| 🟡 | Isolation-branche / Idempotence | Le verrou d'idempotence est par branch_id+cle (FrontendOrderService:132) mais la recherche et la contrainte UNIQUE sur idempotency_key sont GLOBALES (:136, 23000 :595). Deux kiosks de branches differe | `app/Services/FrontendOrderService.php:136` |
| ⚪ | Fidelite / Idempotence (retry) | Sur rejeu idempotent, loyaltyApplied est reconstruit via ($existing->discount > 0), qui confond remise coupon/manuelle et remise fidelite. Le kiosk affiche un toast 'points appliques' faux quand la re | `app/Services/FrontendOrderService.php:141` |

### ⛓️ Remise / autorisation

| Sév | Croisé avec | Mode de défaillance à l'intersection | Emplacement |
|:--:|---|---|---|
| 🔴 | Auth / seuils de rôle (POS) ★ | Le gate calcule pct = discount/subtotal sur le subtotal CLIENT (declare non fiable). Un caissier gonfle 'subtotal' pour ramener pct sous 10% et franchir le seuil manager/owner ; le serveur applique la | `app/Http/Requests/PosOrderRequest.php:143` |
| 🔴 | Tables-QR (route non authentifiee) ★ | TableOrderRequest n'a AUCUN gate remise (authorize()=true) et /table/dining-order est non authentifiee (QR, apiKey partagee). tableOrderStore passe request->discount a PricingService qui l'applique (c | `app/Http/Requests/TableOrderRequest.php:36` |
| 🔴 | Fiscal / rapport Z (NF525) ★ | aggregate() prend total_ht depuis orders.subtotal (pas de colonne total_ht, fallback ??) donc le brut, et total_tva sur base pre-remise, alors que total_ttc=order.total est net de remise. Pour toute v | `app/Services/Fiscal/ZReportService.php:229` |
| 🟠 | Fidelite / usurpation d'identite | La reduction fidelite kiosk resout le client uniquement par loyalty_code fourni par le client (aucun lien d'ownership/auth), decremente les points de CE compte et les applique en remise sur la command | `app/Services/FrontendOrderService.php:463` |
| 🟠 | Panier / idempotence (coupon) | limit_per_user est controle en comptant les OrderCoupon dans validateCouponForOrder, mais OrderCoupon n'est cree qu'apres save() sans verrou couvrant check->insert. Deux soumissions concurrentes ou un | `app/Services/CouponService.php:308` |
| 🟠 | Historique / audit du motif | discount_reason (motif obligatoire) est valide dans la FormRequest mais jamais persiste sur la commande, et l'autorisateur n'est pas enregistre. Une remise autorisee ne laisse aucune trace structurell | `app/Http/Requests/PosOrderRequest.php:132` |
| 🟡 | Isolation-branche (coupon) | resolveCouponById fait Coupon::find() sans scope de branche (coupons sans branch_id) et, sur les routes table/kiosk non authentifiees, BranchScope est inerte (Auth::check() faux). Un coupon configure  | `app/Services/CouponService.php:250` |
| 🟡 | Remboursement / retour | refundPoints() (reversal fidelite) et cashBack ne sont invoques que sur CANCELED/REJECTED. La transition DELIVERED->RETURNED (retour) ne declenche ni l'un ni l'autre : une commande retournee ayant con | `app/Services/OrderService.php:1480` |

### ⛓️ Paiement / encaissement

| Sév | Croisé avec | Mode de défaillance à l'intersection | Emplacement |
|:--:|---|---|---|
| 🔴 | auth/token ★ | Route payment.success sous le seul middleware 'installed', aucun auth. Le gateway Credit y decremente le solde du PROPRIETAIRE de la commande (Credit.php:79), pas de l'appelant, et le token est un ran | `routes/web.php:40` |
| 🔴 | pricing ★ | 'amount' => (int) $order->total * 100 : le cast (int) tronque les euros AVANT conversion en centimes. 12.99EUR est encaisse 12.00EUR chez Stripe; la part fractionnaire n'est jamais prelevee alors que  | `app/Http/PaymentGateways/Gateways/Stripe.php:47` |
| 🔴 | panier/idempotence ★ | Credit::success lit le solde puis ecrit balance - total sans lockForUpdate dans la transaction. Deux appels concurrents (route non authentifiee, rejouable) sur deux commandes du meme user lisent le me | `app/Http/PaymentGateways/Gateways/Credit.php:79` |
| 🟠 | annulation/remboursement | L'agregat Z somme total pour toute commande non-UNPAID sans exclure les CANCELED/RETURNED (le cancel ne remet pas payment_status, OrderService:1445); elles ne sont comptees qu'a part en cancel_count ( | `app/Services/Fiscal/ZReportService.php:217` |
| 🟠 | idempotence/borne | orders.transaction_id n'a AUCUNE contrainte unique (migration 2026_03_25_004307:22). paymentConfirm est idempotent seulement par commande via lock, mais ne verifie jamais que le transaction_id TPE a e | `app/Http/Controllers/Frontend/OrderController.php:113` |
| 🟠 | isolation-branche | Le garde de branche de changePaymentStatus est 'if ($userBranch && ...)'. Un non-Admin dont branch_id vaut 0 ou null traverse le && et peut modifier le payment_status (dont PAID->REFUNDED, qui impacte | `app/Services/OrderService.php:1589` |
| 🟠 | remboursement/especes | cashBack credite le solde user de order->total des qu'une transaction de paiement existe, sans garde d'unicite (pas de verif qu'un cash_back existe deja) ni MAJ de order->total. Appels repetes = credi | `app/Services/PaymentService.php:31` |
| 🟡 | fidelite | refundPoints relit a chaque appel les transactions 'redeem' et ecrit le contre-passage en type 'manual_add' (l.66) sans jamais marquer le redeem consomme. Une seconde annulation recredite les memes po | `app/Services/LoyaltyService.php:35` |
| 🟡 | offline/infra-testable | refundPoints saute lockForUpdate quand le driver est sqlite. L'infra de test (sqlite:memory) n'exerce donc jamais le verrou qui protege les credits fidelite concurrents => l'invariant de concurrence e | `app/Services/LoyaltyService.php:42` |
| 🟡 | historique/audit | PaymentService::payment (encaissement en ligne Credit/Stripe) passe la commande en PAID sans ecrire d'entree AuditLogService, alors que cashBack et changePaymentStatus le font. Les paiements en ligne  | `app/Services/PaymentService.php:26` |

### ⛓️ Remboursement (cashBack / changeStatus / refundP

| Sév | Croisé avec | Mode de défaillance à l'intersection | Emplacement |
|:--:|---|---|---|
| 🔴 | panier/idempotence ★ | allows() renvoie true si from===to (OrderStateMachine:29), donc CANCELED->CANCELED passe la garde. cashBack ne verifie que l'existence d'une transaction de paiement (PaymentService:33), jamais qu'un c | `app/Services/OrderService.php:1436` |
| 🔴 | fiscal/Z (CA surevalue) ★ | cashBack ne remet jamais payment_status a UNPAID/REFUNDED (PaymentService:31-72). Le Z somme $o->total pour tout order != UNPAID (ZReportService:228) : une commande annulee+remboursee reste PAID et re | `app/Services/Fiscal/ZReportService.php:228` |
| 🟠 | fiscal/Z (refund_count mort) | refund_count compte status == RETURNED (ZReportService:238) mais tous les remboursements mettent CANCELED (OrderService:1480, FrontendOrderService:669). RETURNED n'est atteignable que depuis DELIVERED | `app/Services/Fiscal/ZReportService.php:238` |
| 🟠 | especes/rendu (kiosk mauvais walle | cashBack credite User::find($order->user_id)->balance (PaymentService:44-48). Pour une commande kiosk, user_id = compte KioskMachine (FrontendOrderService:196), pas le client. Le remboursement est cre | `app/Services/PaymentService.php:44` |
| 🟠 | annulation/atomicite | Le chemin d'annulation client n'est pas dans DB::transaction : FrontendOrderService::changeStatus enchaine cashBack + refundPoints + save a nu (660-670), idem OrderService voie auth (1436-1446), tandi | `app/Services/FrontendOrderService.php:660` |
| 🟠 | fidelite (double re-credit / perte | refundPoints re-incremente loyalty_points sans garde d'idempotence (aucun controle qu'un reversal existe) : couple a la boucle CANCELED->CANCELED, points re-credites a chaque appel. Inversement si loy | `app/Services/LoyaltyService.php:27` |
| 🟡 | paiement (montant rembourse) | cashBack rembourse toujours amount => $order->total (PaymentService:38) et verifie seulement qu'une transaction existe (l.33), sans lire le montant reellement encaisse ($transaction->amount). Sur comm | `app/Services/PaymentService.php:38` |
| 🟡 | isolation-branche (ledger) | Transaction n'a ni branch_id ni fiscal_sequence_no (fillable Transaction:10) : les cash_back ne sont rattachables a une branche que par jointure order. L'AuditLog du remboursement retombe sur branch_i | `app/Models/Transaction.php:10` |

### ⛓️ Annulation / transitions terminales (OrderServic

| Sév | Croisé avec | Mode de défaillance à l'intersection | Emplacement |
|:--:|---|---|---|
| 🔴 | Remboursement (cashBack) ★ | OrderStateMachine::allows() renvoie true si from===to (l.29), donc CANCELED->CANCELED passe. changeStatus rejoue cashBack a chaque POST : transaction '-' + user->balance += order->total, sans idempote | `app/Services/OrderService.php:1435` |
| 🔴 | Fiscal / Z (sceau NF525) ★ | destroy() bloque (409) une commande scellee par un ZReport clos (l.1723-1736), mais changeStatus->CANCELED/REJECTED n'a AUCUN controle de sceau : une commande deja dans un Z ferme peut etre annulee +  | `app/Services/OrderService.php:1480` |
| 🔴 | Isolation-branche (branch_id==0) ★ | Le garde du cancel non-auth teste 'if ($userBranch && $userBranch !== order->branch_id)'. Un employe non-Admin avec branch_id=0 (sentinelle admin) rend la condition falsy : il annule/rembourse les com | `app/Services/OrderService.php:1475` |
| 🟠 | Auth/token (KDS cross-branche) | KDS::changeStatus n'a AUCUN controle de branche (contrairement a OrderService l.1473-1477), juste le middleware permission. Un ecran cuisine d'une branche peut faire transiter (dont ACCEPT/PREPARING-> | `app/Services/KitchenDisplaySystemOrderService.php:115` |
| 🟠 | Paiement (kiosk paye en ligne) | CleanupStalePendingKioskOrders rejette les FrontendOrder PENDING kiosk via apply() sans verifier payment_status ni appeler cashBack/refundPoints. Une commande kiosk payee (Stripe/PayPal) restee PENDIN | `app/Jobs/CleanupStalePendingKioskOrders.php:32` |
| 🟠 | Fidelite (points) | refundPoints recredite les LoyaltyTransaction 'redeem' de la commande sans verifier qu'un reversal existe deja ; les lignes 'redeem' ne sont jamais consommees. Chaque annulation rejouee (CANCELED->CAN | `app/Services/LoyaltyService.php:27` |
| 🟠 | KDS x remboursement | KDS::changeStatus peut piloter ACCEPT/PREPARING->CANCELED (autorise par ValidStatusTransition) mais n'appelle jamais cashBack ni refundPoints, contrairement a OrderService. Une annulation emise depuis | `app/Services/KitchenDisplaySystemOrderService.php:129` |
| 🟠 | Atomicite (chemin argent) | Le self-cancel client (auth=true) execute cashBack() + refundPoints() + save SANS DB::transaction (l.1436-1446), alors que le non-auth les enveloppe (l.1470). Si refundPoints leve apres cashBack, arge | `app/Services/OrderService.php:1436` |
| 🟡 | Historique / audit | Les cancel utilisent recordTransition best-effort (echec seulement logge, l.108-110) au lieu du apply() atomique. Le statut + cashBack peuvent committer alors que la ligne order_status_transitions man | `app/Domain/Order/OrderStateMachine.php:108` |
| 🟡 | Contrat reason (StateMachine) | requiresReason(CANCELED)=true et le cancel non-auth valide reason=required (l.1481), mais le self-cancel client rend le motif optionnel ('if ($request->reason)', l.1432). L'invariant 'annulation exige | `app/Services/OrderService.php:1432` |

### ⛓️ Fiscal / Z-report / scellement (ZReportService, 

| Sév | Croisé avec | Mode de défaillance à l'intersection | Emplacement |
|:--:|---|---|---|
| 🔴 | annulation/statut | changeStatus (chemin staff) ne fait AUCUN controle de scellement : une commande deja figee dans un Z cloture peut passer a CANCELED. Le total_ttc signe du Z l'inclut toujours, et verifySignature ne re | `app/Services/OrderService.php:1499` |
| 🔴 | paiement ★ | aggregate() borne la fenetre sur created_at ET filtre payment_status!=UNPAID a l'instant de close. Une commande creee dans la fenetre N mais payee APRES la cloture de Z_N n'entre jamais dans Z_N (UNPA | `app/Services/Fiscal/ZReportService.php:210` |
| 🔴 | annulation ★ | Le chemin cancel (1487) appelle cashBack mais ne remet jamais payment_status a UNPAID/REFUNDED ; la commande reste PAID. aggregate n'exclut que UNPAID, donc une commande annulee-mais-PAID est sommee d | `app/Services/Fiscal/ZReportService.php:217` |
| 🟠 | remise/pricing | totalHt += total_ht ?? subtotal. total_ht n'est jamais renseigne sur les commandes POS -> fallback subtotal, montant AVANT remise (l.820). total_ttc lui est post-remise. Consequence: total_ht + total_ | `app/Services/Fiscal/ZReportService.php:229` |
| 🟠 | remboursement | refund_count compte status==RETURNED, mais tout remboursement reel passe par cashBack sous status CANCELED/REJECTED (jamais RETURNED). refund_count reste donc a 0 en permanence : le Z sous-declare str | `app/Services/Fiscal/ZReportService.php:238` |
| 🟠 | base-de-donnees/migrations | La table z_reports n'a aucun trigger ni contrainte d'immutabilite : une ligne CLOSED peut etre UPDATE librement (contrairement a audit_logs). Le sceau NF525 n'est qu'une seule verif applicative (destr | `database/migrations/2026_04_22_000003_create_z_reports_table.php:27` |
| 🟠 | auth/token | L'auto-annulation client (changeStatus $auth=true) mute le statut, rembourse et refundPoints mais n'ecrit AUCUN AuditLog et n'a aucun controle de scellement. Une annulation d'une commande fiscalement  | `app/Services/OrderService.php:1443` |
| 🟠 | isolation-branche (branch_id==0) | next() jette sur branch_id<=0, mais un admin (branch_id=0, l.579) peut creer une commande ; si branch_id retombe a 0 la sequence fiscale echoue. A l'inverse AuditLogService accepte branch_id=0 comme c | `app/Services/Fiscal/FiscalSequenceService.php:59` |
| 🟡 | offline/infra-non-testable | L'invariant sequence gap-free repose sur lockForUpdate, no-op en SQLite (commentaire l.86), et Cache::lock est no-op en driver array/sync. Sous sqlite:memory + QUEUE=sync la course concurrente et l'an | `app/Services/Fiscal/FiscalSequenceService.php:86` |
| 🟡 | pricing (TVA) | total_tva somme order.total_tax (l.230) tandis que total_by_tax_rate somme order_items.tax_amount (l.250). Si order.total_tax est null (0) alors que les items portent de la TVA, total_tva=0 mais la ve | `app/Services/Fiscal/ZReportService.php:230` |

### ⛓️ Isolation de branche (BranchScope + DefaultAcces

| Sév | Croisé avec | Mode de défaillance à l'intersection | Emplacement |
|:--:|---|---|---|
| 🔴 | tables-QR ★ | Endpoint QR non authentifie (middleware installed/apiKey/throttle seulement) : branch_id client (regle 'required/numeric', sans exists/min) ecrit tel quel dans FrontendOrder. Sans Auth le BranchScope  | `routes/api.php:1007` |
| 🔴 | auth/token ★ | Surcharge branch_id==0. EmployeeRequest autorise branch_id 'nullable/numeric' (ni min:1 ni exists). Un employe NON-admin cree a branch_id=0/null est traite par toute la logique d isolation comme 'admi | `app/Http/Requests/EmployeeRequest.php:56` |
| 🔴 | paiement/historique ★ | Le modele Transaction n a AUCUN BranchScope, et TransactionService::list ne filtre par branche que si le client fournit branch_id (optionnel). Tout staff authentifie qui omet ce filtre lit les transac | `app/Services/TransactionService.php:32` |
| 🟠 | outbox/temps-reel | Deux sources de verite divergentes : l autorisation de canal utilise user->branch_id, le scope requetes utilise DefaultAccess.default_id. Un user user->branch_id=0 mais DefaultAccess=branche X s abonn | `routes/channels.php:33` |
| 🟠 | KDS | KitchenDisplaySystemOrderService lit auth()->user()->branch_id ?? 0 et traite 0 = 'toutes branches'. users.branch_id etant nullable, un user a branch_id NULL est coalesce en 0 -> l ecran cuisine affic | `app/Services/KitchenDisplaySystemOrderService.php:54` |
| 🟠 | annulation/remboursement | Le garde de changeStatus 'if ($userBranch && $userBranch !== order->branch_id) abort' est falsy quand userBranch=0/null (admin surcharge/staff a 0) : annulation/remboursement d une commande d une autr | `app/Services/OrderService.php:1475` |
| 🟠 | OSS | OrderStatusScreenOrderService reproduit auth()->user()->branch_id ?? 0 : un operateur a branch_id 0/null fait afficher sur l ecran statut public (surface salle) les commandes de toutes les branches. F | `app/Services/OrderStatusScreenOrderService.php:67` |
| 🟡 | fidelite | La fidelite est clef sur loyalty_code GLOBAL sans notion de branche (User::where('loyalty_code',...)). Couple au garde inter-branches contournable (changeStatus l.1494 refundPoints), des points redime | `app/Services/LoyaltyService.php:40` |
| 🟡 | panier/idempotence | Le pre-check Order::where('idempotency_key') subit le BranchScope alors que le UNIQUE en base est global. Une clef vue en branche B est invisible au pre-check de A -> l INSERT frappe le UNIQUE (23000) | `app/Services/OrderService.php:555` |
| 🟡 | base-de-donnees/migrations | orders/order_items/dining_tables ont foreignId(branch_id)->constrained (branche 0 impossible en MySQL) mais users.branch_id est nullable default 0 SANS FK. Le sentinel 0=admin n a aucune assise struct | `database/migrations/2014_10_12_000000_create_users_table.php:28` |

### ⛓️ Auth / tokens / borne (pivot)

| Sév | Croisé avec | Mode de défaillance à l'intersection | Emplacement |
|:--:|---|---|---|
| 🔴 | auth/token x paiement/tout : escal ★ | RefreshTokenController recree un token '*' a partir de N'IMPORTE quel token, sans verif de proprietaire, sur une route protegee par la seule apiKey statique. Un token borne 'kiosk:order' -> POST /refr | `app/Http/Controllers/Auth/RefreshTokenController.php:24` |
| 🔴 | auth/token x isolation-branche : b ★ | Proprietaire borne = user admin branch_id=0 (channels.php:21-24). Gate::before renvoie true pour tout ability si hasRole('admin'), et le groupe /admin (api.php:229) n'a aucun middleware d'ability. Un  | `app/Providers/AuthServiceProvider.php:30` |
| 🔴 | annulation/remboursement x branche | changeStatus autorise l'annulation si order->user_id === Auth::id(). Toute commande borne est creee avec le user borne partage, donc une borne peut annuler une commande d'une AUTRE borne/branche et de | `app/Services/FrontendOrderService.php:641` |
| 🔴 | auth/borne x paiement : PAID auto- ★ | paymentConfirm passe payment_status=PAID a partir d'un transaction_id fourni par le client Electron, sans aucune verif Stripe/PayPal/TPE. Le controle order->user_id === user est trivialement satisfait | `app/Http/Controllers/Frontend/OrderController.php:111` |
| 🟠 | remboursement x fidelite : mauvais | cashBack credite le balance de order->user_id du total. Sur commande borne, user_id = user machine/admin (pas le client payeur) : l'annulation credite le compte machine. Combine a l'annulation cross-b | `app/Services/PaymentService.php:44` |
| 🟠 | auth/token x especes/rendu : PIN a | SettingResource renvoie kiosk_admin_pin (defaut '1234') a tout token kiosk:order via /frontend/setting. Le token borne est detenu cote client (Electron/navigateur), donc le PIN d'override manager (ann | `app/Http/Resources/SettingResource.php:129` |
| 🟠 | auth/token x multi-borne/offline : | kiosk-login fait tokens()->where('name','kiosk-token')->delete() : revoque TOUS les tokens kiosk du user. Si plusieurs bornes partagent le user (design borne=admin), la connexion de la borne B invalid | `app/Http/Controllers/Auth/KioskMachineLoginController.php:81` |
| 🟡 | auth/token x temps-reel : canal br | Autorisation du canal branch.{id} pour un token borne : KioskMachine::where('user_id',$user->id)->first() choisit une branche arbitraire si le user a plusieurs machines -> ecoute d'un mauvais flux. Et | `routes/channels.php:28` |
| 🟡 | auth/token x panier : autorisation | OrderRequest::authorize() retourne true en dur : la creation de commande n'applique aucune regle d'autorisation au niveau requete, la securite depend de tokenCan() opt-in dispersees dans le service. S | `app/Http/Requests/OrderRequest.php:20` |
| 🟡 | apiKey x auth : cle statique parta | ApiKeyMiddleware compare une cle unique statique (app.api_key) via === non constant-time, partagee POS/kiosk/web. Seule barriere devant /refresh-token et /kiosk-login. Sa fuite (embarquee dans chaque  | `app/Http/Middleware/ApiKeyMiddleware.php:24` |

### ⛓️ Disponibilité / 86 / stock (AvailabilityService 

| Sév | Croisé avec | Mode de défaillance à l'intersection | Emplacement |
|:--:|---|---|---|
| 🔴 | panier / catalogue kiosk ★ | La grille kiosk consomme GET /frontend/item?surface=kiosk (simpleList) qui ne joint jamais item_branch_availability ni ne filtre branch_id ; SimpleItemResource ne porte aucun is_available. Le 86 est d | `app/Services/ItemService.php:88` |
| 🔴 | paiement | Aucun controle de disponibilite a la creation de commande : OrderService/FrontendOrderService encaissent (Stripe/PayPal) sans jamais appeler isAvailable(). Le seul hook stock, decrementForOrder, s'exe | `app/Services/Menu/AvailabilityService.php:118` |
| 🔴 | atomicite argent (concurrence) ★ | decrementForOrder lit la ligne SANS lockForUpdate (toggle() ligne 42 verrouille pourtant) puis read-modify-write de daily_consumed_qty. Deux commandes concurrentes lisent la meme valeur -> lost update | `app/Services/Menu/AvailabilityService.php:124` |
| 🟠 | auth / token | Le groupe frontend (installed/apiKey/localization) n'a PAS auth:sanctum, ni le sous-groupe item. GET /frontend/item est non authentifie : branch_id est un parametre client passe par kioskMenu.js (l.24 | `routes/api.php:809` |
| 🟠 | isolation-branche / branch_id==0 | decrementForOrder prend order->branch_id brut : une commande branch_id==0 ne matche aucune ligne -> jamais decrementee, et isAvailable(item,0) renvoie true par defaut (l.111). Les commandes branch 0 c | `app/Services/Menu/AvailabilityService.php:120` |
| 🟠 | outbox / temps-reel | Le listener DecrementItemAvailabilityOnOrder est synchrone (pas de ShouldQueue) et s'execute dans OrderCreated::dispatch, enveloppe dans un try/catch qui ne fait qu'un Log::warning. Toute exception du | `app/Services/OrderService.php:534` |
| 🟡 | annulation / remboursement | decrementForOrder n'incremente que daily_consumed_qty et pose l'auto-86 ; aucun chemin inverse a l'annulation/remboursement. Une commande annulee consomme definitivement le stock du jour et peut maint | `app/Services/Menu/AvailabilityService.php:140` |
| 🟡 | offline / reconnexion | Cache memoire TTL 5 min + snapshot offline (SET_FROM_CACHE) servent un catalogue perime issu de frontend/item, sans donnee de disponibilite. Au refetch (SET_ITEMS) state.items est reecrit sans availab | `resources/js/store/modules/kioskMenu.js:114` |
| 🟡 | temps-reel (patch broadcast) | UPDATE_ITEM patche is_available en place depuis le broadcast branch.{id}, mais SET_ITEMS au moindre refetch (nav categorie, TTL, force) remplace state.items par le catalogue sans availability -> le fl | `resources/js/store/modules/kioskMenu.js:114` |
| ⚪ | historique / audit | L'auto-86 (unavailable_reason='out_of_stock') ne fait qu'un dispatchEvent et n'ecrit aucune entree AuditLogService, alors que la remise ecrit un audit structure (OrderService:921). Les ruptures automa | `app/Services/Menu/AvailabilityService.php:145` |

### ⛓️ Fidélité (loyalty)

| Sév | Croisé avec | Mode de défaillance à l'intersection | Emplacement |
|:--:|---|---|---|
| 🔴 | auth/token ★ | POST /loyalty/register est public (api.php:929). Sur collision d'email le 409 renvoie existing_loyalty_code + existing_phone d'un autre compte. Un attaquant non authentifie enumere des emails et recup | `app/Http/Controllers/Frontend/LoyaltyController.php:136` |
| 🟠 | annulation | Le job d'auto-rejet des commandes kiosk PENDING perimees applique REJECTED sans appeler refundPoints. Une commande ayant debite des points (FrontendOrderService:478) puis abandonnee est rejetee a 15 m | `app/Jobs/CleanupStalePendingKioskOrders.php:32` |
| 🟠 | remboursement | DELIVERED->RETURNED est autorise (OrderStateMachine:58) et l'award se fait a DELIVERED, mais refundPoints n'est appele que sur CANCELED/REJECTED (OrderService:1435,1480), jamais sur RETURNED. Un retou | `app/Services/OrderService.php:1480` |
| 🟠 | fiscal/Z | Dans l'agregation Z, total_ttc = order.total (net de remise) mais total_ht = subtotal (brut) et total_tva sur le brut. Des qu'une remise fidelite s'applique, HT+TVA ne reconcilie plus avec le TTC : le | `app/Services/Fiscal/ZReportService.php:229` |
| 🟠 | panier/idempotence | Double deduction : redeem (LoyaltyController:302) est appelable par un token kiosk (tokenCan kiosk:order) ET FrontendOrderService redebite les points inline au store (478). Un kiosk peut appeler /rede | `app/Services/FrontendOrderService.php:478` |
| 🟠 | annulation | refundPoints n'a aucune garde d'idempotence : il relit les txns 'redeem' et recredite (increment l.56) a chaque appel sans marquer le redeem comme rembourse. Deux annulations concurrentes lisent statu | `app/Services/LoyaltyService.php:56` |
| 🟡 | isolation-branche | Les resolutions fidelite (check:72, redeem:274, scan:620, FrontendOrderService:463) font User::where('loyalty_code') sans filtre succursale, et users.branch_id vaut 0 par defaut (migration users:28).  | `app/Services/FrontendOrderService.php:463` |
| ⚪ | historique/audit | refundPoints ecrit le remboursement d'annulation avec type='manual_add' (l.66) au lieu de 'refund'. Dans /loyalty/history (LoyaltyController:485) un remboursement s'affiche comme ajout manuel staff, e | `app/Services/LoyaltyService.php:66` |

### ⛓️ Outbox / evenements / temps-reel / reconnexion (

| Sév | Croisé avec | Mode de défaillance à l'intersection | Emplacement |
|:--:|---|---|---|
| 🔴 | Atomicite / chemins argent ★ | OrderCreated::dispatch est appele HORS de la DB::transaction (fermee L523), dans un try/catch qui avale. Le listener outbox ecrit donc en post-commit non-atomiquement : si l'INSERT echoue, la commande | `app/Services/OrderService.php:534` |
| 🔴 | Infra non testable (masquage) ★ | handle() ecrit dispatched_at=now() (L96) meme quand il SAUTE le broadcast (branche 'Pusher not configured' L85, simple Log::info). Sous QUEUE=sync + PUSHER_APP_KEY vide (CI/sqlite), chaque evenement e | `app/Jobs/DispatchDomainEventsJob.php:96` |
| 🔴 | Notifications / fidelite | Listeners OrderStatusChanged synchrones dans l'ordre : AwardLoyaltyPoints, SendFcm, PersistOutbox (dernier). Si le listener fidelite ou FCM leve une exception, l'outbox n'est jamais atteint -> aucun e | `app/Providers/EventServiceProvider.php:96` |
| 🟠 | Isolation-branche / auth-token | L'auth du canal branch.{branchId} retourne true pour tout user branch_id===0 (surcharge admin). Or les payloads Order* sur private-branch.{id} contiennent total, queue_number, token et PII. Un compte  | `routes/channels.php:33` |
| 🟠 | Disponibilite / 86 / stock | Le handler de reconnexion WS de la borne (_onWsReconnect) ne relance que la synchro offline ; il ne re-souscrit ni ne re-fetch le menu. Les ItemAvailabilityChanged (86) emis pendant la coupure ne sont | `resources/js/components/frontend/kiosk/KioskAppComponent.vue:263` |
| 🟠 | Stock (decrement commande) | DecrementItemAvailabilityOnOrder est un listener synchrone d'OrderCreated, execute hors transaction et dans le try/catch qui avale (OrderService:534). Le decrement de stock n'est pas atomique avec le  | `app/Listeners/DecrementItemAvailabilityOnOrder.php:15` |
| 🟠 | Annulation / remboursement | Auto-annulation client (branche $auth) : cashBack + refundPoints + save + recordTransition + OrderStatusChanged::dispatch s'enchainent SANS DB::transaction (a la difference de la branche else). Non-at | `app/Services/OrderService.php:1437` |
| 🟡 | KDS (audit / state-machine) | Cote KDS la DB::transaction n'entoure que $order->save() ; recordTransition (audit) et OrderStatusChanged::dispatch sont HORS transaction. Le statut peut committer alors que l'audit echoue -> transiti | `app/Services/KitchenDisplaySystemOrderService.php:128` |
| 🟡 | Pricing / remise | L'edition globale d'un article diffuse un unique price GLOBAL a toutes les branches actives (fan-out Branch::all). Le store borne ecrit ce prix (patch.price=payload.price) malgre le commentaire 'aucun | `resources/js/store/modules/kioskMenu.js:204` |
| 🟡 | Auth-token (echec silencieux) | Si l'auth du canal Echo echoue au boot (token pas pret), _subscribeEchoChannel avale l'erreur sans retry (catch L394) ; onEvents renvoie un no-op si !window.Echo (L65). Sans re-souscription au reconne | `resources/js/components/frontend/kiosk/KioskAppComponent.vue:394` |

### ⛓️ Panier / idempotence (POS+borne)

| Sév | Croisé avec | Mode de défaillance à l'intersection | Emplacement |
|:--:|---|---|---|
| 🔴 | fiscal/Z (sequence NF525) | next() fait MAX(fiscal_sequence_no)+1 sous Cache::lock RELACHE avant le commit de la transaction appelante. Deux caisses concurrentes meme branche lisent le meme MAX -> meme N; la 2e viole orders_bran | `app/Services/OrderService.php:862` |
| 🔴 | isolation-branche | Contrainte DB composite (branch_id, idempotency_key) mais pre-check et recovery 23000 font where('idempotency_key')->first() SANS filtre branche. Une meme cle sur 2 branches renvoie la commande d'une  | `app/Services/FrontendOrderService.php:136` |
| 🟠 | outbox/temps-reel (KDS/OSS) | OrderCreated dispatche apres commit dans un try/catch qui ne fait que logguer. Si commit OK mais broadcast soketi echoue, le client rejoue avec la meme cle: le pre-check idempotence fait un return ant | `app/Services/OrderService.php:556` |
| 🟠 | disponibilite/86/stock | Commande kiosk offline rejouee plus tard. Item 86/supprime entre-temps -> serveur leve 422 'Item introuvable'. syncQueue() attrape TOUTE erreur comme reessayable (pas de distinction 4xx/5xx), puis apr | `resources/js/helpers/kioskOfflineQueue.js:114` |
| 🟠 | auth/token | Le rejeu offline poste vers frontend/order (auth:sanctum + ability kiosk). Si le token kiosk a expire pendant la coupure, postFn recoit 401; syncQueue ne distingue pas 401 d'une panne reseau -> consom | `resources/js/helpers/kioskOfflineQueue.js:108` |
| 🟠 | paiement | Sur erreur reseau, submitOrder resout une reponse SYNTHETIQUE de succes (id=localKey, queue '—', _offline:true) et l'UI confirme la commande, alors qu'aucun paiement n'est capture et que la commande p | `resources/js/store/modules/kioskCart.js:374` |
| 🟠 | especes/rendu (double encaissement | Cle idempotence POS regeneree a CHAQUE ouverture du modal paiement (Date.now()+random). Apres timeout 30s ('Ne relancez pas'), reouvrir le modal emet une NOUVELLE cle: la commande deja creee cote serv | `resources/js/components/admin/pos/PosComponent.vue:1496` |
| 🟡 | remise x fiscal/Z | Le Z agrege total_ht = somme(o->total_ht ?? o->subtotal). Or subtotal est stocke AVANT remise (discount porte separement, total=subtotal+tax-discount). Une commande panier avec remise coupon/manuelle  | `app/Services/Fiscal/ZReportService.php:229` |

---

## 4. Arbres de cause racine (fault-trees)


### RC1 — Aucun principal authentifié serveur sur le périmètre public : l'identité (client, branche, propriété, paiement) est LUE dans le corps de requête, la seule barrière étant une clé statique livrée à chaque navigateur. Le serveur croit le client au lieu d'établir les faits.

**Symptômes visibles :**
- Seule barrière de /frontend/* et /table/* : middleware x-api-key (api.php:809,992) qui compare une constante statique en clair (ApiKeyMiddleware.php:22-30).
- Cette « clé » est sérialisée dans le HTML de chaque navigateur (master.blade.php:80) — publique, donc frontière de confiance nulle.
- Commande table (QR) anonyme : authorize()=true (TableOrderRequest.php:19), branch_id/customer_id pris du body en required|numeric sans lien à la table (33-35 ; route api.php:1007).
- tableOrderStore crée l'ordre depuis le branch_id/customer_id client puis se dit SSOT : commentaire trompeur OrderService.php:1119 vs création 983-995.
- IDOR : Table\OrderController::show lie tout FrontendOrder par id, sans contrôle de propriété ni de branche (OrderController.php:32-39 ; route api.php:1005).
- Argent sur parole : paymentConfirm passe PAID sur un transaction_id string client, sans vérif PSP Stripe/PayPal/TPE (Frontend/OrderController.php:77-113).
- Temps réel : le canal branch.{id} autorise toutes les branches dès branch_id==0, identité dérivée du client (channels.php:33-37).

**Arbre des causes :**

```
RACINE: le périmètre public n'a pas de principal authentifié — l'identité vient du corps de requête, et la « clé » de confiance est publique.

Branche A — « l'auth n'est pas de l'auth »
Symptôme: tout /frontend + /table ne repose que sur x-api-key (api.php:809,992)
-> pourquoi? apiKey = constante statique comparée en clair (ApiKeyMiddleware:22-30)
-> pourquoi? injectée dans window.foodkingConfig de chaque navigateur (master.blade:80)
-> RACINE: une valeur publique sert de frontière de confiance.

Branche B — identité fournie par le client
Symptôme: commande QR anonyme, branch_id/customer_id = required|numeric du body (TableOrderRequest:19,33-35)
-> pourquoi? authorize()=true, aucun principal serveur
-> pourquoi? l'ordre est créé depuis $validated puis se cite comme SSOT (OrderService:983-995,1119)
-> RACINE: pas d'acteur serveur d'où DÉRIVER branch/customer.

Branche C — argent sur parole
Symptôme: paymentConfirm passe PAID sur transaction_id string client, sans PSP (OrderController:77-113)
-> RACINE: le client AFFIRME « payé » au lieu que le serveur le VÉRIFIE.

Branche D — lecture & temps réel
Symptôme: show lie tout ordre sans ownership (Table/OrderController:32-39); canal branch_id==0 = toutes branches (channels:33-37)
-> RACINE: autorisation dérivée de données client, pas d'un principal.

Convergence: un même défaut structurel — aucune identité serveur de confiance à la frontière publique.
```

**🎯 Cause racine :** Aucun principal authentifié serveur à la frontière publique : la seule « auth » est une constante statique (x-api-key) livrée au navigateur. Les surfaces publiques dérivent donc identité, branche, client, propriété et état de paiement des VALEURS FOURNIES PAR LE CLIENT, pas d'un acteur vérifié. La confiance est placée dans la requête, jamais dans un principal.

**🔧 Levier unique :** Exiger un principal authentifié serveur sur chaque écriture publique et en DÉRIVER l'identité, au lieu de la lire du body. Concrètement : ne plus traiter x-api-key comme une auth ; le QR échange un token de table signé lié à dining_table_id+branch_id ; vraie session client ; brancher branch_id, customer_id, ownership et le fait « payé » (webhook/verif PSP) sur ce principal, jamais sur la requête.

**💥 Ce qui s'effondre :** Tombent : cross-branch (branch_id client), attribution à un customer_id arbitraire, IDOR de lecture (show), faux paiement (transaction_id inventé), abonnement multi-branches. Sur 40 findings, la strate « endpoints publics » (~12-15) s'effondre ; restent atomicité et sceau.


### RC2 — Sécurité opt-in : l'authentification, l'autorisation par rôle et le secret d'API ne sont actifs QUE là où un développeur a pensé à les ajouter à la main. Rien n'est fermé par défaut ; l'exposition est l'état natif, la protection est l'exception.

**Symptômes visibles :**
- Groupe `table` sans auth:sanctum (routes/api.php:992) : commande QR ET lecture de commande non authentifiées (dining-order:1004-1007)
- TableOrderController::show (Table/OrderController.php:33) renvoie n'importe quelle FrontendOrder par model-binding, sans contrôle propriété/branche
- Groupe `api` du Kernel (app/Http/Kernel.php:43-52) sans auth par défaut ; Sanctum EnsureFrontendRequestsAreStateful commenté (Kernel:44)
- Groupe `frontend` public par défaut (api.php:809) ; auth re-collé sous-groupe par sous-groupe, donc loyalty/opt-in:975 et coupon-checking:892 restent publics
- Zéro middleware role/permission dans routes/api.php (grep=0) : groupe admin (api.php:229) authentifie mais n'autorise jamais par rôle
- Seulement 3 appels $this->authorize()/Gate sur 120 contrôleurs : autorisation applicative quasi absente et opt-in
- api_key défaut = chaîne vide (config/app.php:63) ; ApiKeyMiddleware compare header===config (ApiKeyMiddleware.php:24), franchissable si secret non configuré
- Canal broadcast : admin branch_id==0 => return true (channels.php:33), sécurité par exception plutôt que liste blanche

**Arbre des causes :**

```
SYMPTÔME: endpoints argent/données atteignables sans auth (table QR store+show, loyalty/opt-in, coupon-checking)
├─ pourquoi? le groupe de routes ne porte pas auth:sanctum au niveau du groupe (api.php:992, 809)
│  └─ pourquoi? auth appliqué en OPT-IN, re-collé à la main sous-groupe par sous-groupe (846/826/876)
│     └─ pourquoi? le groupe `api` du Kernel n'impose aucun auth (Kernel:43-52, Sanctum commenté :44)
│        └─ pourquoi? la posture native du framework (api = ouvert) n'a jamais été INVERSÉE → RACINE

SYMPTÔME: tout rôle connecté atteint les endpoints admin (escalade)
├─ pourquoi? aucun middleware role/permission dans les routes (grep=0) et authorize() 3x/120 ctrl
│  └─ pourquoi? l'autorisation n'est posée que quand un dev y pense, pas exigée structurellement
│     └─ MÊME RACINE: protection = exception explicite, pas défaut

SYMPTÔME: garde apiKey franchissable / canal admin ouvert par branch_id==0
├─ pourquoi? secret défaut vide (config/app.php:63), accès par exception (channels.php:33)
│  └─ MÊME RACINE: le contrôle n'existe que s'il est activé/configuré, sinon il laisse passer

RACINE UNIQUE: aucune posture deny-by-default ; la fermeture n'est jamais l'état par défaut, elle est ajoutée route par route.
```

**🎯 Cause racine :** Aucun point de contrôle ne ferme l'accès par défaut : auth, autorisation par rôle et secret d'API sont des ajouts optionnels posés route par route. La posture native (groupe `api` du Kernel sans auth, defaults vides) n'a jamais été inversée : l'exposition est l'état par défaut et la protection dépend de la vigilance humaine à chaque route.

**🔧 Levier unique :** Inverser la posture au point d'entrée unique : faire porter auth:sanctum (+ abilities/scope branche) par le groupe racine `api` du Kernel, et faire appeler authorize() par défaut dans le contrôleur de base. Toute route publique devient alors une EXCEPTION explicite listée dans une allowlist unique — le seul endroit où l'on retire la protection, au lieu des ~20 endroits où on l'ajoute.

**💥 Ce qui s'effondre :** Toute la classe « endpoint X atteignable sans auth » : table QR store/show, loyalty/opt-in, coupon-checking, + l'escalade de rôle sur le groupe admin. Neutralise la RÉGRESSION FUTURE (route ajoutée = publique par défaut). Soit le sous-ensemble contrôle-d'accès des 40 findings (~une vingtaine).


### RC3 — La valeur 0 de branch_id est une sentinelle SURCHARGÉE : elle encode à la fois « utilisateur sans branche » (client, invité, borne, défaut DB) ET « super-admin qui voit toutes les branches ». Rien dans le type ni le schéma ne les distingue ; la désambiguïsation repose sur des vérifs secondaires dispersées. Dès qu'un chemin les oublie, un client à branche 0 devient silencieusement admin.

**Symptômes visibles :**
- C07 — BranchScope.php:33 : si branch_id===0 le scope fait `return` (aucun filtre) → un client voit TOUTES les branches
- Même surcharge dupliquée dans DefaultAccessModelTrait.php:21 (branch()===0 → pas de filtre)
- C12 — channels.php:33 : (int)$user->branch_id===0 → `return true` → tout client écoute le canal privé de n'importe quelle branche (fuite temps réel cross-branch)
- create_users_table.php:28 : branch_id `nullable()->default(0)` → TOUT utilisateur vaut 0 par défaut
- CustomerService.php:70 : client créé avec branch_id=0
- GuestSignupController.php:121 : invité créé avec branch_id=0
- channels.php:21 (commentaire) : les users borne ont branch_id=0
- C15 — KioskMachineLoginController.php:82 : token borne émis sur le user admin id=1 (dont branch_id=0)

**Arbre des causes :**

```
SYMPTÔME A « client lit toutes les branches » (C07)
└ BranchScope.php:33 saute le filtre quand branch===0
 └ pourquoi ? 0 = convention « admin voit tout »
  └ pourquoi collision ? le client vaut AUSSI 0 (users default 0, CustomerService:70)
   └ RACINE : un même entier encode 2 sens opposés

SYMPTÔME B « client écoute tous les canaux » (C12)
└ channels.php:33 traite 0 comme admin
 └ pourquoi ? il lit branch_id BRUT, sans le lookup DefaultAccess
  └ pourquoi divergent ? désambiguïsation dispersée (DefaultAccess vs ability vs compare brut)
   └ RACINE : parade applicative dispersée, pas structurelle

SYMPTÔME C « la borne = super-admin » (C15/C27)
└ token borne posé sur user admin id=1
 └ la borne « sans branche » partage le 0 de l'admin
  └ RACINE : même surcharge de sentinelle

SYMPTÔME D « fragilité de la parade »
└ seul un 2e lookup DefaultAccessModelTrait.php:18 empêche client=admin
 └ si la ligne DefaultAccess manque/échoue → retombe sur 0=admin (ligne 21)
  └ RACINE : sécurité dépendante d'un état secondaire optionnel
```

**🎯 Cause racine :** La valeur 0 de branch_id est une sentinelle à double sens contradictoire — « aucune branche » ET « super-admin toutes-branches » — sans contrainte de type/schéma séparant les deux populations. La distinction n'existe que dans des vérifs secondaires disséminées (DefaultAccess, ability token, compare brut) non uniformes ; tout chemin qui les omet promeut un client en admin.

**🔧 Levier unique :** Détruire la surcharge : users.branch_id NOT NULL, sans default(0), FK réelle vers branches (chaque user a une branche concrète) ; « voir toutes les branches » devient une capacité/rôle EXPLICITE ($user->canSeeAllBranches()) vérifiée partout, jamais inférée de branch_id===0. Le 0 cesse d'être une identité ; BranchScope, le Trait et channels.php interrogent la capacité, pas un entier magique.

**💥 Ce qui s'effondre :** Directement : C07 (BranchScope:33) et C12 (channels:33). L'inférence « 0=admin » disparaît des 3 sites (BranchScope:33, Trait:21, channels:33). Défait l'escalade derrière C15/C19/C27, C30 partiel. NON coupés : IDOR C02/C16/C08 et C22/C18 (autres racines). ~4-5 findings B/C.


### RC4 — Aucune atomicité/verrou sur les chemins argent : l'invariant monétaire (un paiement = une transaction, solde non dépensable deux fois) n'existe qu'en couche applicative, sans contrainte structurelle en base pour le rendre impossible à violer.

**Symptômes visibles :**
- PaymentService::payment : first() puis create sans transaction ni verrou -> transaction dupliquee + double PAID sur callbacks concurrents (app/Services/PaymentService.php:15-27)
- Table transactions sans UNIQUE sur order_id : aucun sceau DB contre le double-paiement, dedup = pur ->first() (migration ...143747_create_transactions_table.php:15-24)
- Credit::success : DB::transaction mais User::find SANS lockForUpdate -> lost update, portefeuille depense 2x (Gateways/Credit.php:77-81)
- Credit::payment verifie le solde sans verrou, loin de la deduction -> TOCTOU (Gateways/Credit.php:28)
- LoyaltyService::refundPoints : seul lockForUpdate desactive sous sqlite (=env test), balance_after sur lecture non verrouillee (LoyaltyService.php:42-44,60)
- OrderService flippe payment_status=PAID d'apres Transaction->first() non verrouille (OrderService.php:1377-1384)
- Incoherence : redeem/pay kiosk verrouillent (FrontendOrderService.php:463, OrderController.php:102), passerelle/portefeuille non -> atomicite opt-in

**Arbre des causes :**

```
Symptome: double-paiement, portefeuille dépensé 2x, points négatifs, PAID incohérent.

- Pourquoi ? Écritures argent = read-modify-write (first() puis create/save, balance = balance - total) sans verrou de ligne, isolation par défaut.
  - Pourquoi pas de verrou ? Verrouillage appliqué à la main, chemin par chemin. Certains devs l'ont mis (redeem/pay kiosk), d'autres non (PaymentService, gateway Credit).
    - Pourquoi cette dispersion ? Aucune primitive unique obligatoire de mutation d'argent ; chaque service réimplémente sa logique.
      - RACINE A : atomicité = convention optionnelle, pas un chemin unique imposé.

- Pourquoi rien ne rattrape l'oubli ? Base sans contrainte : transactions.order_id non UNIQUE, aucun CHECK solde >= 0.
  - Pourquoi ? L'invariant « 1 commande = 1 paiement » vit dans du PHP (->first()), pas dans le schéma.
    - RACINE B : le sceau monétaire est une vérif applicative, jamais une contrainte structurelle.

- Pourquoi non détecté ? Seul lock court-circuité sous sqlite (test), QUEUE=sync sérialise tout.
  - RACINE C : l'infra de test masque toute course ; l'oubli de verrou ne casse aucun test.

A+B+C : la correction financière dépend de la mémoire du dev, ni imposée par un chemin unique, ni scellée par la base, ni exerçable par les tests.
```

**🎯 Cause racine :** L'invariant monétaire n'est encodé nulle part de façon contraignante : ni contrainte de schéma (pas d'UNIQUE sur transactions.order_id, pas de CHECK solde), ni chemin de mutation unique atomique. Il survit comme convention « penser à verrouiller », appliquée inégalement et non testable (lock off sous sqlite). La correction argent repose sur la discipline humaine, pas sur une garantie structurelle.

**🔧 Levier unique :** Descendre l'invariant argent en base et router TOUTE mutation via une primitive atomique unique obligatoire. Canonique : UNIQUE sur transactions.order_id (+ clé idempotence) rendant le double-paiement impossible, servi par un seul MoneyMutationService ouvrant toujours transaction + SELECT..FOR UPDATE agnostique du driver. On passe de « penser à verrouiller » à « impossible à violer ».

**💥 Ce qui s'effondre :** Effondre le cluster atomicité/argent, ~8-12 des 40 findings : transaction dupliquee, double PAID, double-dépense portefeuille, TOCTOU solde, overdraw points, balance_after erroné, lock masqué par sqlite (recouvre la 6e racine infra). L'UNIQUE seul neutralise tout double-paiement.


### RC5 — Sceau/contrat = une seule vérification applicative impérative, répétée (ou oubliée) à la main sur chaque site d'écriture, au lieu d'un invariant déclaratif imposé par le substrat (contrainte DB, garde de cycle de vie du modèle, schéma mono-sourcé). L'intégrité est traitée comme un « if » que le développeur doit se souvenir d'écrire à chaque appel, donc sa couverture est mécaniquement partielle : le sceau est posé une fois (destroy) et absent partout ailleurs.

**Symptômes visibles :**
- Sceau Z posé UNIQUEMENT dans destroy() : requête ZReport inline (OrderService.php:1723-1736), 409 sur suppression seulement
- changeStatus() fait save() sans garde de sceau (OrderService.php:1499) → commande scellée reste mutable (C03/C23)
- changePaymentStatus() idem : save() sans garde (OrderService.php:1594) → statut paiement d'une vente au Z signé modifiable
- Order::boot() ne garde QUE restoring(), aucune garde updating/deleting (Order.php:79-107) → forceDelete/mass-update contournent le sceau
- transactions : order_id SANS unique (migration 2023_03_23_143747:18) → double remboursement/ledger (C25, PaymentService:15)
- Contre-preuve : loyalty_transactions a reçu un unique(user_id,order_id,type) après coup (migration 2026_03_26_075919:29) — correctif structurel ponctuel
- Chaîne d'audit : UNIQUE(branch_id,prev_hash) utilisée, mais genèse prev_hash NULL non couverte (AuditLogService.php:167)
- Contrat d'événements : eventContract.schema.json jamais importé ; validateEnvelope n'exige qu'un type string, pas l'enum (eventContract.js:20-42)

**Arbre des causes :**

```
SYMPTÔME: commande scellée reste mutable / argent dupliqué / contrat front≠back
│
├─A Sceau Z ne couvre que destroy (OrderService.php:1723)
│  └─pourquoi? le sceau est un `if` recodé par site d'écriture
│     └─pourquoi? destroy vérifie, changeStatus:1499 et changePaymentStatus:1594 ne vérifient pas
│        └─pourquoi? aucune garde de cycle de vie du modèle (Order.php:79 ne garde que restoring)
│           └─RACINE: l'invariant "scellé ⇒ immuable" n'est pas une propriété de la donnée imposée au point d'écriture unique
│
├─B Double remboursement / double ledger (FrontendOrderService.php:661, PaymentService.php:15)
│  └─pourquoi? check-then-act applicatif sans verrou
│     └─pourquoi? transactions.order_id SANS contrainte unique (migration 2023_03_23:18)
│        └─RACINE: l'unicité est laissée au code, pas au schéma (cf. correctif opt-in loyalty_transactions:29)
│
├─C Fork de genèse de la chaîne d'audit (AuditLogService.php:167)
│  └─pourquoi? UNIQUE(branch_id,prev_hash) ne couvre pas prev_hash NULL
│     └─RACINE: contrainte structurelle présente mais incomplète — à la main, pas exhaustive
│
└─D Contrat d'événements front plus faible que le back (eventContract.js:31)
   └─pourquoi? validateEnvelope n'impose pas l'enum
      └─pourquoi? eventContract.schema.json existe mais n'est jamais importé
         └─RACINE: le contrat est réécrit dans chaque couche au lieu d'être mono-sourcé
─────────────
CONVERGENCE: l'intégrité vit dans des vérifications impératives, site par site, jamais dans le substrat (DB/modèle/schéma), seul goulot par lequel TOUTE écriture passe.
```

**🎯 Cause racine :** Les invariants (scellement fiscal, unicité monétaire, contrat d'événements) sont codés en vérifications applicatives à répéter à chaque site d'écriture, au lieu de contraintes structurelles imposées par le substrat (DB, garde de cycle de vie du modèle, schéma mono-sourcé), seul point par lequel toute écriture passe. La couverture dépend de la mémoire du dev : partielle et contournable.

**🔧 Levier unique :** Matérialiser l'invariant en état persistant (colonne sealed_at) imposé au point d'écriture unique : garde globale Order::updating/deleting rejetant toute mutation d'une ligne scellée (couvre save, mass-update, forceDelete) ; unique(order_id,type) sur transactions ; front généré depuis eventContract.schema.json. Bref : déplacer l'intégrité du call-site vers le substrat.

**💥 Ce qui s'effondre :** ~11-13 findings : C03, C23, C25 ; scellement destroy-only (OrderService:1723), garde modèle absente (Order.php:79), transactions sans unique, ledger PaymentService:15, fork audit (AuditLogService:167), contrat non mono-sourcé (eventContract.js:31). Partiel : ordre UPDATE_ITEM kiosk.


### RC6 — L'infra de test (sqlite:memory + QUEUE=sync + Pusher vide) n'est pas iso-production : elle remplace le moteur de prod par des doublures, si bien que la suite passe verte alors que 7/8 invariants sont violés. Les tests prouvent le mock, pas le système.

**Symptômes visibles :**
- FiscalSequenceService.php:87 : commentaire « SQLite ignore FOR UPDATE ... no-op in tests ». Le verrou censé sérialiser la séquence fiscale ne verrouille rien.
- OrderService.php:463 : allocation du n° de file en SQL MySQL-only (REGEXP, SUBSTRING, CAST AS UNSIGNED), inexécutable sur sqlite. Chemin argent jamais couvert.
- phpunit.xml QUEUE_CONNECTION=sync : jobs (mail/sms/push, FCM, broadcast) inline. Modes d'échec async (job perdu, désordre, retry) invisibles.
- AppServiceProvider.php:56 : la garde prod qui throw si QUEUE=sync n'est jamais franchie (APP_ENV=testing) ; la protection elle-même reste non testée.
- phpunit.xml PUSHER_APP_KEY="" force DispatchDomainEventsJob.php:74 à court-circuiter : la fuite canal private-branch (channels.php:33, bypass branch_id==0) jamais rejouée.
- OrderService.php:1557 : outbox émis après commit dans un try/catch qui avale l'erreur (at-most-once) ; sous sync la perte d'événement ne se manifeste pas.
- tests/Feature/ConcurrentOrderTest.php:12 : nommé « Concurrent » mais RefreshDatabase + connexion sqlite unique = appels séquentiels, zéro concurrence.
- FrontendOrderService.php:661 : cashBack (double remboursement) check-then-act sans transaction ni lock ; TOCTOU inexistante hors concurrence réelle.

**Arbre des causes :**

```
SYMPTÔME: verdict block ~3.5/10, 40 findings critiques, 7/8 invariants violés — MAIS suite de tests verte.
│
├─ Pourquoi verte malgré les violations ?
│   └─ L'infra de test diverge de la prod : les invariants ne sont jamais EXERCÉS dans les conditions qui les cassent.
│
├─ BRANCHE A — Concurrence / chemins argent
│   double-refund (FrontendOrderService:661), séquence fiscale, n° de file
│   └─ Pourquoi non détecté ? lockForUpdate = no-op (FiscalSequenceService:87)
│      └─ Pourquoi ? sqlite:memory = 1 connexion, 0 parallélisme
│         └─ « ConcurrentOrderTest » est séquentiel → fenêtre TOCTOU inexistante en test
│
├─ BRANCHE B — Asynchrone / livraison d'événements
│   outbox at-most-once (OrderService:1557), jobs FCM/mail/push
│   └─ Pourquoi non détecté ? QUEUE=sync (phpunit.xml) → jobs inline, jamais de job perdu/désordonné
│      └─ La garde prod (AppServiceProvider:56) est elle-même court-circuitée en testing
│
├─ BRANCHE C — Dialecte SQL & broadcast
│   REGEXP+SUBSTRING+CAST MySQL-only (OrderService:463) → inexécutable sur sqlite, chemin non couvert
│   PUSHER_APP_KEY="" → DispatchDomainEventsJob:74 court-circuite
│   └─ fuite canal private-branch (channels.php:33) jamais rejouée
│
└─ RACINE COMMUNE
    Le harnais substitue des doublures (sqlite:memory, sync, Pusher vide) au moteur de prod.
    Il PROUVE le mock, pas le système. Révélateur d'invariants débranché → tout finding concurrence/async/dialecte devient indétectable.
```

**🎯 Cause racine :** Absence de parité test↔production : sqlite:memory (mono-connexion, FOR UPDATE ignoré), QUEUE=sync (jobs inline), Pusher vide (broadcast court-circuité) remplacent le moteur réel. Aucun invariant de concurrence, d'async, de verrou ligne, de dialecte SQL ou de canal temps réel n'est exercé : la CI valide un système fictif et masque les 5 autres causes racines.

**🔧 Levier unique :** Instaurer un tier CI « prod-parité » obligatoire au merge : MySQL réel (verrous ligne + dialecte identique), file async réelle (redis/database, worker séparé) et broadcaster réel (soketi), avec des tests lançant des requêtes vraiment parallèles sur le même agrégat. Ce seul basculement rebranche le révélateur : les invariants argent/async/canal cassent enfin la CI au lieu de passer verts.

**💥 Ce qui s'effondre :** La 6e cause (infra masquante) s'effondre ; ~15-20 findings verts cassent enfin la CI : double-remboursement (FrontendOrderService:661), séquence fiscale (:87), n° file (OrderService:463), outbox (:1557), fuite canal (channels.php:33). Il ne répare pas ces bugs, il cesse de les cacher.


---


*Décomposition par orchestration multi-agents + vérification adversariale. 112 cellules d'intersection cartographiées, 27 confirmées. La valeur : transformer « 40 critiques + 112 intersections » en ~6 leviers structurels.*