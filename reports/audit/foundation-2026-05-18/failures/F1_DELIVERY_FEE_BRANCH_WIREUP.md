# F1 — DeliveryFeeBranchWireupSentinelTest::test_customer_saved_address_quote_uses_branch_configured_fee

**Branch:** `heal/cms-pr1-quickwins-2026-05-18`
**HEAD:** `2949e92ed`
**Investigator (read-only):** Claude (root-cause agent)
**Verdict:** Simple fix (1-line typehint relaxation). Test failure NOT caused by the DEL-5 branch-aware wire-up — that wire-up is in fact correct. The break comes from an unrelated, later commit (NF525 receipt SSOT wire-in) that introduced an over-strict `Order` typehint on a code path that legitimately receives `FrontendOrder` instances.

---

## 1. Actual vs Expected

### Assertion that failed

`tests/Feature/Delivery/DeliveryFeeBranchWireupSentinelTest.php:150`

```php
$this->assertContains($response->status(), [200, 201], $response->getContent());
```

| Field             | Expected            | Actual           |
|-------------------|---------------------|------------------|
| HTTP status       | 200 or 201          | **500**          |
| Response body     | OrderDetailsResource JSON | Laravel error JSON |
| Exception class   | (none)              | `TypeError`      |

> The **delivery_charge** assertion on line 155 (`assertSame(3.0, ...)`) never executes — the test fails at the prior `assertContains` because the HTTP POST returns 500.

### Actual error (from PHPUnit output)

```
TypeError: App\Services\Receipt\ReceiptDataService::buildForOrderModel():
  Argument #1 ($order) must be of type App\Models\Order,
  App\Models\FrontendOrder given,
  called in app/Http/Resources/OrderDetailsResource.php on line 24
  and defined in app/Services/Receipt/ReceiptDataService.php:50
```

So the response body the test sees on failure is the 500 stack trace, not the order JSON.

---

## 2. Code path traced

The /api/frontend/order POST exercised by the test runs:

| # | File:line | Role |
|---|-----------|------|
| 1 | `app/Http/Requests/OrderRequest.php:101-114` | `prepareForValidation` enters the customer saved-address branch (`address_id` filled, `delivery_distance_km` filled, `branch_id` filled, user authenticated) |
| 2 | `app/Services/Delivery/DeliveryQuoteService.php:31-72` | `quoteForAddress` loads branch, computes distance, calls `DeliveryFeeService::fromDistanceKm($distance, $branch)` **with** the branch (correctly wired) |
| 3 | `app/Services/Delivery/DeliveryFeeService.php:26-46` | Branch has all three columns set → returns `round(max(3.0, 2.0 + 2.0*0.0), 2) = 3.0` (correct) |
| 4 | `OrderRequest.php:111-114` | merges `delivery_charge = 3.0` into the request |
| 5 | `app/Http/Controllers/Frontend/OrderController.php:46-69` | `store()` succeeds, persists a `FrontendOrder` with `delivery_charge = 3.0` |
| 6 | `OrderController.php:53` | `return (new OrderDetailsResource($order))->additional([...])` — **`$order` is a `FrontendOrder`** |
| 7 | `app/Http/Resources/OrderDetailsResource.php:24` | `$receipt = app(ReceiptDataService::class)->buildForOrderModel($this->resource);` — **passes `FrontendOrder`** |
| 8 | `app/Services/Receipt/ReceiptDataService.php:50` | Signature is `buildForOrderModel(Order $order)` → PHP `TypeError` at the call site |

The branch-aware wire-up (commits `189d7ebe0` DEL-5 + `04a9454f6` LIVREUR-Z4-ARCH-01..04) **does the right thing**. The test would pass its own delivery_charge assertion if step 8 didn't throw. The 500 fires AFTER the persisted FrontendOrder already has `delivery_charge = 3.0`.

I additionally read `tests/Feature/Receipt/ReceiptDataServiceWireInTest.php:56` which is the only sentinel for the new SSOT path and confirmed it instantiates an `Order::factory()` exclusively — the `FrontendOrder` consumer (this exact `/api/frontend/order` route) was never exercised by the wire-in's own coverage.

---

## 3. Git blame / breaking commit

Two candidate commits between baseline `5bb8c48f9` and HEAD `2949e92ed`:

