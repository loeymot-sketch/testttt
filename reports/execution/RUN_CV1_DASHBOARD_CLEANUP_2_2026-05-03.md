# RUN — CV1-DASHBOARD-CLEANUP-2 (Phase 2, non-destructive)

Date: 2026-05-03  
TASK_ID: CV1-V2-REMAINING-MISSIONS-001  
Subtask: S05 (dashboard/menu cleanup phase 2)

## Scope

Apply a safe V1 cleanup for non-essential modules by hiding them from admin navigation only, while preserving code/routes/data until gate approvals.

## Changes applied

### 1) Central hidden-modules registry

File: `resources/js/config/v1-hidden-modules.js`

Added:

- `deliveryBoys`
- `onlineOrders`
- `tableOrders`
- `waiters`
- `diningTables`

Policy:

- Non-destructive cleanup only.
- Keep modules accessible by direct URL for forensic/debug.
- Keep schema untouched while DROP TABLE gates remain pending.

### 2) Sidebar URL mapping

File: `resources/js/components/layouts/backend/BackendMenuComponent.vue`

Added key->URL mapping so hidden registry effectively masks nav entries:

- `deliveryBoys` -> `delivery-boys`
- `onlineOrders` -> `online-orders`
- `tableOrders` -> `table-orders`
- `waiters` -> `waiters`
- `diningTables` -> `dining-tables`

### 3) Sentinel update

File: `tests/js/v1HiddenMenuModules.spec.js`

Extended expected hidden list and true-cases for the new modules.

## Validation

Command:

`npx vitest run tests/js/v1HiddenMenuModules.spec.js tests/js/catalogStudioRouting.spec.js tests/js/posWizardComposerAware.spec.js tests/js/runtimeSyncFlagsWiring.spec.js`

Result:

- **29/29 PASS**
- No regression on Studio, wizard runtime, or runtime sync flags.

## Gate compliance

- No schema migration.
- No DROP TABLE.
- No deletion of delivery/online/table-service code paths.
- Pending gates remain unchanged:
  - `GATE_DROP_TABLE_DELIVERY_BOYS_V1_2026-05-02`
  - `GATE_DROP_TABLE_ONLINE_ORDERS_V1_2026-05-02`
  - `GATE_DROP_TABLE_TABLE_SERVICE_V1_2026-05-02`

## Verdict

S05 cleanup phase 2: **PASS** (safe V1 nav cleanup completed, destructive actions deferred behind human gates).
