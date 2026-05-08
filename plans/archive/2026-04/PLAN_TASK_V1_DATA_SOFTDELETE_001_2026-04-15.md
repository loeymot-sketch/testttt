# Plan – TASK_V1_DATA_SOFTDELETE_001 – 2026-04-15

## TASK_ID
TASK_V1_DATA_SOFTDELETE_001

## PRIMARY_MODEL
GPT-5.4 (complex — branch_id isolation interaction, schema migration)

## TEST_STRATEGY
`local-validation` — PHPUnit: soft-delete + BranchScope coexistence, restore, purge.

## PRIOR_CONTEXT — Codebase audit findings

The task lists 7 tables for soft-delete. **Actual state is different:**

### Already have SoftDeletes + `deleted_at`:
| Model | Table | BranchScope? |
|---|---|---|
| `User` | `users` | Yes (skipped in apply) |
| `Item` | `items` | No |
| `ItemVariation` | `item_variations` | No |
| `ItemExtra` | `item_extras` | No |
| `ItemAddon` | `item_addons` | No |

### Need SoftDeletes added (actual scope):
| Model | Table | BranchScope? | Risk |
|---|---|---|---|
| `Order` | `orders` | **Yes** | BranchScope + SoftDeletes coexistence |
| `FrontendOrder` | `frontend_orders` | **Yes** | Same risk |
| `OrderItem` | `order_items` | No | Low |
| `Branch` | `branches` | No | Low |
| `ItemCategory` | `item_categories` | No | Low |

### Does not exist in codebase:
- `ProductCategory` → is `ItemCategory`
- `ProductOption` → is `ItemVariation` (already has SoftDeletes)
- `Product` → is `Item` (already has SoftDeletes)

**Effective scope: 5 tables + 1 new table (`deletion_log`) = 6 migrations.**

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `database/migrations/*_add_soft_deletes_to_orders_table.php` | New — `deleted_at` on orders | Write | Yes (BranchScope on Order) | No |
| `database/migrations/*_add_soft_deletes_to_frontend_orders_table.php` | New — `deleted_at` on frontend_orders | Write | Yes (BranchScope on FrontendOrder) | No |
| `database/migrations/*_add_soft_deletes_to_order_items_table.php` | New — `deleted_at` on order_items | Write | No | No |
| `database/migrations/*_add_soft_deletes_to_branches_table.php` | New — `deleted_at` on branches | Write | No | No |
| `database/migrations/*_add_soft_deletes_to_item_categories_table.php` | New — `deleted_at` on item_categories | Write | No | No |
| `database/migrations/*_create_deletion_log_table.php` | New table | Write | No | No |
| `app/Models/Order.php` | Add `SoftDeletes` trait | Write | Yes | No |
| `app/Models/FrontendOrder.php` | Add `SoftDeletes` trait | Write | Yes | No |
| `app/Models/OrderItem.php` | Add `SoftDeletes` trait | Write | No | No |
| `app/Models/Branch.php` | Add `SoftDeletes` trait | Write | No | No |
| `app/Models/ItemCategory.php` | Add `SoftDeletes` trait | Write | No | No |
| `app/Models/DeletionLog.php` | New model | Write | No | No |
| `app/Observers/SoftDeleteLogObserver.php` | New observer — logs deletions | Write | No | No |
| `app/Providers/AppServiceProvider.php` | Register observer | Write | No | No |
| `app/Console/Commands/PurgeOldSoftDeletedCommand.php` | New command | Write | No | No |
| `app/Console/Kernel.php` | Register purge schedule (optional) | Write | No | No |
| `app/Http/Controllers/Admin/OrderController.php` | Add `trashed` list + `restore` endpoint | Write | No | No |
| `app/Http/Controllers/Admin/ItemCategoryController.php` | Add `trashed` list + `restore` endpoint | Write | No | No |
| `tests/Feature/SoftDelete/OrderSoftDeleteTest.php` | New tests | Write | No | No |
| `tests/Feature/SoftDelete/BranchScopeSoftDeleteTest.php` | New — BranchScope interaction tests | Write | No | No |
| `docs/SOFT_DELETE_POLICY.md` | New documentation | Write | No | No |

## SUBSYSTEMS_OFF_LIMITS
- `app/Services/OrderService.php` — frozen
- `app/Services/FrontendOrderService.php` — frozen
- Pricing logic, status transitions, events/outbox
- Tables already soft-deleted (users, items, item_variations, item_extras, item_addons)
- Vue components — UI admin toggle deferred to separate UI task (backend-first approach)

