# Central Management Runbook — Version A

Status: canonical VA-SYS-09 operations runbook.

## Fast map

| Area | Primary files |
| --- | --- |
| Product CRUD/photo | `ItemController`, `ItemService`, `ItemResource`, `NormalItemResource` |
| Category CRUD | `ItemCategoryController`, `ItemCategory` |
| Composer | `ComposerProfileController`, `ComposerStepController`, `ComposerProfileService`, `ComposerStepService` |
| Projections | `MenuProjectionService`, `KioskMenuService`, `ComposerProfileProjection` |
| Stock/availability | `AvailabilityService`, `StockService`, `ChoiceAvailabilityResolver` |
| Outbox/realtime | `DomainEvent`, `DispatchDomainEventsJob`, `Persist*ToOutbox` listeners |
| KDS fallback | `KdsSyncController`, `KdsSyncService.php`, `KdsSyncService.js` |
| Branch auth | `AdminController::authorizeBranchScope`, routes middleware, `routes/channels.php` |

## Symptom runbook

### Product not visible on kiosk

Check:

1. `items.status` is active.
2. Category is active and visible on `kiosk`.
3. Product `channels` is null or contains `kiosk`.
4. Kiosk machine belongs to expected branch.
5. `item_branch_availability` is not unavailable for that branch.
6. Kiosk cache `kiosk.menu.branch.{branchId}` is invalidated or force refresh.
7. `CatalogChanged` or `ItemAvailabilityChanged` exists in `domain_events`.

Commands:

```bash
php artisan test tests/Feature/Services/Menu
php artisan test tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php
php artisan foodking:outbox:rescue
```

### Product visible on POS but not kiosk

Check:

1. Channel filters differ: `pos` vs `kiosk`.
2. Kiosk-specific category label/sort does not hide category.
3. Kiosk projection uses branch from machine token, not query input.
4. POS may have stale local items; kiosk may have offline snapshot.

Files:

- `MenuProjectionService`
- `KioskMenuService`
- `resources/js/store/modules/kioskMenu.js`
- `resources/js/components/admin/pos/PosComponent.vue`

### Symptom : POS et Kiosk affichent des catégories différentes

**Vérification (tinker)**

Comparer les IDs de catégories renvoyés par la projection POS (`MenuProjectionService::forChannel`) et par le menu kiosk (`KioskMenuService::build`). Adapter `$branchId` (et charger la branche pour `build`).

```bash
php artisan tinker
```

```php
$branchId = 1; // remplacer
$branch = \App\Models\Branch::findOrFail($branchId);

$pos = app(\App\Services\Menu\MenuProjectionService::class)->forChannel('pos', $branchId);
$kiosk = app(\App\Services\Kiosk\KioskMenuService::class)->build($branch);

$posIds = collect($pos['categories'] ?? [])->pluck('id');
$kioskIds = collect($kiosk['categories'] ?? [])->pluck('id');

$onlyPos = $posIds->diff($kioskIds);
$onlyKiosk = $kioskIds->diff($posIds);

dump([
    'category_ids_only_on_pos' => $onlyPos->values()->all(),
    'category_ids_only_on_kiosk' => $onlyKiosk->values()->all(),
]);
```

**Causes possibles**

1. **`channels` à NULL côté admin (item)** — Comportement historique : souvent interprété comme « visible partout », mais des filtres canal peuvent diverger selon la surface ou la phase de convergence catalogue. Voir plan §1.4 : warning log **`[catalog.channels-null]`**. Quand `config('catalog_v15.warnings.expose_to_admin_show')` est vrai, le détail est exposé dans la réponse **`GET /api/admin/items/{id}`** (tableau `warnings`).
2. **`item_branch_availability` manquant ou `is_available = false`** — Sémantique menu : **absence de ligne** ⇒ disponible par défaut pour la branche ; **ligne présente avec `is_available = false`** ⇒ l’article peut être exclu / masqué selon la projection. Sonde rapide :

```sql
SELECT * FROM item_branch_availability WHERE item_id = ? AND branch_id = ?;
```

3. **Feature flag `catalog_v15.unified_projection.kill_switch`** — Si **`config('catalog_v15.unified_projection.kill_switch')`** est truthy, POS peut rester sur la projection legacy alors que le kiosk utilise déjà le chemin unifié (ou l’inverse selon déploiement), ce qui diverge les jeux de catégories / items visibles. Contrôle :

```php
config('catalog_v15.unified_projection.kill_switch');
```

Variable d’environnement associée : **`FK_CATALOG_UNIFIED_PROJECTION_KILL_SWITCH`**.

**Procédure de recovery**