| Commit | Date (Paris) | Touches |
|--------|--------------|---------|
| `04a9454f6` | 2026-05-18 11:11 | Branch-aware delivery wire-up + sentinel test creation (this very test) |
| `80fb27c48` | 2026-05-18 21:05 | NF525 receipt SSOT wire-in — adds `app(ReceiptDataService::class)->buildForOrderModel($this->resource)` in `OrderDetailsResource:24` AND introduces `buildForOrderModel(Order $order)` typehint in `ReceiptDataService:50` |

`git show 80fb27c48 -- app/Http/Resources/OrderDetailsResource.php` shows the new `app(...)->buildForOrderModel($this->resource)` call inserted at `toArray()`. Before that commit, `OrderDetailsResource` read fiscal fields directly off `$this->fiscal_sequence_no` — which worked transparently with both `Order` and `FrontendOrder` because the `orders` table backs both models (`FrontendOrder::$table = "orders"`).

The commit's own verification matrix lists `KDSDeliveryEnrichmentTest` and `SplitPaymentEndToEndTest` as the "other OrderDetailsResource consumers" — but the customer-frontend `/api/frontend/order` POST flow (which returns a `FrontendOrder` through `OrderDetailsResource`) was not in scope.

**The breaking commit is `80fb27c48` (`feat(receipt-nf525): wire ReceiptDataService into POS receipt builder ...`).** The DEL-5 wire-up commit `04a9454f6` is innocent.

Also note: prior to `80fb27c48`, this exact assertion path almost certainly would have passed (FrontendOrder shares `branch`, `user`, `fiscal_sequence_no`, `order_serial_no` with `Order`, all on the `orders` table). The test was authored in `04a9454f6` and the wire-in landed 10 hours later on the same branch — there is no commit between them in this branch's history (only `a27721d21`, a Z-3 docs commit, which doesn't touch PHP).

---

## 4. Recommended fix

**Type-broadening, 1-line scope** — relax the parameter typehint at `ReceiptDataService.php:50` so the SSOT receipt method accepts any model on the `orders` table.

Two equivalent approaches; pick whichever the owner prefers stylistically:

### Option A (minimal, recommended — 1 line)
```php
// app/Services/Receipt/ReceiptDataService.php:50
public function buildForOrderModel(\Illuminate\Database\Eloquent\Model $order): array
```

The method body only reads `$order->id`, `$order->order_serial_no`, `$order->fiscal_sequence_no`, `$order->branch`, `$order->user`, `$order->created_at` — every one of those is present on both `Order` and `FrontendOrder` (same table). No body changes needed.

### Option B (defensive — 1 line, narrower contract)
```php
public function buildForOrderModel(\App\Models\Order|\App\Models\FrontendOrder $order): array
```

Slightly more restrictive (rejects e.g. an arbitrary Eloquent model) but couples the SSOT service to both model classes. Option A is preferable because it preserves the "service knows nothing about specific model classes" boundary.

### Sentinel hardening (recommended companion — 5-10 LOC)
Add one test case in `tests/Feature/Receipt/ReceiptDataServiceWireInTest.php` that instantiates a `FrontendOrder` and calls `buildForOrderModel($frontendOrder)`, asserting payload parity with `Order`. This locks the contract so a future patch can't silently re-narrow the typehint.

### Scope estimate
| Item | LOC | Risk |
|------|-----|------|
| `ReceiptDataService.php` typehint widening | 1 | None — read-only method, no fiscal mutation |
| `ReceiptDataServiceWireInTest.php` FrontendOrder case | ~15 | None |
| **Total** | **~16** | **Trivial** |

Frozen-zone touched: **No** — `ReceiptDataService` is not in the frozen list (only `FiscalSequenceService`, `ZReportService`, `AuditLogService` are NF525-frozen). NF525 chain is untouched.

---

## 5. Confidence

**High (≥95%).** Evidence:

- Reproduced the exact TypeError text from PHPUnit (section 1)
- Located the typehint at the exact line cited in the trace (`ReceiptDataService.php:50`)
- Confirmed `FrontendOrder` extends `Model` not `Order` (`grep` line 13 of `app/Models/FrontendOrder.php`)
- Confirmed `OrderController::store` returns a `FrontendOrder` through `OrderDetailsResource` (lines 46-53 of `app/Http/Controllers/Frontend/OrderController.php`)
- Confirmed the wire-in commit `80fb27c48` is the introducer via `git show` of the diff
- Confirmed the sentinel test `ReceiptDataServiceWireInTest` only exercises `Order` so it could not have caught this (line 56 of that test)

**Residual risk:** Owner may prefer Option B over Option A for explicit-contract reasons. Either resolves the failure.
