# Codex Fallback Final Audit After Claude P2 Cleanup — 2026-04-28

Date: 2026-04-28  
Auditor/implementer: Codex fallback  
Context: Claude Opus 4.7 returned `PASS_PROCEED_HARDWARE_UAT` with two non-blocking P2 findings. Codex closed the P2 findings and reran targeted validation.

---

## Verdict

`AUDIT_FALLBACK_VERDICT: PASS_PROCEED_HARDWARE_UAT`

`RELEASE_DECISION: HOLD_FOR_HARDWARE_UAT`

`P0_OPEN: 0`

`P1_OPEN: 0`

`P2_CLAUDE_FINDINGS_OPEN: 0`

The code remains ready for Hardware UAT. Go-live remains blocked only by physical/staging/human signoff, not by a known local software P0/P1.

---

## Corrections Applied

### P2-1 — Legacy POS/table tax rounding symmetry

Files:

- `app/Services/OrderService.php`

Changes:

- `app/Services/OrderService.php:431` now rounds legacy POS order item tax amount to 2 decimals.
- `app/Services/OrderService.php:1165` now rounds legacy table order item tax amount to 2 decimals.

Reasoning:

- `FrontendOrderService.php:363` already rounded the equivalent tax calculation.
- Rounding both legacy `OrderService` paths removes cent-level drift without changing pricing authority or request trust.

### P2-2 — Legacy kiosk bulk insert timestamps

Files:

- `app/Services/FrontendOrderService.php`

Changes:

- `app/Services/FrontendOrderService.php:386` adds `created_at => now()`.
- `app/Services/FrontendOrderService.php:387` adds `updated_at => now()`.

Reasoning:

- `OrderService` bulk item inserts already include timestamps.
- `OrderItem::insert()` does not run Eloquent timestamp automation, so explicit timestamps make the legacy kiosk path symmetric and auditable.

---

## Validation Run After Cleanup

### Syntax

- `php -l app/Services/OrderService.php`
- `php -l app/Services/FrontendOrderService.php`

Result: PASS.

### Pricing / tax / SSOT

- `php artisan test tests/Feature/PosOrderTaxTest.php --stop-on-failure`
  - 2 passed.
- `php artisan test tests/Feature/PosOrderRequestNullableTotalTest.php --stop-on-failure`
  - 5 passed.
- `php artisan test tests/Feature/PosKioskPricingParityTest.php --stop-on-failure`
  - 4 passed.
- `php artisan test tests/Feature/TableOrderSecurityTest.php --stop-on-failure`
  - 1 passed.
- `php artisan test tests/Feature/PricingIntegrityTest.php --stop-on-failure`
  - 1 passed.
- `php artisan test tests/Feature/TableOrderNegativeTotalTest.php --stop-on-failure`
  - 3 passed.

### Symmetry / order lifecycle

- `php artisan test tests/Feature/Stock/StockSymmetryDiffTest.php --stop-on-failure`
  - 1 passed.
- `php artisan test tests/Feature/Symmetry --stop-on-failure`
  - 5 passed.
- `node tools/audit/order-service-symmetry.mjs`
  - PASS: stock decrement symmetry OK.
- `php artisan test tests/Feature/OrderPipeline/KioskFullFlowE2ETest.php --stop-on-failure`
  - 1 passed.

### Table order / KDS propagation

- `php artisan test tests/Feature/SyncComprehensiveTest.php --filter=table_order_appears_in_kds --stop-on-failure`
  - 1 passed.

### Browser regression check

- `npx playwright test tests/e2e/kiosk-full-process/c1-kiosk-process-audit.spec.js tests/e2e/pos-full-process/c2-pos-process-audit.spec.js --project=chromium --retries=0`
  - 10 passed.

---

## Invariants Rechecked

| Invariant | Verdict | Notes |
| --- | --- | --- |
| Backend pricing SSOT | PASS | P2 rounding uses backend-calculated totals only. No frontend/request pricing authority introduced. |
| OrderStatus enum | PASS | No status logic changed. |
| branch_id isolation | PASS | No branch scope changed. |
| Dispatch after commit | PASS | No dispatch code changed. |
| OrderService / FrontendOrderService symmetry | PASS | Tax rounding and timestamps reduce asymmetry. Existing symmetry tests pass. |
| Fiscal cash-at-counter | PASS_UNCHANGED | No fiscal code changed after Claude PASS. |
| Stock/queue concurrency | PASS_UNCHANGED | No stock/queue code changed after Claude PASS. |

---

## Remaining Non-Code Gates

Still required before commercial go-live:

- Hardware UAT: TPE, fiscal printer, physical kiosk lockdown, real KDS screens.
- Staging validation with production-like realtime provider and Google Maps key.
- Human dashboard walkthrough: category/product/photo/composer/stock/publish from a restaurateur account.
- Human final gate after UAT.

Conclusion: Claude's `PASS_PROCEED_HARDWARE_UAT` remains valid after closing the two P2 findings.

