# Codex M1 / C3 Runtime Multi-Surface Audit — 2026-04-28

Date: 2026-04-28
Executor: Codex
Scope: C3 runtime Kiosk/POS/KDS/OSS live propagation, plus direct fixes discovered while proving the flow.

## Verdict

`C3_RUNTIME_MULTI_SURFACE_VERDICT: PASS_RUNTIME_LOCAL`

The previous Claude API audit was correct to classify C3 as `NOT_VALIDATED` because it had no machine access. This pass converts C3 into a local runtime proof with a real Laravel server, real frontend API calls, real database persistence, and simultaneous browser surfaces.

## Runtime Proof

Artifact:

- `reports/antigravity/c3-runtime-multi-surface.json`

Latest result:

```json
{
  "verdict": "PASS_RUNTIME_LOCAL",
  "results": [
    {
      "scenario": "kiosk_cash_to_kds_pos_oss",
      "pass": true,
      "kds_ms": 2887,
      "oss_preparing_ms": 3880,
      "queue_number": "A0001",
      "order_id": 572
    },
    {
      "scenario": "pos_to_kds_oss",
      "pass": true,
      "kds_ms": 5888,
      "oss_preparing_ms": 4890,
      "token": "PW-C3-POS_CASH-1777380774324",
      "queue_number": "A0001",
      "order_id": 573
    }
  ]
}
```

Run-many validation:

- `npx playwright test tests/e2e/c3-runtime-multi-surface.spec.js --project=chromium --retries=0`
  - `2 passed`
- `npx playwright test tests/e2e/c3-runtime-multi-surface.spec.js --project=chromium --retries=0 --repeat-each=3`
  - `6 passed`

Cleanup proof after repeat:

```json
{"pw_c3_orders":0,"pw_c3_items":0,"active":0}
```

## Direct Product Fixes Applied

### 1. KDS / OSS / POS branch id lookup

Files:

- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`
- `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue`
- `resources/js/components/admin/pos/PosComponent.vue`

Problem:

The stores expose `authBranchId` as a non-namespaced getter/state value, while some surfaces tried to read `getters['auth/authBranchId']`. That made branch id empty in runtime, which weakens Echo channel targeting and fallback sync.

Fix:

Added an `authBranchId()` compatibility helper on each surface:

- tries `getters['auth/authBranchId']`
- then `getters.authBranchId`
- then `state.auth.authBranchId`

KDS/OSS/POS now subscribe and poll with the actual branch id.

### 2. KDS fallback sync authentication

File:

- `resources/js/services/KdsSyncService.js`

Problem:

`KdsSyncService.forceSync()` used raw `fetch()` without the axios interceptors, so the fallback sync did not reliably send `Authorization` or `x-api-key`. A live KDS could recover poorly after a WebSocket outage.

Fix:

Added `_authHeaders()` and attached headers to the fallback sync request:

- `Accept: application/json`
- `x-api-key`
- `Authorization: Bearer ...` when present in persisted Vuex auth
- locale headers when present

### 3. Kiosk `is_advance_order` enum mismatch

Files:

- `resources/js/store/modules/kioskCart.js`
- `app/Http/Requests/OrderRequest.php`
- `app/Http/Requests/PosOrderRequest.php`
- `tests/js/kioskCartSendPayload.spec.js`

Problem:

Kiosk orders sent `is_advance_order: 0`, while KDS/OSS immediate-order filters expect `Ask::NO = 10`. Result: the order could be persisted with status `ACCEPT` but hidden from KDS/OSS filters.

Fix:

- Kiosk payload now sends `is_advance_order: 10`.
- Web/POS request normalizers convert legacy `0` to `Ask::NO`.
- Existing kiosk payload test updated to lock this enum.

### 4. C3 test cleanup and false-pass guard

File:

- `tests/e2e/c3-runtime-multi-surface.spec.js`

Problem:

Actual API-created orders receive numeric `order_serial_no`, so cleanup by test prefix alone missed orders. The report accumulator also risked being reset per scenario.

Fix:

- Cleanup now finds orders by prefixed serial, token, item names, and order item instructions.
- Empty/partial C3 result sets are `REWORK_RUNTIME_LOCAL`, not PASS.
- Report accumulation now persists both runtime scenarios.

## Validation Commands

Passed:

- `npx vitest run tests/js/kioskCartSendPayload.spec.js tests/js/kdsVersionGate.spec.js tests/js/kdsBackoffOn5xx.spec.js tests/js/kdsSyncCadence.spec.js`
- `php -l app/Http/Requests/OrderRequest.php`
- `php -l app/Http/Requests/PosOrderRequest.php`
- `php -l app/Services/Stock/StockService.php`
- `php artisan test tests/Feature/Delivery/DeliveryFeeForgePosTest.php`
- `php artisan test tests/Feature/Payment/CounterDeferredPaymentLifecycleTest.php`
- `php artisan test tests/Feature/Stock/StockAvailabilityAfterCommitTest.php`
- `npm run production`
- `npx playwright test tests/e2e/c3-runtime-multi-surface.spec.js --project=chromium --retries=0 --repeat-each=3`

## Invariants Checked

- Backend pricing SSOT: C3 creates quotes/orders through backend quote/order endpoints; no frontend price assertion is authoritative.
- Order status enum: C3 uses persisted status values and KDS status transition endpoint, not magic client strings.
- Branch isolation: fixtures are branch scoped; KDS/OSS/POS now resolve branch id consistently.
- Dispatch after commit: M0 fixed stock availability event dispatch and C3 validates post-commit visibility.
- Frozen zones: no OrderService or FrontendOrderService edits were made in this C3 pass.

## Remaining Open Items From Claude Super Audit

Still not globally closed by this report:

- C4 stock stress with high concurrency.
- C5 queue number stress with POS+kiosk concurrency.
- C6 fiscal/outbox/persistence/HMAC/Z-report proof.
- C9 dashboard management full restaurateur flow.
- MySQL-specific validation for suites skipped under SQLite.
- Optional P2 cleanup: counter-collect controller extraction, visual regression baselines, OpenAPI docs.

## Decision

C3 can move from `NOT_VALIDATED` to `PASS_RUNTIME_LOCAL`.

Global release remains `REWORK_BEFORE_UAT` until the remaining P0/P1 audit items above are converted into machine proofs.