1. Identifier l’article ou la branche fautive via l’étape de vérification ci-dessus (écarts d’IDs de catégories, puis items si besoin).
2. Corriger la cause racine : renseigner explicitement `channels`, réparer / ajuster `item_branch_availability`, ou aligner le kill-switch et les flags `unified_projection` selon la politique de rollout.
3. Invalider le cache menu et notifier les surfaces : après correction DB, déclencher le **même pipeline** que les mutations catalogue admin — en pratique un **événement domaine** écouté par `InvalidateKioskMenuCacheOnCatalogChange` et `PersistCatalogChangedToOutbox` (ex. `event(new \App\Events\CategoryUpdated($categoryId));` ou autre événement pertinent selon l’entité modifiée). À défaut d’ID métier précis, recours minimal côté infra : `app(\App\Services\Menu\MenuSnapshot::class)->bump($branchId)` et `\Illuminate\Support\Facades\Cache::forget('kiosk.menu.branch.'.$branchId)`.  
   *Note ops :* `\App\Events\CatalogChanged` est agrégé via `CatalogChanged::fromMenuMutation()` dans le listener outbox ; **`CatalogChanged::dispatch(...)`** attend la signature constructeur `(string $entityType, int $entityId, string $changeType, ?int $branchId = null, …)` et **n’est pas** enregistré comme événement Laravel écouté à part entière — privilégier `CategoryUpdated`, `ItemAvailabilityChanged`, etc., comme le fait le code applicatif.
4. Rejouer le snippet tinker de vérification : les deux projections doivent alors présenter le même ensemble de catégories (IDs) pour la branche.

**Validation post-fix** — Sentinelles PHP : `tests/Feature/Menu/PosCategoryBranchScopeTest.php`. Quand la livraison 1.3 sera en place : `tests/js/posComponentMenuFiltering.spec.js`.

### Wizard not visible

Check:

1. A published `ItemWizardProfile` exists for the product.
2. Profile `branch_id_scope` matches actor/branch.
3. `ComposerProfileController::show` returns own/global profile, not latest foreign.
4. `ComposerProfileProjection` includes steps/options.
5. Kiosk/POS projection has `composer_profile`.
6. Browser is not using stale menu cache.

Commands:

```bash
php artisan test tests/Feature/Composer
php artisan test tests/Feature/ItemAttributeComposerResourceTest.php
php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php
```

### Photo not updated

Check:

1. Actor has global photo permission policy (`Admin`/`Tenant Admin` in V1).
2. `ItemController::changeImage` succeeded.
3. Stored media URL changed.
4. `CatalogChanged`/photo invalidation event exists.
5. Kiosk/POS refreshed projection or cache was invalidated.

Commands:

```bash
php artisan test tests/Feature/Catalog/ProductPhotoAuthzTest.php
php artisan test tests/Feature/Catalog/PhotoEndToEndKioskInvalidationTest.php
```

### Stock not synced

Check:

1. Is it product-level or choice-level stock?
2. Product-level: inspect `item_branch_availability`.
3. Choice-level: inspect `stock_levels` with stockable type/id.
4. `StockLevelChanged`, `ItemAvailabilityChanged`, or `CatalogChanged` exists in `domain_events`.
5. Kiosk cache key and menu snapshot bumped.
6. POS/Kiosk frontend shows disabled state; backend pricing rejects direct submit.

Commands:

```bash
php artisan test tests/Feature/Stock
php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php
npx vitest run tests/js/posRuptureUx.spec.js tests/js/kioskRuptureUx.spec.js
```

### KDS not receiving order

Check:

1. Order exists and branch is correct.
2. `OrderCreated` row exists in `domain_events`.
3. `dispatched_at` is not null, or `last_error` explains provider failure.
4. Queue worker processed `DispatchDomainEventsJob`.
5. KDS user/channel auth can subscribe to branch.
6. Fallback `KdsSyncService` can fetch the order without WebSocket.
7. No throttle/429 on KDS sync.

Commands:

```bash
php artisan test tests/Feature/KioskRealtimeBroadcastTest.php
php artisan test tests/Feature/SyncComprehensiveTest.php
php artisan foodking:outbox:rescue
php artisan foodking:outbox:retry-failed --since=1h
npx playwright test tests/e2e/c3-runtime-multi-surface.spec.js --repeat-each=2 --retries=0
```

### Queue number duplicate alert

Check:

1. DB unique constraint for branch/day/queue number path is present in current rollout.
2. Redis/cache lock driver is shared in production.
3. POS and Kiosk both use symmetric allocation/retry.
4. Idempotency key did not replay as a new order.
5. Inspect retry logs and failed inserts.

Commands:

```bash
php artisan test tests/Feature/ProdLike/ProdLikeConcurrencyTest.php
php artisan test --filter=QueueNumber
```

## Branch isolation checklist

| Actor | Expected behavior |
| --- | --- |
| Branch admin/user | Own branch data only |
| Branch user with null branch mutation | Scope to own branch, not all branches |
| Admin/Tenant Admin | Global or all branches where route allows |
| Kiosk machine | Machine branch only |
| KDS/POS user | Own branch private channel only |

Critical VA-SYS-07B tests:

- `DashboardBranchScopeMatrixTest`
- `AvailabilityToggleAuthzMatrixTest`
- `ProductPhotoAuthzTest`
- `ComposerAuthzMinimalTest`
- `AdminItemBranchAvailabilityProjectionTest`

## Final support rule

If a symptom is runtime sync related, inspect in this order:

1. Backend DB state.
2. Backend projection response.
3. `domain_events` row.
4. Queue/provider delivery.
5. Frontend dedupe/cache/fallback.
6. Browser DOM.

