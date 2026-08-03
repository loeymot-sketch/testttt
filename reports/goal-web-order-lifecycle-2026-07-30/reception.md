# Audit RÉCEPTION commande SITE (web) — backend create/validate/seal

Auditeur sécurité+correctness adversaire · LECTURE SEULE · LOCAL only · 2026-07-30
Parcours audité : `POST /api/frontend/order` (source=5 WEB, token Sanctum `kiosk:order`,
header `X-Idempotency-Key`) → `OrderController::store` → `OrderRequest` →
`FrontendOrderService::myOrderStore` → `PricingService::calculateOrder` (SSOT, frozen).

Route confirmée : `routes/api.php:1439`
`Route::post('/', [FrontendOrderController::class, 'store'])->middleware(['throttle:kiosk-orders', 'idempotency'])`
dans le groupe `order` (`auth:sanctum`, ligne 1429) + groupe `frontend`
(`installed, apiKey, localization`, ligne 1353).

---

## VERDICT PAR AXE

### Axe 1 — Manipulation prix/total (money-path, PRIORITÉ) : **UNDERCHARGE = AIRTIGHT**
- `FrontendOrderService.php:271` `unset($validatedRequest['total'], ['subtotal'], ['discount'])`
  AVANT `FrontendOrder::create` → aucune valeur client ne persiste, même transitoirement.
- Total 100 % recalculé par `PricingService` SSOT (`FrontendOrderService.php:302-317`,
  `saveFrontendOrderWithQueueNumber` 544-565). Prix item TOUJOURS `$dbItem->price`
  (`PricingService.php:134`). Prouvé par `WebOrderExpectedTotalGuardTest::test_server_always_bills_its_own_ssot_total_not_client_values`.
- Cross-item guard ON (`enforceCrossItemGuards:true` via `forKiosk`) : variation/extra/addon
  d'un autre item rejeté 422 (`PricingService.php:152-157,182-187,207-212`).
- Item/variation/extra/addon inexistant → 422 (`PricingService.php:128,146,176,201`).
- Injection ratio menu (`role=menu_boisson` → -60 %) BLOQUÉE par `ValidatesAddonRoles`
  (`app/Http/Requests/Concerns/ValidatesAddonRoles.php:104-216`) + defense-in-depth
  `CompositionSnapshotBuilder`. Heal RED-Z4 P0-01 solide.
- Coupon/remise discrétionnaire : coupée en V1 par `assertDiscretionaryDiscountAllowed`
  (`FrontendOrderService.php:909-929`) — `pos.manual_discount_enabled` défaut **false**
  (`config/pos.php:187-191`). Fidélité bornée aux points RÉELLEMENT détenus (lock ligne
  `applyKioskLoyaltyDiscount` 954-1030). Aucune voie d'undercharge.

### Axe 2 — Injection branche/tenant : **AIRTIGHT (V1 mono-branche)**
- `user_id => Auth::user()->id` forcé (`:286`) — jamais client. `address_id` IDOR gardé
  (`:639-649`, throw `OrderAddressOwnershipException` → rollback atomique).
- `branch_id` client validé existant (`:156` `DB::table('branches')->...->exists()`),
  BranchScope sur FrontendOrder. Multi-tenant durci = backlog V2 (CLAUDE.md §9), hors V1.

### Axe 3 — Idempotency : **AIRTIGHT**
- Middleware scopé (branch, user, hash(key)) (`IdempotencyKeyMiddleware.php:76-82`),
  payload-diff → 409 (`:88-93,110-115`). `required_routes` inclut `api/frontend/order`
  (`config/idempotency.php`). Service : `Cache::lock` (`:170`) + DB UNIQUE + recovery
  scopé user (`findExistingFrontendOrderForIdempotencyRecovery` 760-779 avec `auth()->id()`).
- Rejeu = commande existante retournée (`:177-183`). Tests : `QueueNumberConcurrencyTest`,
  `ConcurrentOrderTest`. Note mineure : lock namespace `sha1(branch|key)` sans user, mais
  la lecture recovery est user-scopée → user B réutilisant la clé de A obtient 422, PAS la
  commande de A (pas de fuite PII).

### Axe 4 — Scellement NF525 : **AIRTIGHT pour l'étape réception**
- `composition_snapshot` figé au `OrderItem::insert` via `json_encode`
  (`PricingService.php:270-291`), jamais réécrit. Allocation `fiscal_sequence_no` : les
  commandes WEB (pas de KioskMachine) ne passent pas `finalizePaidKioskOrder`
  (`:1237-1251` exige machine kiosk) → séquence allouée à l'encaissement caisse (design
  Le Cayenne mono-poste). Échec d'alloc = flag `fiscal_alloc_error_at` + retry cron, jamais
  de gap silencieux (`:1341-1373,1450-1484`).

### Axe 5 — Validation OrderRequest : **SOLIDE** (1 gap qty extras, voir F-1)
- `delivery_charge` autorité serveur : réécrit par `DeliveryQuoteService`/`DeliveryFeeService`
  en `prepareForValidation` (`OrderRequest.php:103-129`), `address_id` requis pour DELIVERY
  → toujours re-quoté. Couvert par `OrderRequestDeliveryFeeAuthorityTest`. Non-DELIVERY :
  `delivery_charge` forcé 0 (`FrontendOrderService.php:280-282`).
