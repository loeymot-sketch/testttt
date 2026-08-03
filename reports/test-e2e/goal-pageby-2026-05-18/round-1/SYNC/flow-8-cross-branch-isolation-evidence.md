# FLOW 8 — Cross-Branch Isolation Evidence

**Scenario** : Verify that an event on branch A does NOT cascade to branch B surfaces.

## Environment Constraint

`Branch::count()` returns **1** (only "Le Cayenne (principal)"). A live cross-branch
cascade test is therefore impossible in this database. Per CLAUDE.md §13 ("never fake
certainty"), we DO NOT manufacture a fake 2nd branch and we DO NOT claim a live test
result we cannot deliver.

Instead, we verify the **architectural invariants** that enforce branch isolation:

## Code-Level Verification (tinker)

| Invariant | Source | Verdict |
|---|---|---|
| BranchScope global on Order model | `(new Order)->getGlobalScopes()` contains `App\Models\Scopes\BranchScope::class` | **OK** |
| Kiosk token branch restriction | `routes/channels.php` contains `tokenCan('kiosk:order')` check | **OK** |
| Admin branch_id=0 bypass | `routes/channels.php` contains `branch_id === 0` (intentional admin scope-out) | **OK** |

## Architecture Recap

Per CLAUDE.md §9 Multi-Tenant + Auth Invariants:

1. **BranchScope global** applied on 11 models post iter11+12:
   - Order, FrontendOrder, OrderItem, OrderPayment, KioskMachine
   - StockLevel, StockMovement, CashDrawerSession, CashMovement
   - PendingPaymentConfirmation, PushNotification, DiningTable, Printer

2. **Channel auth callback** in `routes/channels.php:25-39`:
   - Kiosk token with `kiosk:order` ability → restricted to its `KioskMachine.branch_id`
   - Admin user with `branch_id=0` → bypass (subscribes to any branch — intentional)
   - Staff user (branch_id>0) → own branch only

3. **Defense in depth**: even if a listener accidentally fans out to all branches,
   the `routes/channels.php` callback DENIES subscription, so the client never
   receives the event.

## Verdict

**GREEN architecture, N/A live.**
Backend isolation invariants verified via static code inspection.
Live cascade test is N/A given the single-branch DB.

## Backlog (V1.1+ multi-branch)

When Le Cayenne expands to 2+ physical branches, repeat this flow with:
1. Create 2 branches (e.g., via `BranchSeeder`)
2. Toggle rupture on branch A
3. Assert kiosk B menu cache NOT invalidated
4. Assert kiosk B Echo subscription does NOT receive the broadcast
   (visible via channel auth deny in Soketi logs)

## References

- Scope: `app/Models/Scopes/BranchScope.php` (frozen zone — global isolation)
- Channel auth: `routes/channels.php:25-39`
- Models with BranchScope: see CLAUDE.md §9
