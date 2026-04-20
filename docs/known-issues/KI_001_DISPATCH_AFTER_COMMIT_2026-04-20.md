# KI-001 — `dispatch-after-commit` invariant broken on broadcast events

**Status** : OPEN — awaiting human gate C9 (extended) for V5 #1 remediation
**Severity** : HIGH (production data inconsistency, no data loss)
**Discovered** : 2026-04-20 by V4 #8 sentinel test (`P11_DISPATCH_AFTER_COMMIT_AUDIT`)
**Extended** : 2026-04-20 by V5 #2 (static grep) + V5 #3 (runtime data provider)
**Tracking** : `tasks/execute-2026-04-20/V5_01_P11_DISPATCH_AFTER_COMMIT_REMEDIATION.md`

---

## TL;DR

Three broadcast events (`OrderCreated`, `OrderStatusChanged`, `ItemAvailabilityChanged`) are dispatched **immediately** during a database transaction, instead of **after the transaction commits**. If the transaction subsequently rolls back, the broadcast already left the application — KDS/OSS/Kiosk surfaces show "ghost" orders/status changes that don't exist in the database.

## Affected events

| Event class | File | Implements `ShouldDispatchAfterCommit` ? |
|---|---|---|
| `App\Events\OrderCreated` | `app/Events/OrderCreated.php` | ❌ NO |
| `App\Events\OrderStatusChanged` | `app/Events/OrderStatusChanged.php` | ❌ NO |
| `App\Events\ItemAvailabilityChanged` | `app/Events/ItemAvailabilityChanged.php` | ❌ NO |

## Other broadcast events under static surveillance (V7 #1)

**Audit** : 2026-04-20 (`V7_01_P11_INVARIANT_4_OF_6_EXTEND_ITEM_CATEGORY`). The five lifecycle event classes below were read in full. None implement `ShouldBroadcast` or `ShouldBroadcastNow`; each is a plain `Dispatchable` class with **no** parent class beyond the default. They are therefore **not** broadcast (Pusher cross-surface) events for the purposes of the `dispatch-after-commit` / ghost-UI concern described in this KI.

| Event | Path | `ShouldBroadcast` / `ShouldBroadcastNow` ? | `ShouldDispatchAfterCommit` ? | V7 #1 decision |
|---|---|---|---|---|
| `ItemCreated` | `app/Events/ItemCreated.php` | No | N/A (not broadcast) | **SKIP** — out of scope for invariant 4/6 broadcast list |
| `ItemDeleted` | `app/Events/ItemDeleted.php` | No | N/A | **SKIP** |
| `CategoryCreated` | `app/Events/CategoryCreated.php` | No | N/A | **SKIP** |
| `CategoryUpdated` | `app/Events/CategoryUpdated.php` | No | N/A | **SKIP** |
| `CategoryDeleted` | `app/Events/CategoryDeleted.php` | No | N/A | **SKIP** |

**Verdict** : **CLOSED — NO_OP** for V7 #1. No change to `scripts/check-invariants.sh` from this wave: do not add non-broadcast events to the broadcast surveillance regex. (`ItemUpdated.php` does **not** exist in the repo — never add it to patterns or KI lists.)