- `order_type=KIOSK` refusé pour token web sans machine (`OrderRequest.php:238-241`).

### Axe 6 — Auth : **AIRTIGHT**
- `authorize()` exige `tokenCan('kiosk:order')` (`OrderRequest.php:39-86`). Token invité ne
  peut créer pour un autre user (user_id serveur) ni order_type KIOSK. `expected_total`
  optionnel = défense-en-profondeur only, jamais facturant (`:580-589`).

### Axe 7 — Race : **AIRTIGHT** — `Cache::lock` queue + DB UNIQUE + retry 5x
(`saveFrontendOrderWithQueueNumber` 1096-1128, `allocateQueueNumber` 1130-1171).

---

## FINDINGS ANCRÉS (reproductibles)

### [P2] `app/Rules/ValidJsonOrder.php:77` + `PricingService.php:188,213` — quantité extras/addons NON plafonnée → commande à total absurde PERSISTÉE
`ValidJsonOrder` plafonne UNIQUEMENT `item.quantity` (≤ 999, ligne 77). Les sous-quantités
`item_extras[].quantity` et `item_addons[].quantity` n'ont AUCUN plafond : `PricingService`
fait `max(1,(int)$extra->quantity)` (`:188`) puis `$extraTotal += price * qty` (`:189`).
`item_extras` n'est couvert par aucun min/max_select (seuls `item_variations` le sont, via
`MultiVariationConstraint` / `assertVariationConstraints`).

Reproduction (payload accepté par toutes les gardes — extra valide, appartient à l'item) :
```json
items: [{"item_id": <valide>, "quantity": 1,
         "item_extras": [{"id": <extra_valide_de_l_item>, "quantity": 9999999999999}]}]
```
Les colonnes sont `decimal(19,6)` (`create_orders_table.php:26-29`,
`create_order_items_table.php:22-28`) → un total ~5e12 € **rentre** (pas d'overflow → pas de
422) et une commande à total délirant est SCELLÉE. Impact : pollution intégrité/NF525 si
encaissée au comptoir (agrégation Z, dashboards, file, "commandes en cours"). PAS un vol
(sur-facture le client, plafonnée à ses moyens en paiement réel), mais tout token invité web
peut injecter des ordres à total arbitraire.
Correctif scope-minimal (NON frozen) : dans `ValidJsonOrder::passes`, itérer
`item_extras`/`item_variations`/`item_addons` et rejeter toute `quantity > 999` (même plafond
générique que l'item), OU un cap dédié (ex. 99). Zéro impact money-path (borne haute seule).

### [P2] `FrontendOrderService.php:302-303` — réception WEB utilise `PricingRequest::forKiosk` → visibilité options vérifiée sur surface 'kiosk' (pas 'web')
La réception web appelle `PricingRequest::forKiosk(...)` (context='kiosk') pour TOUTE commande
de cet endpoint, y compris web (aucune branche conditionnelle). `assertOptionsOrderable`
résout alors surface='kiosk' (`PricingService.php:461-463`) et teste
`isVisibleOn('kiosk')`. Or `visible_on` (null = toutes surfaces, sinon liste restreinte —
`ItemExtra.php:32-34`, `ItemVariation.php:43-45`) :
- une option `visible_on=["web"]` → `isVisibleOn('kiosk')=false` → **commande web légitime
  rejetée 422** ;
- une option `visible_on=["kiosk"]` (cachée du web) → **devient commandable via le web**.
Incohérence interne : le chemin COUPON, lui, utilise bien 'web'
(`$isKioskMachineOrder ? 'kiosk' : 'web'`, `:505,521`). Une factory `PricingRequest::forWeb`
(context='web') EXISTE (`PricingRequest.php:30-48`) mais n'est PAS utilisée ici (seul
`OrderService`/POS l'emploie). Latent : ne mord que les options à `visible_on` explicite
(défaut null = inoffensif). Reproduction directe impossible sans donnée catalogue à
`visible_on` divergent (non vérifiable en statique) → classé P2, mécanisme prouvé en code.
Correctif : router `forWeb` quand `!$isKioskMachineOrder`. ATTENTION : `forWeb` bascule AUSSI
tous les arrondis à false (vs `forKiosk` true) → changerait l'arrondi money des ordres web de
quelques centimes ; découpler surface/arrondi ou valider l'impact avant d'appliquer.

---

## AXES AIRTIGHT (explicitement)
- Undercharge money-path = airtight (unset+SSOT prouvé `WebOrderExpectedTotalGuardTest`).
- Total/subtotal/discount forgés ignorés = airtight (test (d) du même fichier).
- Auth token kiosk:order + user_id serveur + address IDOR = airtight.
- Idempotency rejeu/collision = airtight (middleware+service+DB UNIQUE, recovery user-scopé).
- Delivery fee autorité serveur = airtight (`OrderRequestDeliveryFeeAuthorityTest`).
- NF525 composition_snapshot figé + pas de gap séquence silencieux = airtight (réception).

## COMPTE : P0 = 0 · P1 = 0 · P2 = 2 · P3 = 0
Frozen touché : AUCUN (les 2 correctifs proposés vivent dans `ValidJsonOrder` et le choix de
factory dans `FrontendOrderService`, tous NON frozen ; `PricingService` cité comme cause, pas
modifié).
