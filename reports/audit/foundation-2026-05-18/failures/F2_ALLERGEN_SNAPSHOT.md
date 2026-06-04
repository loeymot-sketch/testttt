# F2 — OrderAllergenSnapshotComposedTest — Root-Cause Investigation

- **Status**: ROOT CAUSE IDENTIFIED — fix is scope-mini, NOT NF525-critical
- **Branch**: heal/cms-pr1-quickwins-2026-05-18
- **HEAD**: 2949e92ed
- **Investigator**: Claude (read-only audit subagent)
- **Date**: 2026-05-18
- **Confidence**: HIGH (>95%)

---

## 1. Headline

The test is failing **not** because of an allergen-snapshot bug. It is failing because the kiosk/frontend HTTP order-creation route throws a `TypeError` introduced by commit **`80fb27c48`** (today, 2026-05-18 21:05). The allergen assertion at line 168 is **never reached** — the test stops at line 163 because the `/api/frontend/order` POST returns HTTP 500.

`composition_snapshot` and `allergens_snapshot` columns are unaffected. NF525 fiscal data is intact. This is a JSON-resource serialization defect, **not** a fiscal/snapshot-builder defect.

> **F2 is a SYMPTOM. The disease is a wider blast-radius regression in `OrderDetailsResource` that breaks every kiosk/app order creation HTTP response.** Other failing tests in the same audit run are almost certainly the same root cause.

---

## 2. Exact Assertion That Failed

```
$this->assertContains($response->status(), [200, 201], json_encode($response->json()));
```
- **File**: `tests/Feature/Orders/OrderAllergenSnapshotComposedTest.php:163`
- **Actual `$response->status()`**: `500`
- **Expected**: `200` or `201`
- **Underlying error** (extracted from the trace):
  ```
  App\Services\Receipt\ReceiptDataService::buildForOrderModel():
    Argument #1 ($order) must be of type App\Models\Order,
    App\Models\FrontendOrder given,
    called in app/Http/Resources/OrderDetailsResource.php on line 24
  ```

The original sentinel assertion at line 168 — `$this->assertSame(['lait'], $orderItem->allergens_snapshot ?? [], ...)` — was **never executed**. We therefore have **no runtime evidence** for the actual vs expected snapshot contents of this order. Inspection of the merge logic in `OrderItemAllergenSnapshot::hydrate()` (lines 56–73) and `resolveExtraAllergens()` (lines 87–119) looks correct on paper, and the permanent migration for `item_extra_allergens` is in place (`2026_04_22_300000_create_item_extra_allergens_table.php`), so once the TypeError is fixed the assertion is *expected* to pass — but this is unverified until the HTTP response path is unblocked.

---

## 3. Code Path Traced (file:line)

1. `tests/Feature/Orders/OrderAllergenSnapshotComposedTest.php:156-162`
   — `postJson('/api/frontend/order', $payload + quote tokens)`
2. `app/Http/Controllers/Frontend/OrderController.php:46-55`
   — `store()` calls `$this->frontendOrderService->myOrderStore($request)` then returns `new OrderDetailsResource($order)` where `$order` is a `FrontendOrder` instance.
3. `app/Services/FrontendOrderService.php:131` — `myOrderStore(): object`, internally builds/persists `FrontendOrder` (line 660: `return FrontendOrder::query()...`).
4. `app/Http/Resources/OrderDetailsResource.php:24`
   — `$receipt = app(ReceiptDataService::class)->buildForOrderModel($this->resource);`
   `$this->resource` is the `FrontendOrder`.
5. `app/Services/Receipt/ReceiptDataService.php:50`
   — `public function buildForOrderModel(Order $order): array` — **strict typehint `App\Models\Order`** rejects the `FrontendOrder` and throws `TypeError` before any of the actual reads execute.

The TypeError propagates up, Laravel's exception handler turns it into `HTTP 500`, the test fails on the status assertion, the OrderItem-level allergen assertion is unreachable.

---

## 4. Breaking Commit Identification

- **SHA**: `80fb27c48573f45bfd01ffa8da2939ce39a96b1d`
- **Date**: 2026-05-18 21:05 (today)
- **Author**: Kossay20 (Claude Opus 4.7 co-author)
- **Subject**: `feat(receipt-nf525): wire ReceiptDataService into POS receipt builder for fiscal_sequence_no + siret on printed ticket`

What it did:
- Added new method `ReceiptDataService::buildForOrderModel(Order $order): array` with **strict `App\Models\Order` typehint**.
- Modified `OrderDetailsResource::toArray()` to delegate six NF525 fields to the new method via `app(ReceiptDataService::class)->buildForOrderModel($this->resource)`.

What was missed:
- `OrderDetailsResource` is used by **both** `Order` (POS path) and `FrontendOrder` (kiosk/app path). Search confirms `Frontend/OrderController::store/show/changeStatus` all return `OrderDetailsResource($frontendOrder)` where `$frontendOrder instanceof FrontendOrder`.
- The commit's own verification block lists `15/15 PASS` + `9/9 PASS` but every passing test exercises an `App\Models\Order` (POS / receipt-print / split-payment). **No kiosk/frontend-order test was executed.**
- The commit was marked "Owner-gated wire-in (Option A — V1 conformity max)" — so the regression slipped past owner sign-off as well. The owner gate did not catch it because the test scope was POS-only.

