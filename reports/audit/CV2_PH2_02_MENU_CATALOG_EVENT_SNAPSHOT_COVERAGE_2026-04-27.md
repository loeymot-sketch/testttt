# CV2-PH2-02 - Menu Catalog Event Snapshot Coverage

Date: 2026-04-27
Train: B - Phase 2 Enhancement
Mode: IMPLEMENTATION + SENTINELS

## Verdict

`CV2_PH2_02_VERDICT: PASS`

The catalog/menu freshness gap is closed for the existing V1 event model without
introducing a new public outbox event contract. This avoids the event-contract
gate for this pass and keeps the change focused:

- catalog create/update/delete events now invalidate kiosk cache and bump
  `MenuSnapshot`;
- category sorting now emits category update events after commit;
- variation, extra, and addon mutations emit a full item refresh event;
- existing `ItemAvailabilityChanged` listeners continue to handle branch/global
  cache invalidation, snapshot bump, and outbox persistence.

## Files Modified

| File | Change |
| --- | --- |
| `app/Listeners/InvalidateKioskMenuCacheOnCatalogChange.php` | Injects `MenuSnapshot`; bumps branch snapshot when catalog cache is invalidated. |
| `app/Services/ItemCategoryService.php` | Emits `CategoryUpdated` events after category sort commits. |
| `app/Services/ItemVariationService.php` | Emits full item refresh after store/update/delete. |
| `app/Services/ItemExtraService.php` | Emits full item refresh after store/update/delete. |
| `app/Services/ItemAddonService.php` | Emits full item refresh after store/delete. |
| `tests/Feature/Menu/BumpMenuSnapshotListenerTest.php` | Adds catalog event cache/snapshot sentinels. |
| `tests/Feature/Menu/CatalogMutationSnapshotCoverageTest.php` | Adds variation/extra/addon refresh sentinels. |

## Invariants Checked

| Invariant | Result |
| --- | --- |
| Backend pricing SSOT | Preserved. No frontend price logic added. |
| Branch isolation | Preserved. Branch-scoped events affect one branch; global catalog events iterate branches explicitly. |
| Dispatch after commit | Preserved for category create/update/delete/sort through `DB::afterCommit`; composition services dispatch after successful DB write. |
| Outbox contract | No new public event type introduced; existing `ItemAvailabilityChanged` contract reused. |
| Order service symmetry | Not touched. |
| Frozen zones | No frozen zone touched. |

## Validation

```text
php -l app/Listeners/InvalidateKioskMenuCacheOnCatalogChange.php
php -l app/Services/ItemCategoryService.php
php -l app/Services/ItemVariationService.php
php -l app/Services/ItemExtraService.php
php -l app/Services/ItemAddonService.php
php -l tests/Feature/Menu/CatalogMutationSnapshotCoverageTest.php
```

Result: PASS.

```text
php artisan test tests/Feature/Menu/BumpMenuSnapshotListenerTest.php
```

Result: PASS, 4 tests.

```text
php artisan test tests/Feature/Menu/CatalogMutationSnapshotCoverageTest.php
```

Result: PASS, 3 tests.

```text
php artisan test tests/Feature/Menu
```

Result: PASS, 20 passed, 6 skipped. The skipped tests are the existing
SQLite/`JSON_CONTAINS` surface-filtering skips documented for MySQL CI.

```text
git diff --check -- scoped Train 2 files
```

Result: PASS.

## Remaining Limits

1. This does not create a new catalog event taxonomy. That remains a possible
   future improvement, but it requires the public event-contract gate.
2. POS/Kiosk consumer migration is still blocked until projection parity
   sentinels pass in `CV2-PH2-03`.
3. Dashboard write UI remains blocked until authz and category branch-scope
   decisions are closed.
4. Quantitative stock is not covered by this mission.

## Next Mission

Recommended next step: `CV2-PH2-03-MENU-PROJECTION-PARITY-SENTINELS`.

Reason: event/snapshot freshness is now covered; before changing consumers, the
repo needs a sentinel proving the canonical `MenuProjectionService` can represent
the shared POS/Kiosk fields without changing prices, item visibility, categories,
variations, extras, or addons unexpectedly.