## INVARIANTS_AT_RISK
- **branch_id data isolation** — `Order` and `FrontendOrder` use `BranchScope`. Adding `SoftDeletes` adds a second global scope (`SoftDeletingScope`). Both scopes stack — Laravel applies them in registration order. Risk: `withTrashed()` removes the soft-delete scope but leaves BranchScope intact (correct). `withoutGlobalScope(SoftDeletingScope::class)` also leaves BranchScope intact (correct). The concern is queries that bypass both scopes inadvertently. Mitigation: explicit tests for scope stacking.
- **Frozen zones** — NOT touched. Models receive `SoftDeletes` trait only; services are not modified.

## GATE_CONDITIONS
- **Gate required: YES** (task says NO, but invariant rules override)
  - Trigger 1: Schema migration (6 migration files)
  - Trigger 2: `branch_id` isolation interaction (SoftDeletes on BranchScope-enabled models)
- Gate brief: `docs/gates/GATE_V1_DATA_SOFTDELETE_001_2026-04-15.md`

## Execution Steps

### E1 — Migrations (5 × `deleted_at` + 1 × `deletion_log`)

One migration per table adding `$table->softDeletes()`:
1. `orders`
2. `frontend_orders`
3. `order_items`
4. `branches`
5. `item_categories`

Plus `deletion_log` table:
- `id`, `model_type` (string), `model_id` (bigint), `actor_id` (nullable bigint), `actor_type` (nullable string), `reason` (nullable text), `deleted_at` (timestamp).
- Index on `(model_type, model_id)`.

### E2 — Add SoftDeletes trait to models

For each of `Order`, `FrontendOrder`, `OrderItem`, `Branch`, `ItemCategory`:
- Add `use SoftDeletes;`
- Add `protected $dates = ['deleted_at'];` if not already using casts.

### E3 — Deletion log observer

Create `app/Observers/SoftDeleteLogObserver.php`:
- On `deleting` event: insert row into `deletion_log` with `model_type`, `model_id`, current user as `actor_id`, optional `reason` from request context.
- Register on all 5 models (+ existing 5 that already have SoftDeletes for completeness).

Register in `AppServiceProvider::boot()`.

### E4 — Purge command

Create `app/Console/Commands/PurgeOldSoftDeletedCommand.php`:
- `foodking:purge-old-soft-deleted --days=365 [--dry-run]`
- Iterates over all SoftDeletes models, hard-deletes rows where `deleted_at < now()->subDays($days)`.
- Dry-run mode: outputs count per model without deleting.

### E5 — Admin restore endpoints

For critical models (Order, ItemCategory, Branch):
- Add `GET /admin/{resource}/trashed` — list soft-deleted records.
- Add `POST /admin/{resource}/{id}/restore` — restore a soft-deleted record.
- Permission-gated: only admin role.

### E6 — BranchScope + SoftDeletes interaction tests

Create `tests/Feature/SoftDelete/BranchScopeSoftDeleteTest.php`:
1. Staff branch 1 soft-deletes order → order invisible in default query.
2. Staff branch 1 queries `withTrashed()` → soft-deleted order visible (if policy allows).
3. Staff branch 2 queries `withTrashed()` → order NOT visible (BranchScope still active).
4. Admin (branch_id=0) queries `withTrashed()` → all soft-deleted orders visible.
5. `onlyTrashed()` respects BranchScope — staff sees only their branch's trashed records.

### E7 — General soft-delete tests

Create `tests/Feature/SoftDelete/OrderSoftDeleteTest.php`:
1. Delete order → `deleted_at` set, invisible in default query.
2. Restore order → `deleted_at` null, visible again.
3. `deletion_log` row created on delete.
4. Purge command: soft-deleted > 365 days → hard-deleted.
5. Purge command dry-run: no actual deletion.

### E8 — Documentation

Create `docs/SOFT_DELETE_POLICY.md`:
- Which tables are soft-deleted.
- Retention: 365 days default.
- Who can restore: Admin role only.
- Purge command usage.
- BranchScope interaction notes.

## SYMMETRY_NOTE
N/A — neither OrderService nor FrontendOrderService is modified. Both `Order` and `FrontendOrder` receive the `SoftDeletes` trait symmetrically.

## SCOPE_PRESSURE


## ESCALATION


## Audit Status
[ ] Pending
[ ] Passed — cycle closed
[ ] Gate opened — `docs/gates/GATE_V1_DATA_SOFTDELETE_001_2026-04-15.md`