Prior to `80fb27c48`, `ReceiptDataService` only exposed `buildForOrder(int $orderId)` (verified via `git show 90a66f4a4`) which did its own `Order::findOrFail()`; nothing in `OrderDetailsResource` called it, so the model-type asymmetry was invisible.

---

## 5. Blast Radius (per advisor)

`Frontend/OrderController` returns `OrderDetailsResource` from three endpoints (`store`, `show`, `changeStatus`). **Every** kiosk/app order creation/read/status-change HTTP response is now broken in 500. Likely siblings in the same failing audit run:
- Any test that POSTs `/api/frontend/order` and reads `data.id` from the response.
- Any test that calls `/api/frontend/order/{id}` to read order details.
- Any test that calls `/api/frontend/order/{id}/status` to change status.
- The Sentinel suite likely includes several such tests (`OrderItemAllergenFlatTest`, `OrderItemAllergenComposedTest`, `KioskOrderQuoteTest` family, etc.). Worth a targeted re-run after fix.

Auditor recommendation: **treat F2 as one of N — search the failure roster for any test that hits `/api/frontend/order*` routes and group them under a single heal**, do not heal one by one.

---

## 6. NF525 Impact Assessment

**NF525 priority: LOW — NOT a fiscal data corruption.**

- `composition_snapshot`: untouched.
- `allergens_snapshot`: untouched.
- `fiscal_sequence_no` allocation logic: untouched.
- `audit_logs` HMAC chain: untouched.
- The order is **never written** to disk when the TypeError fires (the resource is built *after* persistence in `OrderController::store`, line 49 → line 53), so we should verify whether the order row was inserted before the 500 — quick check of the test's `OrderItem::query()->where('order_id', $orderId)->sole()` after the 500 would tell us, but the test never reaches that line. From code reading, `myOrderStore()` commits its DB transaction before `OrderDetailsResource` is constructed, so **the order persists but the API never returns the order id** — the kiosk client would see a 500 with the order silently created. **This is an idempotency/UX hazard worth noting separately** (kiosks may retry, duplicating the order — though `X-Idempotency-Key` middleware should mitigate it).

The frozen `composition_snapshot` immutability contract is not violated; the snapshot was built and persisted correctly upstream. The fix is one line in a service signature, not in any NF525-adjacent file.

---

## 7. Recommended Fix

**Severity**: P0 (HTTP 500 on every kiosk/app order POST)
**Scope**: ≤5 lines, one file (or two)
**Recommendation tier**: **simple scope-mini heal — no owner decision required, no LOCK needed**

### Option A (cleanest, recommended)

`ReceiptDataService.php:50` — widen the typehint to the existing `BroadcastableOrder` marker interface that both `Order` and `FrontendOrder` already implement:

```php
public function buildForOrderModel(\App\Contracts\BroadcastableOrder $order): array
```

The interface is intentionally empty (see `app/Contracts/BroadcastableOrder.php:11`); the method body only touches `$order->id`, `$order->order_serial_no`, `$order->fiscal_sequence_no`, `$order->branch`, `$order->user`, `$order->created_at` — all of which exist on both models as Eloquent attributes. **No body change needed.**

### Option B (also valid)

Drop the typehint and add a PHPDoc `@param \App\Models\Order|\App\Models\FrontendOrder $order`. Less type-safe.

### Option C (architectural)

`FrontendOrder` and `Order` should share a thicker interface (`HasFiscalReceiptFields`?) or a parent abstract model. Out of scope for the heal; backlog item.

### What NOT to do

- Do **not** revert `80fb27c48` — the NF525 SSOT delegation is correct and owner-gated; rollback would lose the wire-in.
- Do **not** change `buildForOrder(int $orderId)` — it still loads `Order::findOrFail()`, that is correct for the legacy POS-only contract.

### After-fix smoke

Re-run `OrderAllergenSnapshotComposedTest` plus any test that exercises `Frontend/OrderController::store|show|changeStatus`. Suggested filter: `php artisan test --filter='Allergen|FrontendOrder|KioskOrder'`. Verify the allergen-snapshot assertion at line 168 itself passes — if it does not, that is a SECOND finding (the sentinel doc-string `FINDING_BACK_DEFERRED` suggests the original test author expected this assertion might still legitimately fail).

---

## 8. Confidence & Caveats

- **Root cause**: HIGH confidence. Direct TypeError trace + commit diff + caller grep.
- **Fix**: HIGH confidence. `BroadcastableOrder` interface already covers the polymorphism contract by design (the interface docstring literally says "so broadcast events can accept either model without a type mismatch" — same problem).
- **Blast radius**: MEDIUM-HIGH confidence — only validated via grep, not full test run.
- **Residual allergen-snapshot risk**: UNKNOWN — never reached at runtime. After the fix lands, the L168 assertion *should* pass given the SSOT path's hydration call (`FrontendOrderService.php:289 & 454` → `OrderItemAllergenSnapshot::hydrate()` → `resolveExtraAllergens()` correctly joins the pivot), but this is inference, not measurement.

---

## 9. One-Line Summary

> Commit `80fb27c48` introduced a strict `App\Models\Order` typehint in `ReceiptDataService::buildForOrderModel()` but `OrderDetailsResource` is also used for `FrontendOrder` — fix is widening the typehint to `BroadcastableOrder` (the existing empty marker interface designed for exactly this polymorphism). Not NF525-critical; allergen snapshot logic itself was never exercised.
