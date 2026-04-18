# AUDIT POS — Section 1 / 4 : Parcours commande (wizard → panier → paiement → split → discount → receipt)

**Date.** 2026-04-18
**Agent.** Sous-agent 1 (READ-ONLY) — Track B POS-A
**Scope.** Parcours caissier de bout en bout : wizard item → panier → discount → paiement → reçu. Pas de centralisation multi-surfaces (sous-agent 2), pas de dashboard/Z (sous-agent 3), pas de backend events/jobs (sous-agent 4).
**Mode.** Lecture seule exclusive, 0 modification de code, 0 commit.
**Matériel produit.** Ce rapport uniquement.

---

## 1. Synthèse verdict

**VERDICT GLOBAL : WARN → BLOCKED**, avec **2 findings P0 bloquants** (modèle paiement binaire empêchant toute multi-tender ; absence totale d'`OrderStateMachine::apply` et d'`afterCommit`) et **5 findings P1**. Le parcours « caisse simple 1 client = 1 paiement unique » est fonctionnel et a plusieurs garde-fous non triviaux (idempotency key client+serveur, queue_number avec `Cache::lock`, SSOT pricing via `PricingService` derrière flag `config('pricing.use_ssot_service', true)`, IDOR address owner check, cross-item injection guards variations/extras). Mais **la surface POS réelle attendue par un SaaS resto** (split bill, amend, refund inline, multi-tender, ouverture tiroir, re-print, logs d'impression, ticket TVA ventilée par taux, K/Z fiscal) est **soit absente soit stubée**. Le POS est à ~40 % de la profondeur auditée côté Kiosk.

### 3 forces
1. **Re-calcul serveur du prix.** `posOrderStore` (`app/Services/OrderService.php:546-929`) ignore `total/subtotal/discount` du payload (ligne 566 `unset($validated['total'], $validated['subtotal'], $validated['discount']);`) et passe par `PricingService::calculateOrder` (ligne 602) ou par une boucle DB bulk-loadée avec rejet strict d'item / variation / extra inconnu (lignes 652-710). SSOT pricing respecté sur ce chemin nominal.
2. **Idempotency key client + serveur + DB unique.** Génération client (`PosComponent.vue:1282`), propagation header `X-Idempotency-Key` (`posOrder.js:73-83`), check pré-création (`OrderService.php:550-556`), piège duplicate-key MySQL 23000 en filet (`OrderService.php:916-922`). Rare à ce niveau de maturité dans le codebase.
3. **Queue number atomique par branche+jour.** `Cache::lock('queue_lock_...')` + `MAX(CAST(SUBSTRING))` en une requête (`OrderService.php:773-799`). Fallback microtime en cas de `LockTimeoutException`. Bonne défense sur ressource partagée.

### 3 faiblesses structurelles
1. **Modèle paiement binaire PAID(5)/UNPAID(10) uniquement** (`app/Enums/PaymentStatus.php:1-10`). Pas de `PARTIALLY_PAID`, pas de table `order_payments`, pas de colonne `paid_amount`. ⇒ **split payment (cash + carte, cash + ticket resto, acompte + solde) structurellement impossible**. Invariant §1.2 POS violé.
2. **État `status` et `payment_status` écrits en direct** partout (`OrderService.php:588`, `1439-1440`, `1505-1506`). Aucun appel à `OrderStateMachine::apply()` dans `app/` (grep vide). Seule `OrderStateMachine::recordTransition()` est utilisée, et *après* le save — donc elle ne *contrôle* rien, elle journalise. Invariant §1.1 violé.
3. **Événements dispatchés hors `DB::afterCommit()`.** `OrderCreated::dispatch` (`OrderService.php:904`) est appelé après `DB::transaction` mais **pas via `DB::afterCommit()`**. Idem `OrderStatusChanged::dispatch` (`OrderService.php:1401, 1470`). Si un listener sync lit la DB dans une connection répliquée / pool read-only, il peut manquer la ligne. Invariant §1.1 violé. Sur `changePaymentStatus` (`OrderService.php:1485-1526`), **aucun event** n'est émis du tout.

---

## 2. Cartographie parcours commande POS

### 2.1 Structure UI (`resources/js/components/admin/pos/`, 5 fichiers)

| Composant | Rôle | Lignes |
|---|---|---|
| `PosComponent.vue` | Shell POS : recherche, catégories, panier, discount, order_type (dine-in caché, takeaway par défaut, delivery inline), kiosk-cash FAB | 1863 |
| `ItemComponent.vue` | Grille items + modale "wizard" (variations, extras, addons, instruction) — support edit-from-cart | 1068 |
| `PaymentComponent.vue` | Modale paiement cash/carte + numpad + confirm & print | 280 |
| `ReceiptComponent.vue` | Ticket imprimé via `vue3-print-nb` (dialogue navigateur, pas ESC/POS) | 243 |
| `CreateCustomerAddressComponent.vue` | Modal adresse delivery (MapComponent + labels) | 197 |

### 2.2 Séquence caissier nominal (cash, takeaway)

```
UI: PosComponent.mounted → $refs.takeAway.click() (forcé TAKEAWAY)
 │   loadKioskCashOrders() + Echo subscribe (private-branch.{id}) + polling fallback
 │
 1. Recherche / clic catégorie → itemList() → items store
 2. Clic item → ItemComponent.variationModalShow(item)
    ├─ dispatch('item/details', { id, surface: 'pos' })   (NormalItemResource)
    ├─ Pré-sélection 1ère variation par attribut (itemAttributes)
    ├─ wizard.js DOM parallèle peut écrire data-wizard-total / data-wizard-pos-line-addons
    └─ Clic "add_to_cart" OU wizard:add-to-cart event → addToCart()
 3. addToCart → buildPosCartMainPayload → posCart/lists
    ├─ Fusion si même item_id + variations + extras + instruction + pos_line_addons
    ├─ Persistance localStorage `pos_cart_v2` (TTL 2h)
    └─ subtotal recalculé via computePosCartLineDisplayTotal
 4. Discount (PosComponent.applyDiscount)
    ├─ % ou fixe → stocke discount dans store (client-side)
    └─ AUCUN appel serveur de preview SSOT
 5. Order submit (PosComponent.orderSubmit)
    ├─ Génération token séquentiel localStorage `pos_order_seq_YYYY-MM-DD`
    ├─ Génération idempotency_key `${Date.now()}_${rand}_${branch_id}`
    ├─ JSON.stringify items (+ pos_line_addons dupliqués comme items séparés)
    └─ Ouverture modale #orderpayment (pas encore d'appel API)
 6. PaymentComponent.confirmOrder
    ├─ Lecture DOM directe #cashInput / #cardInput (contourne binding Vue)
    ├─ defaultAccess/show → re-patch branch_id depuis serveur
    └─ posOrder/save POST admin/pos (X-Idempotency-Key, timeout 30s AbortController)
 7. Backend: PosController.store → OrderService::posOrderStore
    ├─ Check idempotency_key (pré-création + filet duplicate-key)
    ├─ DB::transaction:
    │   ├─ Order::create(… status=ACCEPT, payment_status=PAID, total/subtotal/discount=0 …)
    │   ├─ PricingService::calculateOrder (SSOT) OU boucle DB (fallback)
    │   ├─ OrderItem::insert($itemsArray)
    │   ├─ queue_number via Cache::lock + MAX(queue_number REGEXP '^A[0-9]+$')
    │   ├─ $order->save() (total recalculé, loyalty_customer_code, source_surface='pos')
    │   ├─ OrderCoupon::create si coupon
    │   ├─ OrderAddress::create si delivery (avec IDOR check user_id)
    │   └─ ActionLog::create (texte libre, pas structuré)
    ├─ HORS transaction (mais PAS afterCommit):
    │   ├─ SendOrderGotMail/Sms/Push::dispatch
    │   └─ OrderCreated::dispatch  ← peut lire Order avant réplication
    └─ return $this->order
 8. PosOrder/show → ReceiptComponent (modal) → vue3-print-nb window.print()
 9. posCart/resetCart → localStorage.removeItem
```

### 2.3 Séquence paiement carte (TPE)

Identique à 2.2 sauf :
- `pos_payment_method=CARD`, `pos_payment_note` = 4 derniers chiffres (chiffrés nulle part, loggés tels quels en DB via `$validated`).
- **Aucune intégration TPE**. Pas d'appel `window.borne.cardPay()`, pas de retry, pas de timeout dédié TPE. Grep `TPE|cardTerminal|payment_provider_ref` sur `app/` retourne 0 résultat métier. Le « paiement carte POS » est donc une **déclaration caissier** — il appuie sur le TPE physique hors système, puis clique confirm.

### 2.4 Séquence split / tab / amend / refund

**Inexistante.** Grep `split_bill|splitBill|tab_table|order_payments|PARTIALLY_PAID|partial_payment` retourne uniquement des refs dans les briefs d'audit (`tasks/audits/AUDIT_POS_PAYMENT_CASH_CARD_002.md`). Aucune route (`grep -n "Route::.*pos"` de `routes/api.php` confirme : seules `store`, `change-status`, `change-payment-status`, `select-delivery-boy`, `reorder-items`). Aucune méthode `OrderService::splitOrder`, `OrderService::refund`, `OrderService::amend`.

---

## 3. Audit wizard item POS

### 3.1 Parité avec kiosk
- **Structure items payload** : composée dans `ItemComponent.buildPosCheckoutOrderRow` (`:1176-1224`). Champs `item_id`, `item_variations: [{id, item_id, item_attribute_id, variation_name, name}, …]`, `item_extras: [{id, item_id, name}, …]`, `instruction`, `quantity`, `item_price`, `total_price`, `item_variation_total`, `item_extra_total`. C'est **l'héritage Vue 2 historique**, le kiosk V2 a migré vers une structure `wizard_selections` plus stricte (voir `KioskPosWizardComponent.vue`). **Divergence majeure non tracée dans un contract commun.**
- **Addons** : POS les fusionne dans `pos_line_addons` (nested sous la ligne principale) ou les transforme en items séparés via `buildPosCheckoutOrderRow` dans `orderSubmit` (`PosComponent.vue:1239-1248`). Le backend reçoit donc **N lignes indépendantes** alors que la ligne de panier POS en présente N+1 groupée. Le lien parent/enfant est perdu côté DB : impossible de savoir "cet OrderItem frites fait partie du menu du sandwich OrderItem #42".

### 3.2 Validations serveur
- `PosOrderRequest` (`app/Http/Requests/PosOrderRequest.php:29-64`) valide `items:required|json|ValidJsonOrder`. `ValidJsonOrder` (`app/Rules/ValidJsonOrder.php`) vérifie **uniquement** `item_id > 0`, `quantity > 0`, `instruction ≤ 500`. **Aucune validation min/max sélection d'attributs, aucune obligation de variation requise, aucune vérification que l'addon appartient au parent**. Le backend compense partiellement via cross-item injection guards (`OrderService.php:676-681, 702-707`), mais n'empêche pas un sandwich d'être commandé **sans pain**.

### 3.3 Prix SSOT
- **Prix payload ignoré** : oui (`OrderService.php:566`) + boucle DB stricte.
- **Preview panier** : calculé 100 % client dans `posCart.js` via `computePosCartLineDisplayTotal` + `mainOrderLineTotal` + `rowUnitBundled`. **Aucun appel `/api/pricing/preview`**. L'écart visible caissier vs total imprimé peut exister sur tax-inclusive vs exclusive (cf. label "HT" ajouté ligne 428 de `PosComponent.vue`).
- **Discount côté client** (`PosComponent.vue:1139-1158`) : applique % ou fixe à un champ stocké en Vuex, non re-checked par preview. Passe ensuite en payload `discount`. **Le backend le *revalide* dans la branche non-SSOT** (`OrderService.php:763-770`) mais **l'accepte tel quel si ≤ subtotal**. Sur la branche SSOT (`use_ssot_service=true`), `PricingService` reçoit `(float) $request->discount` (ligne 609) — impossible de vérifier si `PricingService` le clippe, mais la signature l'accepte en entrée.

### 3.4 Re-edit d'item déjà au panier
- **Implémenté** : bouton crayon sur chaque ligne (`PosComponent.vue:276-280`) → `editCartLine(index)` → `ItemComponent.openEditFromCart(line, index)` (`:384-474`). Pré-remplissage via `buildWizardRestorePayload` (`:721-915`). Résultat réinjecté via `posCart/replaceCartLine`.
- **Points de fragilité** : la reconstruction wizard repose sur des heuristiques sur le *nom* d'attribut (`attrLower.includes('pain')`, `includes('viande')`, `includes('sauce')`, `includes('accompagnement')|includes('riz')|includes('salade')`) — si un admin crée un attribut "Garniture" qui joue le rôle viande, **il sera perdu au re-edit**. Même chose pour extras (`isFree || tomate || oignon || salade || cornichon`). C'est un couplage dur à des noms FR, fragile à l'i18n et à la configuration admin.

---

## 4. Audit panier POS

### 4.1 Structure store (`resources/js/store/modules/posCart.js`, 305 lignes)

- **State** : `lists[]`, `subtotal`, `discount`, `restoredFromStorage`.
- **Persistance** : localStorage `pos_cart_v2`, TTL 2 h (ligne 7). Restauré au démarrage avec une bannière d'alerte (`PosComponent.vue:956-964`).
- **Fusion** : mutation `lists` (`:164-246`) fusionne si même `item_id` + mêmes variations (set keys+values) + mêmes extras (set membres) + même `instruction` + même `posLineAddonsSignature`. Correct mais **fusion côté client uniquement** — le serveur reçoit toujours le payload éclaté.

### 4.2 Reprise shift
- Le localStorage n'est **pas** scopé par `branch_id` ni par `user_id` caissier. Si un manager se connecte sur la même caisse après un caissier, il hérite du panier pending. Donnée métier potentielle sensible (client, items, instructions).
- Pas de **draft order côté serveur**. Si la caisse plante entre `orderSubmit` et `posOrder/save`, le panier survit (bonne UX), mais aucune reprise distante possible si le caissier migre sur une autre machine.

### 4.3 Split bill / tab table / discount staff

- **Split bill** : absent (cf. §2.4). Impossibilité de facturer 3 clients sur une même table à partir d'un même panier.
- **Tab par table** : dine-in et table selector sont **masqués** (`PosComponent.vue:121 v-if="false"` + `:235 v-if="false"`). La fonctionnalité est littéralement cachée. Pas de "parking ticket" ni d'ardoise table.
- **Discount staff** : la remise est un champ libre **%** (max 100) ou **fixe** (max = subtotal). **Aucun check de rôle Spatie** côté client (`applyDiscount`). Côté serveur : `posOrderStore` accepte toute valeur ≤ subtotal sans vérifier `$user->can('apply-discount')` ni logger le caissier vs seuil (il y a un `ActionLog` mais pas structuré). **Un caissier peut appliquer 100 % de remise sans autorisation**. Invariant §1.2 POS violé.

---

## 5. Audit paiement POS

### 5.1 Cash
- **Rendu monnaie** calculé **100 % client** (`PaymentComponent.vue:137-141`) sur `props.form.total` (client). Le backend n'en conserve qu'un aperçu (`pos_received_amount` validé côté serveur en double sur le vrai total recalculé, `OrderService.php:818-825`). Le champ `cash_back_amount` apparaît dans le reçu (`ReceiptComponent.vue:145`) mais sa source n'est pas recalculée serveur — **risque d'écart si le total client ≠ total serveur** (ex : TVA ajoutée serveur mais non anticipée client).
- **Ouverture tiroir** : **aucune** (grep `openDrawer` dans `resources/js/components/admin/pos/` : 0 hits). `kioskHardware.openDrawer()` est câblé pour le kiosk uniquement. **Le POS ne pilote pas de tiroir-caisse électronique**.
- **Multi-tender** : impossible (cf. §2.4, binary enum).

### 5.2 Carte (TPE)
- **Pas de bridge TPE**. `pos_payment_note` = 4 chiffres saisis manuellement par le caissier. Pas de référence TPE (`tpe_ref`, `auth_code`, `rrn`). Pas de retry, pas d'annulation TPE, pas d'ACK serveur, pas de retour OK/KO.
- **Timeout 30s côté Axios** (`posOrder.js:77`) : si le serveur traîne, un flag `_paymentTimeout` est levé avec message "Délai de paiement dépassé. Ne relancez pas — vérifiez le statut." — **mais il n'existe aucun endpoint de "check statut par idempotency_key"** pour aider le caissier.

### 5.3 Ticket restaurant, acompte
- **Ticket restaurant** absent de `PosPaymentMethod` enum (CASH=1, CARD=2, MOBILE_BANKING=3, OTHER=4 — pas de `MEAL_VOUCHER`).
- **Acompte** : concept absent. Pas de colonne `deposit_amount`, pas de state `PARTIALLY_PAID`.

### 5.4 Refund inline
- **Absent**. `changePaymentStatus` (`OrderService.php:1485-1526`) permet *juste* de basculer `payment_status` UNPAID↔PAID sans re-contrôle, sans cashback, sans stock release, sans event, sans permission dédiée (elle relève du permission `pos-orders` du controller). Le `PaymentService::cashBack` existe mais n'est appelé **que** depuis `changeStatus` quand on passe en REJECTED/CANCELED (`OrderService.php:1378-1384, 1428-1434`) — pas depuis un flow refund assumé.

---

## 6. Audit reçu / ticket

### 6.1 Template
- **Seul template ticket** : `ReceiptComponent.vue` (client, HTML + `vue3-print-nb`). Aucun fichier `resources/views/*receipt*` ou `*ticket*` (grep vide). **Pas de fallback ESC/POS serveur**.
- **Champs imprimés** : company, branch address/phone, `order_serial_no`, date/time, items (qty, nom, variations, extras, instruction, *ligne TVA par item si `tax_rate > 0`*), subtotal, total_tax *agrégé*, discount, delivery_charge si delivery, total, order_type, payment_type, cash & change si > 0, token.
- **TVA** : **pas ventilée par taux** (5.5 % / 10 % / 20 %). Une seule ligne "Total TVA" (`ReceiptComponent.vue:106-112`). **Non conforme facture fiscale française** (art. 242 nonies A CGI).
- **queue_number** : **non imprimé** (seul `token` l'est). Le token est fabriqué client-side depuis localStorage. L'OSS (Order Status Screen) affichera le `queue_number` côté client alors que le ticket papier remettra le `token`. **Incohérence d'identifiant entre ticket client et écran OSS**.

### 6.2 Impression
- `v-print="printObj"` ouvre le dialogue navigateur (`window.print`). **Aucune détection imprimante HS, aucun log d'impression, aucun re-print depuis l'historique**. Si le caissier ferme la modale sans imprimer, le ticket est perdu.
- **Kitchen notes** : grep `kitchen_notes|special_instructions` sur `app/Models/Order*.php` → 0 hit. Seul `OrderItem.instruction` existe (string). Pas de séparation "instructions client sensibles cuisine" vs "préférences non bloquantes". Le KDS reçoit `instruction` via résource, sans sémantique.

### 6.3 Fallback
- **Aucun**. Pas de queue `PrintJob`, pas de retry, pas de replay post-crash.

---

## 7. Findings priorisés (16 findings)

### POS-P1-F-01 — Modèle paiement binaire PAID/UNPAID : multi-tender structurellement impossible — P0
- file:line : `app/Enums/PaymentStatus.php:1-10`, `app/Services/OrderService.php:1485-1526`
- description : L'enum `PaymentStatus` ne contient que `PAID=5` et `UNPAID=10`. Pas de `PARTIALLY_PAID`. Aucune table `order_payments`, aucune colonne `paid_amount`. `changePaymentStatus` écrit en direct sans tracer montant, méthode ou séquence. Il est impossible de supporter cash+carte, acompte+solde, ticket resto+appoint — cas quotidiens d'un resto.
- impact : Fonctionnalité cœur métier SaaS resto absente. Blocage commercial vs concurrents (Tiller, Lightspeed, Square). Toute commande réglée partiellement force le caissier à bidouiller l'enum ou refuser.
- fix_proposal : Introduire `order_payments` (one-to-many), enum étendu, recalcul `payment_status` dérivé de Σ `payments.amount` vs `order.total`.
- invariants touchés : SSOT pricing, state machine paiement, audit log, EventContract (event `PaymentRecorded` manquant).
- resurface_from : `tasks/audits/AUDIT_POS_PAYMENT_CASH_CARD_002.md` Q2, `tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md §1.2`.

### POS-P1-F-02 — Écritures directes sur `status` et `payment_status`, aucun `OrderStateMachine::apply` — P0
- file:line : `app/Services/OrderService.php:588` (`'status' => OrderStatus::ACCEPT` au create), `:1387-1388`, `:1439-1440`, `:1491, 1505-1506`
- description : Toutes les transitions de statut et de paiement sont des `$order->save()` après assignation directe. `OrderStateMachine::recordTransition` est appelée *après* le save pour journaliser, donc elle ne bloque rien. Grep `OrderStateMachine::apply` sur `app/` retourne 0 résultat. `ValidStatusTransition` est utilisée dans `changeStatus` mais pas dans `changePaymentStatus`.
- impact : Règles métier (UNPAID→PAID après CANCEL interdit ; PAID→UNPAID doit journaliser motif + permission ; aucune transition depuis statut terminal RETURNED) non contraintes. Risque comptable direct.
- fix_proposal : Centraliser toutes les transitions via `OrderStateMachine::apply($order, $newStatus, $context)` qui (1) valide, (2) save, (3) recordTransition, (4) broadcast event — atomiquement.
- invariants touchés : state machine, audit log, EventContract.
- resurface_from : `POS_INVARIANTS_AND_GATES §1.1`.

### POS-P1-F-03 — `OrderCreated::dispatch` hors `DB::afterCommit()` — P0
- file:line : `app/Services/OrderService.php:898-908` (dispatch après `DB::transaction` mais sans `afterCommit`) ; `:1397-1404`, `:1464-1473`
- description : Le dispatch est *post*-transaction close, mais n'utilise pas le helper `DB::afterCommit(fn() => ...)`. En mode read-replica lag ou en handler sync qui ouvre une nouvelle tx, le listener peut manquer la ligne. De plus `changePaymentStatus` **n'émet aucun event** du tout — le KDS/OSS/Kiosk ne sauront jamais qu'un UNPAID est devenu PAID.
- impact : Perte de signaux temps réel (impression cuisine KDS manquée, OSS désynchronisé, stats différées). Corruption possible si un listener tente de lire une version stale.
- fix_proposal : Wrapper standard `DB::afterCommit(fn() => OrderCreated::dispatch($order))` dans le `DB::transaction`. Ajouter `PaymentStatusChanged` event + dispatch depuis `changePaymentStatus`.
- invariants touchés : Dispatch after commit, EventContract, centralisation multi-surfaces.
- resurface_from : `tasks/audits/AUDIT_POS_ORDER_CREATION_001.md` Q2, §6 POS_MASTER_BRIEF.

### POS-P1-F-04 — Discount staff sans permission Spatie ni seuil — P0
- file:line : `resources/js/components/admin/pos/PosComponent.vue:1139-1158` (UI), `app/Services/OrderService.php:763-770` (accept), `app/Http/Requests/PosOrderRequest.php:38` (rule `discount: nullable|numeric|min:0`)
- description : Tout caissier avec permission `pos` peut saisir 100 % de remise (ou un montant fixe arbitraire ≤ subtotal) sans check de rôle (`manager`, `admin`), sans motif obligatoire, sans seuil config (ex : remise > 10 % = admin requis). L'`ActionLog` enregistre le total et la remise dans un texte libre non exploitable (pas de colonne structurée `discount_amount`, `discount_reason`, `approver_id`).
- impact : Risque de fraude caissier massif (revue interne quasi impossible sur texte libre). Non-conformité gouvernance grande chaîne.
- fix_proposal : `PosOrderRequest` → rule conditionnelle `discount > seuil` requiert `$user->can('apply-discount-above-threshold')`. `ActionLog` structuré avec colonnes dédiées. Motif obligatoire côté UI + backend.
- invariants touchés : permissions, audit log, SSOT discount.
- resurface_from : `tasks/audits/AUDIT_POS_COUPON_LOYALTY_005.md` Q8, `POS_INVARIANTS_AND_GATES §1.2`.

### POS-P1-F-05 — Aucune intégration TPE : `pos_payment_note` = 4 chiffres tapés à la main — P1
- file:line : `resources/js/components/admin/pos/PaymentComponent.vue:64-67, 207-211`, `app/Http/Requests/PosOrderRequest.php:60`
- description : Le champ CARD demande au caissier les 4 derniers chiffres en clair. Aucun retour TPE (no `auth_code`, no `rrn`, no `acquirer_ref`). Pas de bridge `window.borne.cardPay()` (grep vide). La commande est marquée PAID instantanément par le backend (`OrderService.php:588`) sans aucune preuve d'encaissement.
- impact : Risque fiscal / comptable (preuve encaissement absente). Risque fraude (caissier annonce "carte" sans passer le TPE, empoche le cash). Impossible de rapprocher bancaire.
- fix_proposal : Spec TPE bridge (protocole Concert / NEPTING / Ingenico) + colonnes `payment_reference`, `acquirer_response`. Ne passer PAID qu'après ACK TPE signé.
- invariants touchés : SSOT paiement, audit log, EventContract.
- resurface_from : `RAPPORT_TEST_FLUX_PAIEMENT_POS.md` ("intégration terminal à faire").

### POS-P1-F-06 — Aucun split bill (par item / personne / montant) — P1
- file:line : `routes/api.php:621-638` (routes `pos` et `pos-order` — aucune route split), `app/Services/OrderService.php` (aucune méthode `splitOrder`)
- description : Fonctionnalité totalement absente. La UI (`PosComponent.vue`) n'a pas de bouton split.
- impact : Cas d'usage quotidien resto groupé (4 clients table, un paie cash, trois partagent la carte) impossible. Gap compétitif majeur.
- fix_proposal : Phase POS-B dédiée : modèle `order_payments`, API `POST /pos-order/{order}/split`, UI drag-n-drop items→payeurs.
- invariants touchés : state machine paiement, SSOT, EventContract.
- resurface_from : `tasks/phase9-pos/POS_MASTER_BRIEF.md §3.1`.

### POS-P1-F-07 — Dine-in et table selector masqués via `v-if="false"` — P1
- file:line : `resources/js/components/admin/pos/PosComponent.vue:121, 235`
- description : Les labels `dineInOrder` et la `<select diningtables>` sont hardcodés cachés. Le flag métier n'est pas une config admin mais un `v-if="false"` en dur.
- impact : Aucun service à table possible. Pour un resto assis, le POS est inutilisable. Si la feature a été intentionnellement retirée pour livraison/vente à emporter, le code orphelin est un dette technique (`dineInOrder`, `diningtables`, `dining_table_id` form field vides).
- fix_proposal : Décider politique métier : re-activer conditionnellement via config branche (`branch.has_dining`) OU supprimer intégralement le code mort + la rule `dining_table_id` conditional du FormRequest.
- invariants touchés : propreté architecturale, scope produit.
- resurface_from : (aucun — nouveau).

### POS-P1-F-08 — Aucune ouverture tiroir-caisse depuis POS — P1
- file:line : `resources/js/components/admin/pos/PaymentComponent.vue:191-277` (confirmOrder sans openDrawer), `resources/js/services/kioskHardware.js:259-262` (openDrawer existe mais uniquement câblé kiosk)
- description : Après paiement cash confirmé, **aucun appel** à un bridge tiroir. Grep `openDrawer` dans `resources/js/components/admin/pos/` : 0 hit. Le caissier ouvre manuellement le tiroir avec une clé / bouton mécanique.
- impact : Pas d'auditabilité ouvertures tiroir (aucun event `DrawerOpened`, aucune trace par ouvertures hors-vente). Non-conformité audit interne / anti-fraude.
- fix_proposal : Câbler `kioskHardware.openDrawer()` (ou bridge POS équivalent) dans `confirmOrder` succès cash + logger chaque ouverture (staff, motif, timestamp, commande associée).
- invariants touchés : audit log, conformité fiscale / anti-fraude.
- resurface_from : `POS_INVARIANTS_AND_GATES §1.2` ("Chaque ouverture = event loggé").

### POS-P1-F-09 — Ticket : TVA non ventilée par taux — P1
- file:line : `resources/js/components/admin/pos/ReceiptComponent.vue:105-112`
- description : Une seule ligne "Total TVA" agrégée. Impossible de distinguer 5.5 % / 10 % / 20 %. L'article 242 nonies A du CGI exige la ventilation.
- impact : **Non-conformité fiscale France**. Un client professionnel (note de frais, facture) ne peut pas exploiter le ticket.
- fix_proposal : Computed `taxBreakdown` par `tax_rate`, rendu `<tr v-for>` par taux.
- invariants touchés : conformité fiscale, SSOT pricing (les données existent par item via `tax_rate`).
- resurface_from : `tasks/audits/AUDIT_POS_RECEIPT_INSTRUCTIONS_009.md` Q6.

### POS-P1-F-10 — Ticket n'imprime pas `queue_number` (seul `token` apparaît) — P1
- file:line : `resources/js/components/admin/pos/ReceiptComponent.vue:152-156`, `app/Services/OrderService.php:801-802`
- description : `queue_number` est calculé serveur atomiquement (format `A0001`) et exposé par `OrderDetailsResource.php:22`. Mais le ticket imprime `order.token` (fabriqué client-side depuis localStorage, potentiellement réutilisé cross-caisses). L'OSS client-display affichera `A0001`, le ticket dira "N° 42" — désynchronisation client.
- impact : UX client dégradée (il ne peut pas rapprocher son ticket de l'écran d'appel commande).
- fix_proposal : Imprimer `queue_number` en gros sur le ticket, reléguer `token` au nom d'appel delivery.
- invariants touchés : UX / cohérence multi-surfaces.
- resurface_from : (nouveau).

### POS-P1-F-11 — Aucune réimpression ni journal d'impression — P1
- file:line : `resources/js/components/admin/pos/ReceiptComponent.vue:10-14`, absence `PrintLog` model (grep vide)
- description : Pas de bouton "Réimprimer" depuis l'historique des commandes. Pas de log d'échec impression. Si la caissière ferme la modale sans imprimer, aucun moyen de récupérer le ticket sauf rechercher la commande puis impression Chrome. Aucun `PrintJob` queue / retry.
- impact : Perte de tickets, appels cuisine manqués, réclamations clients sans preuve.
- fix_proposal : Écran historique POS : action "Re-print" sur commande. Table `print_logs` (order_id, device, status, error). Retry auto si imprimante Electron échoue.
- invariants touchés : audit log, UX resilience.
- resurface_from : `AUDIT_POS_RECEIPT_INSTRUCTIONS_009.md` Q3-Q5.

### POS-P1-F-12 — Rules serveur wizard : aucune validation min/max / required — P1
- file:line : `app/Rules/ValidJsonOrder.php:31-73`, `app/Http/Requests/PosOrderRequest.php:58`
- description : `ValidJsonOrder` vérifie uniquement `item_id > 0`, `quantity > 0`, `instruction ≤ 500`. Un sandwich peut partir en cuisine **sans pain**, sans viande, avec 15 variations simultanées du même attribut. Les guards cross-item (`OrderService.php:676-707`) rejettent un variation_id mal appariée mais n'empêchent pas l'omission complète d'un attribut.
- impact : Commande cuisine invalide, retours client, réclamations.
- fix_proposal : Étendre `ValidJsonOrder` : pour chaque `item_id`, charger `itemAttributes + min_selection + max_selection`, vérifier sélection conforme. Idem addons.
- invariants touchés : SSOT règles produit, symétrie POS/Kiosk.
- resurface_from : `AUDIT_POS_WIZARD_CART_006.md` Q2.

### POS-P1-F-13 — Divergence structure items POS vs Kiosk (pas de contract commun) — P1
- file:line : `resources/js/components/admin/pos/ItemComponent.vue:1176-1224` (POS build), vs `resources/js/components/frontend/kiosk/KioskPosWizardComponent.vue` + `wizard_selections` kiosk
- description : Le POS envoie `item_variations:[{id,item_id,item_attribute_id,variation_name,name}]` + `item_extras:[{id,item_id,name}]` + `pos_line_addons` éclatés en lignes sœurs. Le kiosk V2 a migré vers `wizard_selections` structuré. Le backend doit accepter les deux sans contrat explicite. À la re-edit POS, on reconstruit via heuristiques sur noms FR (`buildWizardRestorePayload:721-915`).
- impact : Dette de symétrie. Bug fix sur un chemin ne propage pas à l'autre. Régressions futures garanties.
- fix_proposal : Introduire un `OrderItemPayloadContract v1` versionné, partagé POS + Kiosk + Web. Migrer progressivement via adapter.
- invariants touchés : OrderService / FrontendOrderService symmetry.
- resurface_from : `AUDIT_POS_WIZARD_CART_006.md` Q1, `AUDIT_KIOSK_GLOBAL_2026-04-18.md` (si déjà soulevé).

### POS-P1-F-14 — `buildWizardRestorePayload` basé sur heuristiques de noms d'attribut FR — P2
- file:line : `resources/js/components/admin/pos/ItemComponent.vue:721-915`
- description : Reconnaissance via `attrLower.includes('pain')`, `includes('viande')|'meat'`, `includes('sauce')`, `includes('accompagnement')|'riz'|'salade'`. Si un admin nomme un attribut "Base" au lieu de "Pain", le re-edit perd cette sélection silencieusement.
- impact : Edit panier sélectivement cassé selon nommage admin. Extrêmement dur à détecter en QA.
- fix_proposal : Ajouter à `ItemAttribute` un champ `role` (`bread|meat|sauce|accompaniment|addon|…`) renseigné par admin. La restauration s'appuie sur le rôle, pas le nom.
- invariants touchés : configurabilité admin, i18n future.
- resurface_from : (nouveau).

### POS-P1-F-15 — Panier localStorage non scopé par caissier/branche — P2
- file:line : `resources/js/store/modules/posCart.js:6-44`
- description : `POS_CART_KEY = 'pos_cart_v2'` est global au domaine. Un changement de caissier (même compte logout→login, ou session expirée) peut restaurer le panier d'un autre. Aucun check `user_id` / `branch_id` lors du `loadCartFromStorage`.
- impact : Fuite items/instructions/client entre opérateurs. RGPD borderline si le panier contient `customer_id` → infos client.
- fix_proposal : Clé `pos_cart_v2:<branch_id>:<user_id>`. Invalidation sur logout via hook auth.
- invariants touchés : RGPD, isolation multi-tenant.
- resurface_from : (nouveau).

### POS-P1-F-16 — Token séquentiel journalier fabriqué côté client localStorage — P2
- file:line : `resources/js/components/admin/pos/PosComponent.vue:1253-1266`
- description : `pos_order_seq_YYYY-MM-DD` en localStorage, incrémenté client. Deux caisses à la même branche génèrent les mêmes tokens ; reset minuit par client local (vulnérable au clock drift) ; perte si cache navigateur vidé.
- impact : Collisions token entre postes (2 commandes "N°5" au même moment), incohérence numérotation branche.
- fix_proposal : Attribuer `token` serveur dans le même `Cache::lock` que `queue_number`, ou utiliser directement `queue_number` comme token d'appel.
- invariants touchés : atomicité, SSOT identifiants.
- resurface_from : (nouveau, lié à `AUDIT_POS_ORDER_CREATION_001.md` Q9).

---

## 8. Synthèse tableau findings

| ID | Priorité | Titre court | Fichier |
|---|---|---|---|
| POS-P1-F-01 | P0 | Paiement binaire, multi-tender impossible | `PaymentStatus.php:1-10` |
| POS-P1-F-02 | P0 | Pas d'`OrderStateMachine::apply` | `OrderService.php:588, 1439, 1505` |
| POS-P1-F-03 | P0 | Events hors `afterCommit`, pas d'event paiement | `OrderService.php:898, 1397, 1485-1526` |
| POS-P1-F-04 | P0 | Discount staff sans permission ni seuil | `PosComponent.vue:1139`, `PosOrderRequest.php:38` |
| POS-P1-F-05 | P1 | Pas d'intégration TPE, 4 digits tapés | `PaymentComponent.vue:64`, `PosOrderRequest.php:60` |
| POS-P1-F-06 | P1 | Split bill absent | `routes/api.php:621-638` |
| POS-P1-F-07 | P1 | Dine-in + tables masqués `v-if="false"` | `PosComponent.vue:121, 235` |
| POS-P1-F-08 | P1 | Aucune ouverture tiroir POS | `PaymentComponent.vue:191-277` |
| POS-P1-F-09 | P1 | TVA non ventilée sur ticket | `ReceiptComponent.vue:105-112` |
| POS-P1-F-10 | P1 | `queue_number` non imprimé | `ReceiptComponent.vue:152-156` |
| POS-P1-F-11 | P1 | Pas de re-print ni log impression | `ReceiptComponent.vue:10-14` |
| POS-P1-F-12 | P1 | Pas de validation min/max wizard serveur | `ValidJsonOrder.php:31-73` |
| POS-P1-F-13 | P1 | Divergence structure items POS vs Kiosk | `ItemComponent.vue:1176-1224` |
| POS-P1-F-14 | P2 | Restore wizard via heuristiques noms FR | `ItemComponent.vue:721-915` |
| POS-P1-F-15 | P2 | localStorage panier non scopé | `posCart.js:6-44` |
| POS-P1-F-16 | P2 | Token journalier client localStorage | `PosComponent.vue:1253-1266` |

**Distribution priorité :** 4 × P0 | 9 × P1 | 3 × P2 | 0 × P3.

---

## 9. Notes de méthode

- Fichiers ouverts intégralement : `PosComponent.vue` (1863 lignes), `ItemComponent.vue` (1068), `PaymentComponent.vue` (280), `ReceiptComponent.vue` (243), `CreateCustomerAddressComponent.vue` (197), `posCart.js` (305), `posOrder.js` (203), `PosController.php`, `PosOrderController.php`, `PosOrderRequest.php`, `ValidJsonOrder.php`, `PaymentStatus.php`, `PosPaymentMethod.php`, `DiscountType.php`, `OrderStatus.php`.
- Extraits ciblés : `OrderService.php` L1-100, L540-930, L1360-1700 (le brief autorisait lecture partielle).
- Greps vérifiés : `posOrderStore|changePaymentStatus|changeStatus|applyDiscount|splitBill|tabTable|openDrawer|kitchen_notes` ; `OrderStateMachine::apply` ; `afterCommit` ; `PARTIALLY_PAID|order_payments|partial_payment|split_bill` ; `TPE|cardTerminal|payment_provider_ref` ; `min_selection|max_selection|required_selection` ; `Route::.*pos` dans `routes/api.php`.
- Rien écrit hors ce fichier. Aucune mutation git.
