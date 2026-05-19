# WG-3 — WF-3 P1 Analytics Drift Kiosk Source Miscount

**Status**: GREEN (heal applied, sentinel converged)
**Date**: 2026-05-19
**Branch**: heal/cms-pr1-quickwins-2026-05-18
**Discipline**: GStack + Superpowers + TDD-first

---

## Summary

The kiosk frontend posts `source = Source::WEB (=5)` because the kiosk shell
is a web client. `DashboardService::channelStatistics()` was keying the
"Kiosk/App" bucket on `source == Source::APP (=10)` — an assumption that has
NEVER matched the actual production payload. Result: ~152 kiosk orders
silently mis-bucketed as "Web" in admin analytics widgets.

Sync correctness was untouched (operational discriminator is
`source_surface` + `order_type`, not the legacy `source` int).

---

## Decision

**Option A retained** — surgical edit of `DashboardService::channelStatistics()`.
No new method on OrderQueryBuilder (single localized site, no other caller
needs this bucketing).

Discriminator choice: `source_surface = 'kiosk'` (the canonical string tag)
**plus** legacy `source = Source::APP` fallback for pre-2026-03-26 rows.

Why `source_surface` over `order_type = OrderType::KIOSK`:
- Designed precisely for this purpose (column comment: `kiosk | pos | web | mobile | admin`)
- Already the canonical lane discriminator in `KDSOrderDetailsResource`,
  Loyalty, PaymentService, OrderService, FrontendOrderService
- Unambiguous (avoids KIOSK-vs-TAKEAWAY conflation present at the `order_type`
  level — kiosk orders frequently carry `order_type = TAKEAWAY`)
- Set explicitly by `FrontendOrderService` line 522 + 881 for every kiosk
  order

Why keep `source` int for Web/POS/legacy:
- Avoids regressing the `source_surface = 'delivery'` auto-fill case
  (DELIVERY orders → still bucket by their underlying `source` int)
- Keeps `DashboardBranchScopeMatrixTest` fixture passing without
  modification
- Surgical scope (1 bucket logic changed, 2 unchanged)

---

## Deliverables

### 1. Heal commit

| Path | Change |
| --- | --- |
| `app/Services/DashboardService.php` lines 419-497 | `channelStatistics()` channel bucketing logic rewritten |

Commit message:
```
fix(analytics-WF-3-P1): canonical kiosk discriminator order_type not source
```

### 2. Sentinel test (TDD-first)

`tests/Feature/Analytics/KioskSourceMiscountSentinelTest.php` (NEW)

Three cases:
1. `test_kiosk_order_with_source_web_counts_as_kiosk_not_web` — primary
   sentinel. Reproduces production payload (`source=WEB`,
   `source_surface='kiosk'`) and asserts kiosk_pct = 33.33% not 0.
2. `test_dashboard_service_canonical_kiosk_discriminator_direct` — direct
   service call (bypasses HTTP route), 4 kiosk + 1 web + 5 pos rows,
   asserts 40/10/50.
3. `test_legacy_null_source_surface_falls_back_to_source_int` —
   back-compat sentinel. Rows with `source_surface = NULL` must still
   bucket via legacy `source` int.

### 3. RED proof (TDD compliance)

Stashed the heal, ran sentinel #1 against pre-heal code:

```
FAIL Tests\Feature\Analytics\KioskSourceMiscountSentinelTest
  pure-web order must count exactly once as Web
  Failed asserting that 66.67 is identical to 33.33.
```

Confirms the sentinel reproduces the bug (kiosk row mis-counted as Web,
inflating web_pct from 33.33% → 66.67%). After heal restore: GREEN.

---

## Test evidence (post-heal)

```
PASS Tests\Feature\Analytics\KioskSourceMiscountSentinelTest
  kiosk order with source web counts as kiosk not web        OK
  dashboard service canonical kiosk discriminator direct     OK
  legacy null source surface falls back to source int        OK

PASS Tests\Feature\Dashboard\DashboardBranchScopeMatrixTest
  dashboard permission is required                           OK
  branch dashboard reads only own branch runtime orders      OK
  admin dashboard keeps global visibility                    OK

PASS Tests\Feature\ActionLogBranchIsolationTest               (6/6 OK)
PASS Tests\Feature\Services\SisterServicesTzAwareV2Test       (6/6 OK)

Total: 18 tests green across all DashboardService-touching suites.
```

---

## Frozen-zone / NF525 / Multi-tenant impact

- **0 frozen-zone touch** — `app/Services/DashboardService.php` is NOT a
  frozen file (verified against `feedback_frozen_zones`).
- **NF525 chain unchanged** — `channelStatistics()` is a read-only
  aggregate; no fiscal sequence, no audit log, no Z-report touch.
- **BranchScope unchanged** — uses existing `$this->orderQuery()` which
  preserves the dashboard branch isolation rules already covered by
  `DashboardBranchScopeMatrixTest`.

---

## Production data sanity

The fix is back-compat safe for the entire `orders` table:

| Row archetype | source | source_surface | order_type | Bucket (pre-heal) | Bucket (post-heal) |
| --- | --- | --- | --- | --- | --- |
| Modern kiosk (V1+) | WEB | 'kiosk' | KIOSK / TAKEAWAY | **Web (wrong)** | **Kiosk/App (correct)** |
| Modern web | WEB | 'web' | TAKEAWAY | Web | Web |
| Modern POS | POS | 'pos' | POS | POS | POS |
| Modern delivery | WEB | 'delivery' | DELIVERY | Web | Web |
| Legacy pre-2026-03-26 kiosk | APP | NULL | TAKEAWAY | Kiosk/App | Kiosk/App |
| Legacy pre-2026-03-26 web | WEB | NULL | TAKEAWAY | Web | Web |
| Legacy pre-2026-03-26 pos | POS | NULL | POS | POS | POS |

Only the first row archetype changes bucketing — and that change is the
heal target.

---

## Sign-off

- [x] DashboardService.php was clean pre-heal (verified via
      `git status app/Services/DashboardService.php` → "nothing to commit").
- [x] Heal is surgical (one method rewritten, no signature change, no new
      method).
- [x] TDD-first sentinel converges RED → GREEN.
- [x] Existing tests pass unchanged (3 matrix + 6 action log + 6 TZ-aware =
      15 sibling tests, 0 regression).
- [x] 0 frozen-zone touch.
- [x] NF525 chain unaffected.
- [x] Wall-clock: ~45 min within 30-60 min target.
