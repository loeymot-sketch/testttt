# FK-REM-T0-GOVERNANCE-UNLOCK - Audit Report - 2026-04-27

TASK_ID: `FK-REM-T0-GOVERNANCE-UNLOCK-2026-04-27`  
Mode: orchestration + audit, staging hygiene only  
Verdict: PASS FOR STAGING HYGIENE, PRODUCT TRAINS MUST STILL RESPECT GATES

## 1. Why This Train Exists

The user requested a complete final pass across:

- POS/caisse.
- Kiosk/borne.
- KDS.
- Queue numbers.
- Payment simulation.
- Dashboard management.
- Category/product/stock management.
- Delivery fee by distance.
- Legacy Bangladesh/demo cleanup.
- Full synchronization and final audit.

The repository cannot safely accept a large A-to-Z product patch in one pass because `OrderService.php` and `FrontendOrderService.php` are frozen P0 files and are currently staged/unstaged at the same time.

## 2. Initial Blocking Evidence

Command:

```text
bash .cursor/hooks/safety-check.sh
```

Observed result:

```text
[safety-check] Checking frozen zones...
[HALT] Frozen zone staged: app/Services/OrderService.php - gate clearance required. See docs/gates/
```

Impact:

- No new broad backend/order edit should be made while the frozen staging is ambiguous.
- Queue allocator, handover, stock decrement, payment finalization and order lifecycle changes depend on this area.
- Non-product reports/plans/gate artifacts can still be written.

## 2.1 Resolution Applied

Applied Option B from `docs/gates/GATE_FROZEN_ORDERSERVICE_UNLOCK_2026-04-27.md`.

Action:

```text
git restore --staged app/Services/OrderService.php app/Services/FrontendOrderService.php
```

Result:

- The file contents were not reverted.
- The frozen changes remain in the working tree.
- The Git index no longer stages the frozen files.
- `bash .cursor/hooks/safety-check.sh` now passes.

Validation:

```text
[safety-check] Frozen zones: OK
[safety-check] PHP syntax: OK
[safety-check] Passed. Proceed with execution.
```

## 3. Existing Human Decisions Already Found

`docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md` contains the 2026-04-27 addendum with:

- `HG-FROZEN-ORDER-HUNKS-TRAIN-A-2026-04-27`: approved strict hunks in `OrderService.php` and `FrontendOrderService.php` for D-M13 queue allocation, POS walk-in customer, delivery-fee backend authority and POS/Kiosk parity.
- `HG-POS-WALKIN-CUSTOMER-V1`: approved hidden/branch-safe walk-in customer.
- `HG-DELIVERY-FEE-V1`: approved delivery fee rule.
- `HG-KIOSK-LOCKED-CUSTOMER-SURFACE`: approved no kiosk admin route, hidden tap or navigation to admin/caisse.
- `HG-DASHBOARD-AFTER-TRAIN-A`: dashboard full control plane remains after Train A/D-M13.

This means the business decision is mostly clear. The technical blocker is staging hygiene and safety-hook enforcement, not missing business intent.

## 4. Frozen Diff Classification

### `OrderService.php`

Classified as legitimate Train A/D-M13 scope:

- Remove timestamp queue fallback.
- Add retry on DB duplicate queue violation.
- Use business-day queue scope.
- Preserve server-side total recalculation.
- Seal POS quotes at commit.
- Harden branch checks.
- Avoid duplicate side effects on no-op status changes.

Risk:

- This is high-risk P0 because queue, pricing, fiscal and lifecycle live together in one large service.
- Needs parity against `FrontendOrderService.php`.

### `FrontendOrderService.php`

Classified as legitimate Train A/D-M13 scope:

- Remove timestamp queue fallback.
- Add queue retry helper.
- Use business-day queue scope.
- Seal kiosk quotes at commit.
- Preserve kiosk fiscal rule: payment confirmation does not allocate POS fiscal sequence.

Risk:

- Kiosk order flow must stay branch-safe and quote-bound.
- Any queue change must remain symmetric with POS.

## 5. Demands Already Implemented In Previous Pass

These items are already implemented and validated in previous reports:

| Demand | Status | Evidence |
| --- | --- | --- |
| Kiosk has no admin access | Done | `kioskRoutes.js`, `KioskLoginComponent.vue`, `SettingResource.php` |
| Remove kiosk/POS false connection banner | Done | `ConnectionStatusBanner.vue`, POS/kiosk integration |
| POS should not require manual Client ID | Done | `WalkInCustomerResolver`, `PosController`, `PosOrderRequest` |
| Delivery fee by distance | Done | `DeliveryFeeService`, POS delivery distance normalization |
| Active branch selector only | Done | `BackendNavbarComponent.vue` |
| Train 1 queue/business-day direction | Mostly done, frozen files unstaged for mission-by-mission review | D-M13 diffs + tests |
| Train 2 PH2-01/02/03 | Done | Data ownership, catalog events, projection parity |

## 6. Demands Not Yet Finished

| Demand | Current state | Required train |
| --- | --- | --- |
| Dashboard product/category/price/offer control plane | Not fully delivered | T2 Dashboard |
| Quantitative stock with atomic decrement | Not delivered | T3 Stock V2 |
| POS live board showing kiosk/POS orders | Not delivered | T4 Order ops |
| Explicit handover/remise client | Not delivered | T4 Order ops |
| Full KDS/OSS live lifecycle validation | Partial existing, not globally validated | T4 + T6 |
| Legacy Bangladesh/demo cleanup | Non-destructive UI filtering + dry-run command + EUR seeder/runtime cleanup | T5 Cleanup |
| Google Maps final operational validation | Fallback implemented, live key not validated | T6 Hardware/E2E |
| Full Playwright hardware-like final pass | Not run in this T0 | T6 |

## 7. Immediate Decision Applied

Applied action: Option B from the gate brief.

Meaning:

- Non-destructively unstage `app/Services/OrderService.php` and `app/Services/FrontendOrderService.php`.
- Keep file content untouched.
- Restage only the mission-owned hunks/files before each train.
- Re-run `bash .cursor/hooks/safety-check.sh`: PASS.

This avoids losing code and makes every next implementation auditable.

## 8. Audit Verdict

VERDICT: PASS FOR T0.

Allowed right now:

- Reports.
- Plans.
- Gate briefs.
- Non-frozen product trains.
- Frozen product trains only when their hunks are restaged mission-by-mission under the existing gate/addendum.

Not allowed safely right now:

- New stock/order/queue/handover backend patches outside the approved strict hunk scope.
- New migrations touching order/stock lifecycle without their own gate.
- Broad dashboard mutations that silently depend on order state without tests.
