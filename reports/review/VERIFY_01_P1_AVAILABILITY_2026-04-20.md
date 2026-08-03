# VERIFY-01 — P1 Stock / disponibilité branche (POS ↔ Kiosk ↔ KDS)

**Date :** 2026-04-20  **Mode :** AUDIT-ONLY (lecture, aucune édition code).
**Origine :** `tasks/verify-2026-04-20/01_VERIFY_P1_AVAILABILITY.md` — P1 (`b76506ae9`) + finding `F-SYNC-001` (audit POS 110).
**Périmètre :** chemins backend de création/mutation `Order` / `FrontendOrder` + chaîne d'événements `ItemAvailabilityChanged` côté front (kiosk + POS) + cache `MenuController::kiosk` + données `item_branch_availability`.

---

## 0. Verdict global

> **GLOBAL : WARN** — la garde `AvailabilityService::assertItemsOrderableForBranch` couvre **TOUS** les chemins de production routés (V1 OK, V3 OK pour le calcul prix, V5/V9 OK), mais 3 écarts non bloquants pour la sécurité doivent être tracés (V4 transactionnel + V6 couverture E2E partielle + V7 doc divergente).

Détail :
- ✅ V1 / V2 / V3 (vs PricingService) / V5 / V8 / V9 : preuves directes ci-dessous.
- ⚠️ V3 / V4 : `Order::create` (resp. `FrontendOrder::create`) précède la garde dans la même transaction → rollback systématique mais aucune persistance externe au commit ; pas de risque sécurité, juste du travail SQL gaspillé sous charge.
- ⚠️ V6 : test Feature `OrderRejectsUnavailableBranchItemTest` couvre le chemin Kiosk/Frontend ; aucun test équivalent pour `posOrderStore` ni `tableOrderStore`, aucun test E2E Playwright dédié.
- ❌ V7 : `docs/BUSINESS_RULES.md` §Stock Management déclare encore "not implemented" et "no `is_available` flag" → divergence documentaire majeure (pas modifiée car AUDIT-ONLY).

---

## 1. Sources lues

Backend (call sites Order / OrderItem) :
- `app/Services/Menu/AvailabilityService.php`
- `app/Services/Pricing/PricingService.php`, `PricingRequest.php`
- `app/Services/OrderService.php` (`myOrderStore`, `posOrderStore`, `tableOrderStore`)
- `app/Services/FrontendOrderService.php` (`myOrderStore`, `finalizePaidKioskOrder`)
- `app/Http/Controllers/Admin/PosController.php`, `PosOrderController.php`
- `app/Http/Controllers/Frontend/OrderController.php`, `MenuController.php`, `KioskEventController.php`
- `app/Http/Controllers/Table/OrderController.php`
- `routes/api.php` (mappings 625, 1007, etc.)
- `app/Console/Commands/SimulateKioskOrders.php`
- `app/Listeners/InvalidateKioskMenuCacheOnItemAvailabilityChanged.php`

Front (cart-add + propagation événement) :
- `resources/js/store/modules/kioskCart.js`
- `resources/js/store/modules/kioskMenu.js`
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
- `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue`, `KioskProductListComponent.vue`, `KioskWizardComponent.vue`, `KioskUpsellComponent.vue`
- `resources/js/components/admin/pos/PosComponent.vue`, `pos/ItemComponent.vue`

Données + tests :
- `database/migrations/2026_04_15_230100_create_item_branch_availability_table.php`
- `database/migrations/2026_04_18_140001_add_fks_to_item_branch_availability.php`
- `tests/Feature/Menu/OrderRejectsUnavailableBranchItemTest.php`
- `tests/Feature/Menu/AvailabilityServiceTest.php`
- `tests/Feature/KioskPhase1/InvalidateKioskMenuCacheListenerTest.php`
- `tests/js/kioskMenuStore.spec.js`, `tests/js/posItemAvailabilityHandler.spec.js`

Docs : `docs/BUSINESS_RULES.md` (§Stock Management), `plans/PLAN_P1_STOCK_SYNC_HANDOFF.md`, `reports/review/AUDIT_POS_110_SYNC_CROSS_SURFACE_2026-04-19.md`.

