# REGISTRE FINAL — Intelligence totale, problèmes RÉELS par système (2026-07-18)

HEAD `57df489ce`. Méthode : 7 systèmes × chasse hostile (verify-before-report, preuve file:line/DB) → réfutation adversaire des P1 (3 confirmés, 2 downgradés) → registre. Chaîne HMAC NF525 **verte ×4 branches** (aucune rupture de signature) ; les problèmes sont dans la **logique**, la **data** et les **coutures**, pas dans le crypto.

**Fil rouge** : deux zones faibles réelles émergent — (A) la **numérotation fiscale** dépend d'une table (`orders`) que rien ne protège du DELETE au niveau DB, et (B) les **commandes web/online** (lot web-sync du 15/07) ont des trous d'encaissement/authz. Le reste du money-path (prix SSOT, idempotence, IDOR, refund, split, machine à états KDS) est vérifié SAIN, souvent deux fois.

---

## P1 — CONFIRMÉS (5)

**P1-1 [NF525] Réutilisation de numéros fiscaux après hard-delete** — `app/Services/Fiscal/FiscalSequenceService.php:97-103`.
`next()` = `MAX(fiscal_sequence_no)+1` sur `orders`, table SUPPRIMABLE ; les 9 triggers d'immutabilité (`SHOW TRIGGERS`) couvrent audit_logs/z_reports/order_payments/… mais **PAS `orders`**. Preuve (chaîne d'audit signée) : `seq 2579` revendiqué par **6 orders distincts**, `2068` par 5, `2624` par 2 (montants divergents, gravés à vie). Déclencheur prod = le gate « purge 186 cmd test ». **Gate owner NF525** : trigger `BEFORE DELETE ON orders WHEN fiscal_sequence_no IS NOT NULL` + compteur monotone dédié (table séquence) au lieu de MAX+1.

**P1-2 [NF525] Trou de numérotation réel + détecteur jamais planifié** — `app/Console/Kernel.php` (absence).
`fiscal:verify-sequence-continuity` → « Branche 1 : manquants 2506-2508 » (~19/06, hard-deletes). Le détecteur existe, read-only, sûr — mais `grep continuity Kernel.php` = **rien** : seuls `verify-chain` (03:30) et `verify-z-membership` sont planifiés. En prod un trou resterait invisible. **Heal SAFE** : ajouter la commande au scheduler + alerte. (La cause racine des trous = P1-1.)

**P1-3 [NF525-adjacent] Commande web « Acceptée » → PENDING_COUNTER sans chemin d'encaissement** — `app/Http/Controllers/Admin/OnlineOrderController.php:146` + `app/Services/OrderService.php:2646,1916`.
Le heal SYNC-WEB-KDS-01 (15/07) bascule toute commande web UNPAID en PENDING_COUNTER à l'« Accepter ». Or : file `/pos/counter-collect/pending` filtre source ∈ (kiosk,pos,phone) → **'web' jamais listé** ; `PENDING_COUNTER→PAID` = `abort(422)` ; bouton « Encaisser & Valider » en `v-if status===PENDING` → disparaît. Pire, le sceau doorstep COD est gardé `$wasUnpaidCash = payment_status===UNPAID` → le flip PENDING_COUNTER le CASSE → **livraison livrée, encaissée physiquement, jamais fiscalisée = off-book**. Réfutation : mécanisme béton (le système échoue safe aujourd'hui = bloque PAID) mais **preuve-terrain faible** (les 2 rows DB sont des REJECTED, pas des ventes off-book). **Heal SAFE** (non-frozen) : router l'accept web vers un état encaissable OU ajouter 'web' à la file counter-collect + rétablir le sceau COD.

**P1-4 [Revenu/UX] Upsell borne « Menu Enfant Nuggets » → paiement bloqué 422** — `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue:220-257` + `app/Http/Controllers/Frontend/ItemController.php:81-107`.
L'item 40 (`is_upsell=5`, servi 5/5 tirages `/kiosk-upsell`) porte l'attribut sauce `min_select=1` (12 variations, profil composer publié hier par le goal du 17). `addAndContinue` l'ajoute variations VIDES puis route au paiement → OrderRequest REJECT 422 « Sélectionnez au moins 1 Sauce » (repro validée). Bouton Payer mort, dead-end client seul. **Régression indirecte de mon goal d'hier** (avant, item 40 n'avait pas d'attribut requis actif). **Heal SAFE** (non-frozen) : l'upsell d'un item à profil composer/attribut requis doit ouvrir le wizard, pas router direct au paiement — OU exclure ces items du pool upsell.

**P1-5 [Sécu/RBAC] `/admin/sales-report/overview` (CA net) non protégé** — `app/Http/Controllers/Admin/SalesReportController.php:40`.
`->only('index','export','pdf','overview')` filtre par NOM de méthode ; la vraie méthode = `salesReportOverview` → `'overview' ≠ 'salesReportOverview'` → `permission:sales-report` **jamais appliqué** (confirmé `route:list --json` : overview = seul des 4 sans le middleware). Tout staff auth (POS Operator, Chef, Livreur) lit `total_earnings`/`total_discounts` par période. **Aggravant** : `tests/Feature/Admin/MgmtReadAuthzGateSentinelTest.php` est vert-faux (teste la string, pas le mapping). **Heal SAFE** : corriger le nom de méthode + réparer la sentinelle.