**Dispatch grep (V7 #1)** : `ItemCreated::dispatch` / `ItemDeleted::dispatch` / `CategoryCreated::dispatch` / `CategoryUpdated::dispatch` / `CategoryDeleted::dispatch` — **no matches** under `app/` at audit time.

If any of these classes later gain `implements ShouldBroadcast` without `ShouldDispatchAfterCommit`, extend invariant 4/6 and this KI per `V5_01_P11_DISPATCH_AFTER_COMMIT_REMEDIATION.md`.

---

### ⚠️ CORRECTIVE NOTE — Pre-V8 audit (2026-04-20, post-V7)

The V7 #1 conclusion that these 5 events are "orphan / never dispatched" is **partially incorrect**. The orchestrator review prior to launching V8 found:

1. **All 5 events ARE bound to listeners** in `app/Providers/EventServiceProvider.php` lines 116-130:
   - `ItemCreated` / `ItemDeleted` / `CategoryCreated` / `CategoryUpdated` / `CategoryDeleted` → `InvalidateKioskMenuCacheOnCatalogChange::class`
2. **All 5 events ARE dispatched** but via the helper pattern `event(new XxxCreated($id))` (NOT `XxxCreated::dispatch($id)`), at:
   - `app/Services/ItemService.php:182` `event(new ItemCreated(...))`
   - `app/Services/ItemService.php:306` `event(new ItemDeleted(...))`
   - `app/Services/ItemCategoryService.php:119` `event(new CategoryCreated(...))`
   - `app/Services/ItemCategoryService.php:151` `event(new CategoryUpdated(...))`
   - `app/Services/ItemCategoryService.php:186` `event(new CategoryDeleted(...))`
3. **All 5 dispatch sites are wrapped in `DB::afterCommit(...)`** per audit `reports/review/VERIFY_14_SYNC_CROSS_SURFACE_2026-04-20.md` (manual after-commit pattern, not via the `ShouldDispatchAfterCommit` contract).

**Implications**:
- These events are **NOT orphan** — removing them would break kiosk menu cache invalidation cross-surface.
- The `dispatch-after-commit` invariant is **already enforced manually** via `DB::afterCommit` wrapping. They are NOT subject to the bug described in this KI as long as the manual wrap is preserved.
- The V5 #2 grep pattern (and V7 #1 verification grep) has a **3rd blind spot**: it does NOT capture the `event(new ...)` helper pattern. Cycle V8 #1 (`P11_INVARIANT_4_OF_6_EVENT_HELPER_PATTERN`) addresses this.

**SKIP decision is therefore preserved BUT for a different reason**: not because they are orphan (they aren't), but because they are already correctly wrapped `DB::afterCommit` (manual contract). If any of the 5 dispatch sites were to be unwrapped (regression risk), invariant 4/6 should detect it — hence V8 #1.

## V8 #1 — invariant 4/6 extended to event() helper pattern

**Audit** : 2026-04-20. The `scripts/check-invariants.sh` invariant 4/6 now also catches `event(new <BroadcastEvent>(...))` and `Event::dispatch(new <BroadcastEvent>(...))` patterns, in addition to the static `XxxCreated::dispatch(...)` patterns covered by V5 #2.

**Confirmed-wrapped sites** (helper call on its own line inside `DB::afterCommit`, so mono-line grep does not see `afterCommit` on the same line) :

- `app/Services/ItemService.php:182,306`
- `app/Services/ItemCategoryService.php:119,151,186`

These sites are correctly inside `DB::afterCommit(...)` per `VERIFY_14_SYNC_CROSS_SURFACE` audit. The check monitors that the wrap is preserved (removing the closure without restoring after-commit semantics would surface as new 4/6 hits).

## V9 #1 — invariant 4/6 multi-line `DB::afterCommit` detection

**Audit** : 2026-04-20. The `scripts/check-invariants.sh` invariant 4/6 now uses an `awk`-based post-filter that inspects the 5 lines preceding each grep hit. If `DB::afterCommit(` is found in that window, the hit is considered properly wrapped and is removed.

**Consequence** : the 5 `// allow: wrapped DB::afterCommit (V8 #1)` comments added to `ItemService.php` / `ItemCategoryService.php` in V8 #1 have been removed. The detection is now structural rather than convention-based.

**Trade-offs** :
- ✅ No more allowlist drift risk
- ✅ Future dispatch sites wrapped in `DB::afterCommit` are auto-detected (no manual annotation needed)
- ✅ Removing the wrap correctly raises a new invariant 4/6 hit
- ⚠️ Window size = 5 lines. Wraps spanning >5 lines (multiline `use (...)` lists) would not be detected. Increase the window if needed in future cycles.
- ⚠️ The check is per-line, not per-AST: a `DB::afterCommit(` appearing in a comment 3 lines above would create a false negative. Acceptable trade-off given current codebase patterns.

**Baseline preserved** : 8 hits (3 × `OrderCreated::dispatch` + 3 × `OrderStatusChanged::dispatch` in `OrderService.php` + 1 × `OrderCreated::dispatch` + 1 × `OrderStatusChanged::dispatch` in `FrontendOrderService.php`). To be resolved by V5 #1 remediation (gate C9).

## V11 #1 — façade pattern `Event::dispatch(string|class-string)` audit

**Audit** : 2026-04-20. Exhaustive grep across `app/` for the façade pattern `Event::dispatch(...)` (event passed by name/class-string, without `new`):

```bash
grep -rn "Event::dispatch(" app/ --include='*.php' | grep -v "::dispatch(new "
```

**Result** : **0 matches**. The façade pattern is not used in this codebase. The 3 patterns currently surveilled by `scripts/check-invariants.sh` invariant 4/6 (FQN static, short-name static, helper `event(new ...)`/`Event::dispatch(new ...)`) cover the entire dispatch surface for broadcast events.

**Verdict** : **CLOSED — NO_OP**. No change to `scripts/check-invariants.sh`.

**Lesson** : V7 #1 audited "are events dispatched at all" but missed the helper pattern. V11 #1 audits "is there a 4th pattern we don't surveil" — answered : no. The 3-pattern coverage is complete for this codebase as of 2026-04-20. Re-audit recommended after any major upgrade (Laravel version bump, EventBus refactor).

## Confirmed call-sites (from `scripts/check-invariants.sh -v` invariant 4/6 after V5 #2 hardening)

Cross-checked against `bash scripts/check-invariants.sh -v` on 2026-04-20 (8 hits; exit 1).

| File | Line | Pattern |
|---|---|---|
| `app/Services/OrderService.php` | 541 | `OrderCreated::dispatch(...)` |
| `app/Services/OrderService.php` | 961 | `OrderCreated::dispatch(...)` |
| `app/Services/OrderService.php` | 1266 | `OrderCreated::dispatch(...)` |
| `app/Services/OrderService.php` | 1423 | `OrderStatusChanged::dispatch(...)` |
| `app/Services/OrderService.php` | 1478 | `OrderStatusChanged::dispatch(...)` |
| `app/Services/OrderService.php` | 1575 | `OrderStatusChanged::dispatch(...)` |
| `app/Services/FrontendOrderService.php` | 842 | `OrderCreated::dispatch(...)` |
| `app/Services/FrontendOrderService.php` | 848 | `OrderStatusChanged::dispatch(...)` |

## Production impact

- **KDS** (Kitchen Display System) : a "ghost" order may appear and disappear in the kitchen UI when an order creation transaction rolls back. Cooks may start prep on a non-existent order.
- **OSS** (Order Status Screen / customer display) : a status update may flash to "ready" then revert, confusing customers.
- **Kiosk** : if a kiosk receives `ItemAvailabilityChanged` for a rolled-back availability toggle, the cart may incorrectly prune lines.
- **Severity proportional to**: % of order/availability transactions that roll back. In normal ops < 0.5%, but spikes during DB issues, fiscal Z conflicts, optimistic lock conflicts.

**No data loss** — the database remains consistent. Only the real-time UI is temporarily inconsistent.

## Active sentinels

| Sentinel | File | Type | Current state |
|---|---|---|---|
| Runtime | `tests/Feature/DispatchAfterCommitTest.php` | PHPUnit data provider × 3 events | 3 ✔ commit + 3 ✘ rollback (rouge en CI volontaire) |
| Static | `scripts/check-invariants.sh` invariant 4/6 | grep | FAIL (8 hits, exit 1) — NOT in CI workflows, local only |

**Both sentinels MUST stay active and red until V5 #1 remediation lands.** Do not :

- Add `@group dispatch_after_commit_invariant` exclusion to CI without orchestrator approval
- Mark tests as `@incomplete` or `@skip`
- Add `// allow:` comments to the call-sites above to silence the static check
- Disable invariant 4/6 in `check-invariants.sh`

## Remediation plan (V5 #1 — awaiting human gate C9 extended)

See `tasks/execute-2026-04-20/V5_01_P11_DISPATCH_AFTER_COMMIT_REMEDIATION.md`.

**Recommended strategy A** : add `implements ShouldDispatchAfterCommit` to the 3 event classes (1-line change each).

```php
// app/Events/OrderCreated.php (and 2 others)
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class OrderCreated implements ShouldDispatchAfterCommit
{
    // ... existing code unchanged ...
}
```

**Why human gate** : touches frozen-zone-adjacent event classes. Although the change is minimal (1 line + 1 use statement per file), the contract change affects EVERY existing dispatch call-site simultaneously. Risk of side effects in queue workers, listeners ordering, and the broadcast envelope contract.

**Why strategy A is preferred over strategy B** (refactor every caller to `dispatchAfterCommit()`) :

- A : 3 files × 2 lines each = 6 lines total. Fixes ALL existing AND future call-sites.
- B : 8+ call-sites × 1 line each = 8+ lines. Easy to forget a future caller.

## Workarounds (none recommended for production)

There is **no clean dev workaround**. The only mitigation is to wrap every dispatch in a manual `DB::afterCommit(fn() => Event::dispatch(...))`, which is exactly what `ShouldDispatchAfterCommit` does automatically.

If a hotfix is needed before V5 #1 lands, the surgical patch is to add `implements ShouldDispatchAfterCommit` to the most critical event (`OrderCreated`) only. This still requires the same human gate.

## Detection in production

Logs to monitor (added by V4 #9 `P13_FISCAL_TIMING_METRICS` and V4 #10 `P13_KDS_409_OBSERVABILITY`) :

- `[FISCAL_TIMING]` with `outcome=failure` followed by no compensating broadcast
- `[KDS_409]` correlated with order_id that has only a transient lifetime in the DB

**Recommended Grafana/SIEM alert** : count of `[KDS_409]` events with `current_status` mismatching expected initial state — proxy for ghost orders.

## Closure criteria

- [ ] V5 #1 remediation merged
- [ ] `vendor/bin/phpunit --filter DispatchAfterCommitTest` → 6/6 ✔
- [ ] `bash scripts/check-invariants.sh` → 4/6 OK (0 hits)
- [ ] This KI updated to status `RESOLVED` with merge SHA + date
- [ ] 7 days of production logs without ghost-order patterns