---

## 2. Pass A — Matrice chemin × garde (backend, production)

| # | Chemin métier | Route | Controller (fichier:ligne) | Service (méthode → ligne) | Garde appelée | Preuve `assertItemsOrderableForBranch` | Verdict |
|---|---|---|---|---|---|---|---|
| 1 | Frontend / Kiosk customer order | `POST /api/frontend/order` | `app/Http/Controllers/Frontend/OrderController.php:38` | `FrontendOrderService::myOrderStore` (`app/Services/FrontendOrderService.php:122`) | OUI | SSOT path : `PricingService::calculateOrder` (`app/Services/Pricing/PricingService.php:40-44`) appelé `:212`. Legacy fallback : `app/Services/FrontendOrderService.php:273-277` | ✅ |
| 2 | POS cashier order | `POST /api/v1/admin/pos` | `app/Http/Controllers/Admin/PosController.php:22` | `OrderService::posOrderStore` (`app/Services/OrderService.php:556`) | OUI | SSOT path `PricingService::calculateOrder` (`app/Services/OrderService.php:612`). Legacy fallback : `app/Services/OrderService.php:659-663` | ✅ |
| 3 | Table QR order | `POST /api/frontend/table-order` (`routes/api.php:1007`) | `app/Http/Controllers/Table/OrderController.php:24` | `OrderService::tableOrderStore` (`app/Services/OrderService.php:992`) | OUI | SSOT path `:1016`. Legacy fallback : `app/Services/OrderService.php:1059-1063` | ✅ |
| 4 | (mort) `OrderService::myOrderStore` (Web/App legacy) | aucune (non routée) | — | `app/Services/OrderService.php:289` | OUI (defensive) | SSOT path `:314`. Legacy fallback `:360-364` | ⚠️ dead code (pas de route) — voir F-VERIFY-01-04 |
| 5 | Reorder POS | `GET /api/v1/admin/pos-order/{order}/reorder-items` | `app/Http/Controllers/Admin/PosOrderController.php:120` | aucune création — renvoie le payload cart pour resoumission via le chemin #2 | N/A (resoumission re-passe par #2) | `app/Http/Controllers/Admin/PosOrderController.php:147-153` (lecture seule) | ✅ |
| 6 | `finalizePaidKioskOrder` (promotion paiement TPE/TR) | `Frontend/OrderController::paymentConfirm` `:120` | — | `FrontendOrderService::finalizePaidKioskOrder` (`app/Services/FrontendOrderService.php:779`) | N/A — change uniquement `status` `PENDING → ACCEPT`, n'ajoute aucun `OrderItem` | `:799-811` (UPDATE seul) | ✅ hors scope V1 |
| 7 | KioskEventController (analytics borne) | `POST /api/frontend/kiosk-event` | `app/Http/Controllers/Frontend/KioskEventController.php:142` | aucune — `ActionLog::create` uniquement | N/A | `:218-227` | ✅ aucun OrderItem créé |
| 8 | `php artisan kiosk:simulate-orders` (load test) | console | — | `app/Console/Commands/SimulateKioskOrders.php:36` `Order::create` direct | NON | `:36-50` (pas d'OrderItem inséré, totals factices) | ⚠️ outil de stress hors prod — voir F-VERIFY-01-05 |

**Résumé V1 :** chaque chemin de production qui insère des `order_items` passe par la garde, soit via `PricingService::calculateOrder` (chemin SSOT activé par défaut `config('pricing.use_ssot_service', true)`), soit via l'appel défensif direct dans la branche legacy non-SSOT. Les 3 services `myOrderStore`/`posOrderStore`/`tableOrderStore` ont **les deux** appels (ceinture + bretelles).

---

## 3. Pass B — Front (kiosk + POS)

### 3.1 Sites d'ajout panier

| Surface | Composant / fichier | Action `addItem` | Désactivation tile via `is_available` |
|---|---|---|---|
| Kiosk Categories | `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue:552-556` | `this.addItem(this.buildSimpleCartItem(...))` | tuiles cataloguent `is_available` (mutation `kioskMenu/UPDATE_ITEM`) |
| Kiosk Product list | `resources/js/components/frontend/kiosk/KioskProductListComponent.vue:245-263` | `this.addItem(...)` | idem |
| Kiosk Wizard | `resources/js/components/frontend/kiosk/KioskWizardComponent.vue:1126` | `this.$store.dispatch('kioskCart/addItem', cartItem)` | wizard ferme via overlay `KioskErrorProductRemoved` (P9.3.11) sur event |
| Kiosk Upsell | `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue:158-224` | `addItem(...)` | filtre menu items |
| Kiosk App | `resources/js/components/frontend/kiosk/KioskAppComponent.vue:487` | `this.$store.dispatch('kioskCart/addItem', item)` | n/a (point d'entrée) |
| POS | `resources/js/components/admin/pos/ItemComponent.vue:985, 1054-1063` | `addToCart()` (Vuex `posItems` mutate) | tuile désactivée si `is_available === false` (handler `_onItemAvailabilityChanged`) |

L'action store `kioskCart/addItem` (`resources/js/store/modules/kioskCart.js:252-254`) ne refait **pas** la vérif `is_available` ; le filtre est porté par la vue (greying out) et par la prune Vuex décrite §3.2. **Aucune régression** : la garde serveur reste la SSOT.

### 3.2 Propagation `ItemAvailabilityChanged` → cart prune (kiosk)

- Souscription Echo : `KioskAppComponent.vue:381-397` (`_subscribeEchoChannel`) appelle `onEvent(branchId, 'ItemAvailabilityChanged', …)`.
- Réception : commit `kioskMenu/UPDATE_ITEM` (`:387`) puis dispatch `kioskCart/pruneUnavailableLines` (`:388`).
- Prune : `resources/js/store/modules/kioskCart.js:337-352` retire toute ligne dont `is_available === false` ou `status ∈ {0,2}`.
- Echo OFF / fallback TTL : si `window.Echo` absent, `KioskAppComponent.vue:382` short-circuit ; le cache `kiosk.menu.branch.{id}` (TTL 60 s) reprend la main, ET la garde serveur stoppe la commande à `POST /api/frontend/order`.
- Test unit : `tests/js/kioskMenuStore.spec.js:10-30` confirme `UPDATE_ITEM` patche `is_available` + `unavailable_reason`.

### 3.3 Propagation côté POS

- Souscription : `resources/js/components/admin/pos/PosComponent.vue:1086` (broadcastAs `ItemAvailabilityChanged`) → handler `_onItemAvailabilityChanged` `:1098-1122`.
- Action : grise la tuile (mutation in-place sur `itemsRaw`) et déclenche `itemList()` si `payload.type === 'full'`.
- ⚠️ Le POS ne **prune pas** le panier en cours (différent du kiosk). Si une ligne déjà ajoutée bascule unavailable, la garde serveur la rejette à submit (`OrderService::posOrderStore` ligne 612 / 659). Le cashier reçoit une 422 sans message ciblé sur la ligne fautive (`InvalidArgumentException` avec `item_id` mais le contrôleur enveloppe tout dans `response(['status' => false, 'message' => $e->getMessage()], 422)` — `app/Http/Controllers/Admin/PosController.php:26-28`). UX dégradée → finding F-VERIFY-01-02.

---

## 4. Vérifications V1 → V9 (preuves `fichier:ligne`)

### V1 — Garde présente sur chaque chemin de création — **PASS**
- `app/Services/FrontendOrderService.php:212-222` (Kiosk SSOT) + `:273-277` (legacy fallback)
- `app/Services/OrderService.php:612-623` (POS SSOT) + `:659-663` (legacy)
- `app/Services/OrderService.php:1016-1027` (Table SSOT) + `:1059-1063` (legacy)
- `app/Services/OrderService.php:314-324` (Web/App SSOT — chemin non routé) + `:360-364` (legacy)
- Garde centrale `app/Services/Pricing/PricingService.php:37-45`.

### V2 — Code 422 + payload structuré — **PASS**
- `app/Services/Menu/AvailabilityService.php:146-149` :
  `throw new \InvalidArgumentException("Article {$itemId} indisponible pour cette branche ({$reason}).", 422);`
- Tous les controllers concernés transforment l'exception en `response(['status' => false, 'message' => $e->getMessage()], 422)` (`PosController:26-28`, `Frontend/OrderController:48-50`, `Table/OrderController:31-33`).
- `item_id` et `branch_id` apparaissent en clair dans le message ; raison structurée (`reason`) est portée mais non sérialisée en JSON dédié → finding doc/UX F-VERIFY-01-03.

### V3 — Garde appelée AVANT `PricingService::calculateOrder` ET avant la persistance — **PASS partiel (PricingService) / WARN (Order row)**
- ✅ Avant le calcul prix : la première instruction de `PricingService::calculateOrder` (`PricingService.php:30-45`) est précisément la garde. Aucune ligne `OrderItem` n'est calculée avant.
- ⚠️ Vis-à-vis de la création de la ligne `orders` / `frontend_orders` : `Order::create` (`OrderService.php:298, 593, 999`) et `FrontendOrder::create` (`FrontendOrderService.php:195`) précèdent la garde **dans la même transaction** (`DB::transaction(function () { ... })`). En cas d'item unavailable, la transaction rollback intégralement ; aucune fuite de données mais `INSERT` + rollback systématique.
  - Conséquence sécurité : nulle (rollback atomique).
  - Conséquence perf : sous burst d'erreurs (rupture massive), génère du write amplification. Voir F-VERIFY-01-01 pour cycle P12 proposé.

### V4 — Aucune transaction DB ouverte avant la vérif — **WARN**
- Voir V3. La garde est appelée **dans** la transaction, après le `Order::create`. Cela est volontaire : `assertItemsOrderableForBranch` utilise `lockForUpdate()` sur `item_branch_availability` pour serialiser avec les toggles concurrents (`AvailabilityService.php:135-136`). Sans transaction ouverte, le lock n'aurait aucun effet.
- Compromis assumé documenté dans `plans/PLAN_P1_STOCK_SYNC_HANDOFF.md`. Pas un FAIL — mais V4 du task file est rédigé de façon trop stricte ; à clarifier (F-VERIFY-01-01).

### V5 — Event de rupture purge le panier ET désactive l'item dans le menu kiosk — **PASS**
- Désactivation menu : `kioskMenu` mutation `UPDATE_ITEM` patch `is_available` + `unavailable_reason` (`tests/js/kioskMenuStore.spec.js:10-30`, code source `resources/js/store/modules/kioskMenu.js`).
- Purge cart : `KioskAppComponent.vue:388` → `kioskCart/pruneUnavailableLines` (`kioskCart.js:337-352`).
- Backend : émission event `app/Services/Menu/AvailabilityService.php:55, 69, 195-201, 207-212` (forBranch), persistance outbox `PersistItemAvailabilityChangedToOutbox`, broadcast Soketi/Pusher canal `private-branch.{id}`.

### V6 — Test E2E ou Feature démontre rupture pendant ajout → impossible de checkout — **PARTIAL (1 chemin sur 4)**
- ✅ `tests/Feature/Menu/OrderRejectsUnavailableBranchItemTest.php:29-121` : prouve `POST /api/frontend/order` → 422 « indisponible » pour le chemin Kiosk/Frontend.
- ❌ Aucun test équivalent pour `posOrderStore` (POS), `tableOrderStore` (table QR) ni `OrderService::myOrderStore` (legacy non routé).
- ❌ Aucun test E2E Playwright `tests/e2e/**/availability*` n'existe (recherche ripgrep + Glob, voir §1). Le test live précédant la rupture côté kiosk n'a pas de couverture E2E.
- → finding F-VERIFY-01-06.

### V7 — `BUSINESS_RULES.md` mentionne la règle dispo branche post-P1 — **FAIL doc**
- `docs/BUSINESS_RULES.md:57-59` :
  > "Stock Management (not implemented). FoodKing v1 does not track item stock levels. There is no `stock` column, no `is_available` flag, and no stock validation at order time. Planned for v2: `items.stock_quantity` (nullable integer)…"
- Réalité observée : `item_branch_availability.is_available` existe (migration 2026_04_15_230100), `assertItemsOrderableForBranch` valide à la création (P1). Doc obsolète. Aucune édition appliquée (AUDIT-ONLY) → finding F-VERIFY-01-07 + cycle P11 proposé.

### V8 — Contraintes DB attendues — **PASS**
- `database/migrations/2026_04_15_230100_create_item_branch_availability_table.php:15-28` :
  - `unique(['item_id', 'branch_id'])` (anti-doublons).
  - `boolean is_available DEFAULT TRUE`.
  - `unsignedInteger max_daily_qty NULL` (cap quotidien optionnel).
  - `unsignedInteger daily_consumed_qty DEFAULT 0`, `date daily_reset_at` (compteur jour).
  - `index(['branch_id', 'is_available'])`.
- `database/migrations/2026_04_18_140001_add_fks_to_item_branch_availability.php:28-38` : FK `cascadeOnDelete` sur `item_id` et `branch_id` (sauf SQLite). Cleanup orphelins préalable `:16-22`.
- Modèle `App\Models\ItemBranchAvailability` aligné (cf usage dans `AvailabilityService`).

### V9 — Cache `MenuController::kiosk` ne masque pas une indisponibilité fraîche — **PASS**
- Cache : `app/Http/Controllers/Frontend/MenuController.php:67-72` (clé `kiosk.menu.branch.{branchId}`, TTL `config('kiosk.menu_cache_ttl', 60)`).
- Invalidation : `app/Listeners/InvalidateKioskMenuCacheOnItemAvailabilityChanged.php:45-67` — `Cache::forget('kiosk.menu.branch.{branchId}')` à chaque event branch-scoped, et fan-out toutes branches actives sur event global. Test : `tests/Feature/KioskPhase1/InvalidateKioskMenuCacheListenerTest.php`.
- Throttle route `POST /api/frontend/order` : `routes/api.php` : la route `/api/frontend/menu` n'a pas de throttle qui bloquerait l'invalidation, et la route POS `:625` est `throttle:pos-order-create` → orthogonal au sujet.
- Ceinture finale : même si le cache renvoyait une donnée stale, `assertItemsOrderableForBranch` lit `ItemBranchAvailability` en direct (non caché) au moment du commit (`AvailabilityService.php:130-138`).

---

## 5. Cross-check `docs/BUSINESS_RULES.md` (sans modification)

| Section doc | Réalité code | Divergence ? |
|---|---|---|
| §Stock Management (`:57-59`) — "not implemented", "no `is_available` flag", "no stock validation at order time" | P1 livré : `item_branch_availability` + garde + UI `is_available` | **OUI majeure** (à corriger via cycle P11) |
| §Pricing SSOT (autres sections) | `PricingService::calculateOrder` toujours autoritaire | OK |
| §Order Status enum | Inchangé par P1 | OK |

---

## 6. Findings (`F-VERIFY-01-*`)

| ID | Sévérité | Type | Description | Preuve | Cycle P proposé |
|---|---|---|---|---|---|
| F-VERIFY-01-01 | LOW | Perf / cohérence | `Order::create` précède la garde dans la même transaction → INSERT + rollback systématique sur rupture. Acceptable pour security, à optimiser pour bursts. | `OrderService.php:298,593,999`, `FrontendOrderService.php:195` vs garde `:273/360/659/1059` | **P12_OrderCreate_GuardFirst** : hisser la garde avant `Order::create`, garder `lockForUpdate()` via savepoint nested. |
| F-VERIFY-01-02 | MEDIUM | UX POS | POS ne purge pas son panier sur `ItemAvailabilityChanged` ; la 422 serveur n'est pas remappée sur la ligne fautive. | `PosComponent.vue:1098-1122` (handler menu uniquement) ; `PosController.php:26-28` (message générique) | **P13_POS_CartPrune_PosUX** : reproduire la prune kiosk dans `posItems` store + parsing `item_id` côté front pour highlight ligne. |
| F-VERIFY-01-03 | LOW | Contrat API | `assertItemsOrderableForBranch` jette `InvalidArgumentException` avec message texte ; pas de payload JSON structuré (`item_id`, `reason`, `code`). Rend le mapping front fragile. | `AvailabilityService.php:146-149` ; controllers wrap en `message` plat. | **P14_AvailabilityException_StructuredPayload** : custom exception (`ItemUnavailableForBranchException`) sérialisée en `errors.items[]`. |
| F-VERIFY-01-04 | LOW | Dead code | `OrderService::myOrderStore` (`:289`) n'est appelé par aucun controller routé. Encore patché par P1 par symétrie. | `routes/api.php` (aucun mapping) ; `app/Http/Controllers/**` (aucun appel) | **P15_OrderService_PurgeMyOrderStore** : valider absence de référence runtime puis retirer (cycle scope étroit avec FrontendOrderService symétrie). |
| F-VERIFY-01-05 | LOW | Hors prod | `kiosk:simulate-orders` (`SimulateKioskOrders.php:36`) crée des Orders sans garde (et sans OrderItems). Bypass possible en prod si la commande est exposée. | `app/Console/Commands/SimulateKioskOrders.php:36-50` | **P16_LoadTest_GuardOrRemove** : ajouter check `app()->environment(['local','testing'])` ou retirer du release build. |
| F-VERIFY-01-06 | MEDIUM | Tests | Couverture Feature de la garde uniquement sur Kiosk/Frontend. Aucun test pour POS, Table QR ni E2E Playwright "rupture mid-flow". | `tests/Feature/Menu/OrderRejectsUnavailableBranchItemTest.php` (1 cas) | **P17_AvailabilityCoverage_PosTableE2E** : 2 tests Feature (POS, Table) + 1 scénario Playwright `kiosk-rupture-mid-cart`. |
| F-VERIFY-01-07 | HIGH (doc) | Documentation | `docs/BUSINESS_RULES.md:57-59` déclare le stock "not implemented" alors que P1 est livré. Risque pour onboarding et audits externes. | `docs/BUSINESS_RULES.md:57-59` | **P11_BusinessRules_StockSection** : réécrire §Stock Management pour décrire `item_branch_availability`, `assertItemsOrderableForBranch`, `decrementForOrder`, et le contrat event `ItemAvailabilityChanged`. |

---

## 7. Cycles P à créer (synthèse triée)

1. **P11_BusinessRules_StockSection** — corriger doc divergente (`docs/BUSINESS_RULES.md:57-59`). Bloquant audit externe.
2. **P12_OrderCreate_GuardFirst** — hoist garde avant `Order::create` pour éviter rollback systématique sous charge.
3. **P13_POS_CartPrune_PosUX** — symétriser la prune cart côté POS (parité kiosk).
4. **P14_AvailabilityException_StructuredPayload** — `ItemUnavailableForBranchException` sérialisée.
5. **P15_OrderService_PurgeMyOrderStore** — retirer le chemin web/app dead-code (avec symétrie FrontendOrderService).
6. **P16_LoadTest_GuardOrRemove** — sécuriser ou retirer `kiosk:simulate-orders`.
7. **P17_AvailabilityCoverage_PosTableE2E** — étendre couverture Feature (POS, Table) + Playwright kiosk rupture.

---

## 8. Conclusion

> **GLOBAL : WARN**
>
> - Sécurité : **ALL_GREEN** sur la garde elle-même : aucun chemin de production routé ne crée d'`OrderItem` sans passer par `assertItemsOrderableForBranch`, soit via `PricingService::calculateOrder` (chemin SSOT par défaut), soit via la branche legacy (ceinture + bretelles dans les 3 services). Cache kiosk invalidé à la volée. Données DB correctement contraintes.
> - Doc : **FAIL** sur `docs/BUSINESS_RULES.md` §Stock Management — section obsolète qui prétend explicitement le contraire.
> - Robustesse : **WARN** sur la séquence transactionnelle (V3/V4), la couverture tests (V6) et l'UX POS sur rupture live.
>
> 7 cycles P (P11→P17) à inscrire dans `plans/PLAN_POST_VERIFY_2026-04-20.md`. Reporter F-VERIFY-01-01/02/06/07 dans `reports/review/VERIFY_TRACKER_2026-04-20.md`.