---

## P2 — CONFIRMÉS (sélection, ~18)

### Fiscal / data
- **P2-a** 22 orders PAID sans `fiscal_sequence_no`, tous `fiscal_alloc_error_at` NULL → invisibles du cron retry à vie (`RetryFiscalAllocCommand:65`) ; pas de filet permanent « vente payée hors chaîne ». (Rows = tests, mais trou d'observabilité réel.)
- **P2-b** 2442 commandes numérotées hors de tout Z signé (fenêtre morte pré-C33) — 0 fuite post-C33, mais aucun outil de régularisation, détecteur alertera à vie.
- **P2-c** `ZReportService.php:499-515` ajustement post-Z clé sur `updated_at` MUTABLE → un `save()` tardif (ex. orders 946/947 bumpés 17j après) peut re-soustraire le montant dans un Z suivant. 0 Z corrompu à ce jour, scénario ouvert post-C33. **Frozen (ZReportService) → gate.**
- **P2-d** `transactions.order_id` + `cash_movements.order_id` SANS FK → 11 + 8 orphelins permanents (traces des hard-deletes P1-1) → ledger tiroir non réconciliable.

### Commandes web/online (couture web-sync 15/07)
- **P2-e** `OnlineOrderController::changePaymentStatus` →REFUNDED **sans gate `pos-refund`** (la sœur POS l'a, `PosOrderController:372`). POS Operator (a `online-orders`+`pos`, pas `pos-refund`) peut voider off-book. Même famille RBAC que P1-5. **Heal SAFE.**
- **P2-f** `PosOrderRequest.php:35-51` `delivery_charge` client non recalculé serveur si `delivery_distance_km` absent → fee forgé facturé sous le seuil livraison-offerte. Contredit « prix 100 % backend ». **Heal SAFE.**

### Borne
- **P2-g** (ex-P1-3, downgradé) Bols : step boisson addon 2,00 € routé comme formule Menu → `addonId` null → boisson non facturée (bol 7,90 seul) mais imprimée ticket cuisine. Opt-in, ~2€, pas de rupture NF525. **Heal SAFE** (non-frozen : mapping addon_role drink).
- **P2-h** 1ère sauce « gratuite » client vs serveur facture TOUTE variation (`KioskWizardComponent` frozen vs `PricingService:134`) → display < sealed dès qu'une variation sauce est payante. Latent (sauces menu à 0 €). **Sentinelle à créer** (variations wizard à 0 €).
- **P2-i** Rejeu offline 409 à vie (`kioskOfflineQueue.js:370` + `IdempotencyKeyMiddleware:76`) : POST abouti + réponse perdue → rejeu régénère quote → hash ≠ → 409×10 → « abandonné » alors que la commande vit en cuisine. **Heal SAFE.**
- **P2-j** Pollution DB : items/cats de test Faker (`RJ-Dcat*`, « Aliquam »…) ACTIFS, éligibles borne/upsell. **Purge data — vérifier prod/staging (gate owner data).**

### KDS
- **P2-k** `KdsV2Grid.vue:303` touches [D]–[H] bumpent des commandes INVISIBLES (grille rend `slice(0,3)` mais `onKey` indexe `activeOrders` complet) → transition serveur + notif client sur une commande non vue. **Heal SAFE.**
- **P2-l** `kitchenDisplaySystemOrder.js:42` refresh post-bump réutilise le payload comme filtre → board faux ~300 ms + carillon « nouvelle commande » fantôme à chaque bump. **Heal SAFE.**
- **P2-m** `kitchenLocalPrinter.js:100` dé-dup impression PAS cross-onglet → 2 onglets /kds = 2 tickets physiques. **Heal SAFE** (BroadcastChannel/storage listener).
- **P2-n** Board KDS matche 22 commandes zombies payées de juin ; 412 « actives » >24h, aucun janitor (advance sans plancher d'âge, `KitchenDisplaySystemOrderService:152`). Masqué par chance en layout V2. **Heal SAFE** (plancher advance-overdue) + purge.
- **P2-o** `is_advance_order` hors-enum {0,1,2} = commande invisible de toutes les surfaces cuisine+mur à vie (24 rows) ; `OrderRequest:88`/`PosOrderRequest:24`/`TableOrderRequest:41` ne normalisent pas → `in:5,10` manquant. Defense-in-depth. **Heal SAFE.**
- **P2-p** Fenêtre morte du seed auto-print : commande créée pendant KDS fermé/reload → seedée « imprimée » sans impression, sans badge, ticket perdu en silence (`composant:1989`). **Heal SAFE** (seeder >10 min → liste d'échec).

### Admin / structure
- **P2-q** Collision de raison `'out_of_stock'` : `AvailabilityService` (quota jour) vs `StockService` (rupture physique, `:205-220`) → la resync stock annule le plafond journalier dès `on_hand>0`. Latent (0 item ne combine max_daily_qty + stock aujourd'hui). **Heal SAFE** (raison dédiée `daily_quota`).
- **P2-r** `FrontendOrder` et `Order` = 2 modèles sur la table `orders`, hooks divergents : le flux borne/web (via FrontendOrder) n'a NI le hook horodatage cuisine (`accepted_at`…) NI le guard NF525 `restoring` → ACCEPT sans `accepted_at` (prep-time faussé sur le flux dominant), `restore()` non bloqué. **Gate (architectural, NF525-adjacent).**
- **P2-s** `config/kiosk.php:288` `queue_start_number` absent de la branche `$requireForm=true` → A0001 au lieu de A0032 si `KIOSK_REQUIRE_MACHINE_LOGIN=true` (récurrence classe RED-08). **Heal SAFE.**
- **P2-t** `master.blade.php:238` `env('KIOSK_USE_POS_WIZARD')` + `AppServiceProvider:362` `env('STRIPE_WEBHOOK_SECRET')` crus dans du code exécuté sous `config:cache` (tous les deploy) → env() null → flag/guard neutralisés silencieusement. **Heal SAFE** (migrer vers config()).

### Web
- **P2-u** `/loyalty/scan` (`LoyaltyController:648`) gardé seulement `tokenCan('kiosk:order')` — porté aussi par tokens invités/clients → énumération PII (prénom + solde fidélité + existence) par tout client. Ses jumeaux `check`/`redeem` ont reçu le durcissement KioskMachine ; `scan` a été oublié. **Heal SAFE.**
- **P2-v** `/loyalty/register` (`LoyaltyController:166`) crée un compte `is_guest=NO(10)` sans rôle → verrouille à vie le login web de ce téléphone (guest-signup refuse `!=YES`) ; **7 comptes déjà dans cet état**. **Heal SAFE.**
- **P2-w** (gated SMS) prise de contrôle compte invité par téléphone seul quand `phone_verification=DISABLE` (SMS non câblé) — se ferme à l'activation SMS prod. **Gate = lot activation SMS.**

---

## P3 (sélection, ~25 au total dans les rapports hunt/)
Fiscal : Z filtre négatif `!=UNPAID` (F7), 8 UNPAID+CANCELED détiennent des seqs (F8), X sans tie-break (F9), 65 orders `business_date` NULL (F10), index `orders(branch_id,created_at)` manquant (F11). Structure : EventContract BROADCAST_MAP PHP↔JS divergé (payload tronqué silencieux), `ItemAttributeService` update n'invalide rien, `route:cache` prescrit mais impossible (6 Closures), GET mutation `message/change-status`, configs poids morts (`rush_windows`, `stale_web_collect_ttl`…), `KDS_VISIBLE=[4,7,8]` magique. KDS : recall `queue_number:0` typé menteur, ledger 1773 PENDING→PREPARING « illégales », `since` UTC non converti, `bumped_items_v1` localStorage sans borne, TTL recall sur `updated_at`. Web : `source` piloté client (analytics), redeem `source_surface` asymétrie self-healing, coupon `limit_per_user` non-atomique, `check()` write-on-read → 500 rare. Borne : `ADD_ITEM` merge ignore `item_addons`, kiosk_promo demi-câblé (`uses_count` jamais incrémenté), IDs morts post-reset (cat 315, frites_included), boisson formule non modélisée serveur, fallback fetchMenu sans dispo branche. Admin : `AnalyticSectionController` index/show non gated (tables vides), `DashboardService::totalMenuItems` compte inactifs.

---

## Synthèse quoi-healer / quoi-gater

**HEAL SAFE (non-frozen, sur « go » owner) — ~12 nets** : P1-5 + sentinelle (RBAC CA), P1-4 (upsell→wizard), P1-3 (encaissement web), P1-2 (scheduler continuity), P2-e (gate refund web), P2-f (delivery_charge), P2-g (boisson bol), P2-k/l/m/n/o/p (KDS), P2-q (raison quota), P2-s (queue_start_number), P2-t (env→config), P2-u/v (loyalty scan/register), P2-i (rejeu offline).

**GATE OWNER (frozen / NF525 / migration / data prod / décision)** : P1-1 (trigger BEFORE DELETE orders + compteur monotone = NF525), P2-c (ZReportService frozen), P2-d (FK migration sur tables fiscales), P2-r (FrontendOrder/Order architectural), P2-j/P2-n-purge (data test/zombies prod), P2-w (SMS prod), TVA livraison Z (frozen), boissons 10 % vs 5,5 % (décision fiscale owner).

**Convergence** : chaque P1 a survécu à un réfuteur hostile dédié (ou été downgradé avec raison) ; chaque finding porte file:line + repro DB. Aucun heal appliqué (mission = registre ; heal sur go owner, plusieurs touchent frozen/NF525/prod).
