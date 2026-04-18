# Gate Brief – TASK_V1_DATA_SOFTDELETE_001 – 2026-04-15

## Trigger
Two hard gate triggers per `human-gates.mdc`:
1. **Schema migration** — adding `deleted_at` column to 5 tables + creating `deletion_log` table (6 migrations total).
2. **`branch_id` isolation logic interaction** — `Order` and `FrontendOrder` use `BranchScope` global scope; adding `SoftDeletes` introduces a second global scope that must coexist correctly.

## Affected Subsystems
| Subsystem | Change |
|---|---|
| `orders` table | Add `deleted_at TIMESTAMP NULL` |
| `frontend_orders` table | Add `deleted_at TIMESTAMP NULL` |
| `order_items` table | Add `deleted_at TIMESTAMP NULL` |
| `branches` table | Add `deleted_at TIMESTAMP NULL` |
| `item_categories` table | Add `deleted_at TIMESTAMP NULL` |
| `deletion_log` table | **New table** — audit trail for deletions |
| `Order`, `FrontendOrder`, `OrderItem`, `Branch`, `ItemCategory` models | Add `SoftDeletes` trait |
| `app/Observers/SoftDeleteLogObserver.php` | New — logs deletions |
| Admin controllers | Add `trashed` + `restore` endpoints |

**NOT modified:** OrderService, FrontendOrderService (frozen), pricing, status transitions, events.

## Invariants at Risk
- **branch_id data isolation** — `BranchScope` and `SoftDeletingScope` stack on `Order` and `FrontendOrder`. Laravel applies global scopes additively. Analysis:
  - Default query: `WHERE branch_id = X AND deleted_at IS NULL` — correct
  - `withTrashed()`: removes `SoftDeletingScope`, keeps `BranchScope` → `WHERE branch_id = X` — correct (shows soft-deleted records for the user's branch only)
  - `onlyTrashed()`: `WHERE branch_id = X AND deleted_at IS NOT NULL` — correct
  - Admin (branch_id=0): BranchScope returns early (no filter) → `WHERE deleted_at IS NULL` or `withTrashed()` shows all — correct
  - **Risk:** Only if code uses `withoutGlobalScopes()` (removes ALL scopes including BranchScope). This pattern is NOT used in the codebase for Order/FrontendOrder queries. Mitigation: test suite explicitly validates this.

## Schema: tables modified

### `deleted_at` column (5 tables)
```sql
ALTER TABLE orders ADD COLUMN deleted_at TIMESTAMP NULL;
ALTER TABLE frontend_orders ADD COLUMN deleted_at TIMESTAMP NULL;
ALTER TABLE order_items ADD COLUMN deleted_at TIMESTAMP NULL;
ALTER TABLE branches ADD COLUMN deleted_at TIMESTAMP NULL;
ALTER TABLE item_categories ADD COLUMN deleted_at TIMESTAMP NULL;
```

### `deletion_log` table (new)
| Column | Type | Notes |
|---|---|---|
| id | bigint auto | PK |
| model_type | string | e.g. `App\Models\Order` |
| model_id | bigint unsigned | Polymorphic ID |
| actor_id | bigint unsigned, nullable | Who deleted |
| actor_type | string, nullable | User, KioskMachine, system |
| reason | text, nullable | Optional deletion reason |
| deleted_at | timestamp | When the soft-delete occurred |

Index: `(model_type, model_id)`.

### Tables already soft-deleted (NOT modified)
`users`, `items`, `item_variations`, `item_extras`, `item_addons` — already have `deleted_at` + `SoftDeletes` trait.

## Rollback Plan
| Level | Action | Time |
|---|---|---|
| **Migration rollback** | `php artisan migrate:rollback --step=6` drops `deletion_log` and removes `deleted_at` columns | < 1 min |
| **Trait removal** | Remove `use SoftDeletes;` from 5 models | < 5 min |
| **Code revert** | `git revert` | < 5 min |
| **Data safety** | Adding nullable `deleted_at` column has zero impact on existing data (all rows get NULL = not deleted) | Automatic |

## Options
1. **Approve** — all 6 migrations + SoftDeletes trait + observer + endpoints proceed.
2. **Approve partial** — migrations only (no UI restore endpoints, deferred to later cycle).
3. **Cancel cycle**.

## Approval
- [ ] Approved — option selected: ___
- [ ] Cancelled

**Approver:** _______________
**Date:** _______________
