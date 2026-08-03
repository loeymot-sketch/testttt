# RUN_V14_T19_POS_TABLE_FLOORPLAN_2026-04-20

EXECUTE_DELEGATION: foodking-complex-implementer

## Status

PASSED

## DB schema

- `dining_tables` extended with:
  - `occupancy_status` (`string(16)`, nullable, default `free`)
  - `occupied_order_id` (`unsignedBigInteger`, nullable)
  - `occupied_at` (`timestamp`, nullable)
  - composite index `dining_tables_branch_occupancy_idx` on (`branch_id`, `occupancy_status`)
  - backfill executed: `occupancy_status = 'free'` where null
- new table `dining_table_audit_logs`:
  - `id`, `branch_id`, `user_id`, `action`
  - `source_table_id`, `target_table_id`, `order_id`
  - `metadata` JSON nullable
  - `created_at` only

## Endpoints

- `GET /api/admin/pos/floorplan/state`
- `POST /api/admin/pos/floorplan/transfer`
- `POST /api/admin/pos/floorplan/{tableId}/assign`
- `POST /api/admin/pos/floorplan/{tableId}/release`

## Audit log structure

- one row per successful `occupy`, `release`, `transfer`
- branch-scoped fields: `branch_id`, `user_id`, `action`
- table traceability: `source_table_id`, `target_table_id`
- order linkage: `order_id`
- extension slot: `metadata` JSON
- immutable timestamp column: `created_at` only (`$timestamps = false`)

## Tests

- Focused backend T19 + dine-in/POS sentinels:
  - `tests/Feature/Pos/FloorplanControllerTest.php`: `8/8` passed
  - `tests/Feature/PosParkedOrderTest.php`: passed in focused run
  - `tests/Feature/PosDineInServerGateTest.php`: passed in focused run
- Focused Vitest:
  - `tests/js/posFloorplan.spec.js`: `4/4` passed
  - `tests/js/PosComponent.spec.js`: `1/1` passed
  - `tests/js/posDineInFlag.spec.js`: `11/11` passed
  - `tests/js/posParked.spec.js`: `6/6` passed
  - focused Vitest total: `22/22` passed
- Broad regression command:
  - `php artisan migrate --force`: nothing to migrate
  - `php artisan test --filter='Floorplan|DiningTable|Pos|Order'`: `317 passed`, `3 failed`, `3 skipped`
  - tolerated pre-existing failures confirmed unchanged:
    - `Tests\Feature\DispatchAfterCommitTest` (`2`)
    - `Tests\Feature\Orders\OrderAllergenSnapshotComposedTest` (`1`)

## Files created/modified

- `database/migrations/2026_04_20_210000_extend_dining_tables_occupancy.php`
- `database/migrations/2026_04_20_210100_create_dining_table_audit_logs_table.php`
- `app/Models/DiningTable.php`
- `app/Models/DiningTableAuditLog.php`
- `app/Services/DiningTableService.php`
- `app/Http/Controllers/Admin/Pos/FloorplanController.php`
- `app/Http/Requests/Admin/Pos/FloorplanTransferRequest.php`
- `routes/api.php`
- `resources/js/store/modules/posFloorplan.js`
- `resources/js/store/index.js`
- `resources/js/components/admin/pos/FloorplanComponent.vue`
- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/router/modules/posRoutes.js`
- `resources/js/languages/en.json`
- `resources/js/languages/fr.json`
- `resources/js/languages/ar.json`
- `tests/Feature/Pos/FloorplanControllerTest.php`
- `tests/js/posFloorplan.spec.js`
- `reports/execution/RUN_V14_T19_POS_TABLE_FLOORPLAN_2026-04-20.md`

## Invariant-sensitive checks

- no `OrderService` reference added in `DiningTableService`, `FloorplanController`, or T19 feature test
- `transfer()` updates `orders.dining_table_id` directly via `Order::where(...)->update(...)`
- branch isolation preserved through `BranchScope` + explicit `branch_id` filters
- transfer cross-branch sentinel returns `404`
- release keeps `orders.dining_table_id` untouched
- existing `DiningTableController` CRUD not modified
- `PosComponent.vue` hook kept isolated to a single top-bar button outside parked/search/payment zones

## Residual TODOs

- deferred real-time broadcast for floorplan refresh (`Pusher`/Echo), polling `15s` kept for V1
- future `reserved` lifecycle for customer reservation workflows (visual status only in T19)
