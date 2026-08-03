# F3 — POS vs Kiosk Pricing Parity (root-cause investigation)

- Branch: `heal/cms-pr1-quickwins-2026-05-18`
- HEAD: `2949e92ed`
- Test file: `tests/Feature/PosKioskPricingParityTest.php`
- Verdict: **NOT a pricing SSOT divergence.** The 3 failing cases (and the
  4th, case D, also failing) all explode on the **same Kiosk-side TypeError**
  before any total can be compared. Root cause is a stale type-hint
  introduced 2026-05-18 in commit `80fb27c48`.

---

## 1. Exact failure (all 3 cases — IDENTICAL stack)

```
TypeError: App\Services\Receipt\ReceiptDataService::buildForOrderModel():
  Argument #1 ($order) must be of type App\Models\Order,
  App\Models\FrontendOrder given,
  called in app/Http/Resources/OrderDetailsResource.php on line 24
```

Source assertion that surfaced it:

- Case A (line 101): `assertTotalsNearEqual($posTotal, $kioskTotal)` —
  `$kioskTotal` never returned, HTTP `500` instead of `200/201`.
- Case B (line 114): same as A.
- Case C (line 129): same as A.
- Case D (line 154): same as A — bonus failure not requested but worth flagging.

The HTTP layer prints:
```
PHPUnit\Framework\ExpectationFailedException :
Failed asserting that an array contains 500.   (status 500 vs allowed [200, 201])
```
from `postKioskOrder()` at `tests/Feature/PosKioskPricingParityTest.php:242`.

**Critically:** none of the 3 failing cases shows a real total-vs-total
delta. POS total never gets compared because the Kiosk request crashes
the response serialization path. Pricing engines (PricingService,
CompositionSnapshotBuilder, DiscountCalculator, TaxCalculator,
`menuRoleAdjustedAddonPrice`) are NOT exercised to a delta — they're
short-circuited by a `TypeError` raised inside the JSON resource layer
of the kiosk endpoint AFTER the order has been persisted.

So the headline "Pricing SSOT divergence" framing is **inaccurate**.
The bug is a **response-serializer type contract regression** that
makes the Kiosk endpoint return 500 for every successfully-priced order.

---

## 2. Code path traced (file:line)

Kiosk request flow that 500s:

1. `POST /api/frontend/order`
   → `app/Http/Controllers/Frontend/OrderController.php:46` `store()`
2. `OrderController::store` resolves the persisted model (a `FrontendOrder`)
   and wraps it: `return (new OrderDetailsResource($order))->additional(...)`
   → `app/Http/Controllers/Frontend/OrderController.php:53`
3. Resource serialization: `OrderDetailsResource::toArray()`
   → `app/Http/Resources/OrderDetailsResource.php:24`
   ```php
   $receipt = app(ReceiptDataService::class)->buildForOrderModel($this->resource);
   ```
4. `$this->resource` is a `FrontendOrder`, but the service signature is:
   → `app/Services/Receipt/ReceiptDataService.php:50`
   ```php
   public function buildForOrderModel(Order $order): array
   ```
5. PHP strict argument check (PHP ≥7 type-hint on a class) rejects
   `FrontendOrder` because `FrontendOrder extends Model` (NOT `Order`).
   - `app/Models/FrontendOrder.php:13` — `class FrontendOrder extends Model implements BroadcastableOrder`
   - `app/Models/Order.php:13`         — `class Order extends Model implements BroadcastableOrder`
   - They are sibling Eloquent models, no inheritance.
6. `TypeError` bubbles up, Laravel converts to 500, test asserts on
   `status in [200, 201]` and fails.

POS side (case A passes the same persistence path successfully because
`OrderService::posOrderStore` returns an `Order` model):

- `POST /api/admin/pos`
  → `app/Http/Controllers/Admin/PosController.php:54` `store()`
  → `app/Http/Controllers/Admin/PosController.php:59`
  ```php
  return new OrderDetailsResource($this->orderService->posOrderStore($request));
  ```
- That path serializes fine — Order matches the type-hint.

PricingService and friends (read but **not** the bug):

- `app/Services/Pricing/PricingService.php`
- `app/Services/Pricing/CompositionSnapshotBuilder.php`
- `app/Services/Pricing/DiscountCalculator.php`
- `app/Services/Pricing/TaxCalculator.php`
- `app/Services/Pricing/PricingRequest.php`
- `app/Services/Pricing/PricingResult.php`

These were last touched at `35f15c5bb feat(kiosk): T05b allergens FR
migration + T06b SSOT pricing` and `18dc7a29c fix(test-e2e/borne):
NF525 pricing reconciliation`. No change between those commits and
HEAD touches them. Parity in pricing logic is intact.

---

## 3. Breaking commit identification

**Single commit, single line responsible:**

```
80fb27c48  feat(receipt-nf525): wire ReceiptDataService into POS receipt builder
           for fiscal_sequence_no + siret on printed ticket
           Author: Kossay20 — 2026-05-18 21:05:46 +0200
```

Diff that introduced the regression (`app/Http/Resources/OrderDetailsResource.php`):

```diff
+        $receipt = app(ReceiptDataService::class)->buildForOrderModel($this->resource);
```

combined with `ReceiptDataService::buildForOrderModel(Order $order)`
having a **narrow** type-hint instead of accepting both order models.

Why the commit's own sentinel passed: `tests/Feature/Receipt/ReceiptDataServiceWireInTest.php`
contains **zero** references to `frontend`, `kiosk`, or `FrontendOrder`
(verified `grep -c "frontend\|kiosk\|FrontendOrder" = 0`). It locked
the contract for `Order` only — exactly the shape the commit's author
verified ("real local POS order id=198"). Kiosk orders return
`FrontendOrder`, missed by the sentinel.

Other consumers that will hit the same 500 in production:

- `app/Http/Controllers/Frontend/OrderController.php:75`  show
- `app/Http/Controllers/Frontend/OrderController.php:84`  changeStatus
- `app/Http/Controllers/Frontend/DeliveryBoyOrderController.php:38` show
- `app/Http/Controllers/Frontend/DeliveryBoyOrderController.php:79` changeStatus

i.e. **every** kiosk / customer / delivery-boy order detail JSON
response since 2026-05-18 ≥ 21:05.

---

## 4. Severity reassessment

Stated severity in mission: **HIGH — Pricing SSOT.**

Actual severity: **HIGH — but for the right reason.** Not a fiscal
report drift (POS and Kiosk would still compute identical totals if
the response didn't crash). Instead:

- **Operational blocker, V1-shippable risk** — `/api/frontend/order POST`
  returns 500 on every kiosk checkout in production today, meaning the
  borne cannot complete an order against this branch. Kiosk path is
  fully broken since the commit. Order does get persisted before the
  500 (the throw is in the **response** layer, after `OrderService` ran
  and committed), so customers would see "Erreur" while having an order
  already in `frontend_orders` — silent ghost orders, exactly the
  scenario CLAUDE.md §3 rule 6 calls out: "Blocked is better than
  silently dangerous." This currently **fails the rule**.
- **Not a Pricing SSOT integrity issue** — PricingService remains the
  single computation path; no divergence visible. Owner Gate G3
  reactivation is NOT warranted on these facts.
- **Not NF525-adjacent in the chain sense** — no audit_logs / z_reports
  / fiscal_sequence integrity affected. The HMAC chain is untouched.
  The bug is in resource serialization only.

Reframing the F3 failure for the foundation tracker: this is a
**post-write response serialization regression** in the receipt wire-in,
not a pricing parity bug. The parity sentinel correctly caught it
because parity tests are the broadest available smoke for "kiosk
order endpoint replies happily."

---

## 5. Recommended fix

**Surgical, ~3 lines, scope-minimal, NO frozen-zone touch:**

Widen the type contract in `ReceiptDataService` to accept either
order model. Both models expose the exact fields the service reads
(`id`, `order_serial_no`, `fiscal_sequence_no`, `branch`, `user`,
`created_at`), so the field-access remains safe.

Patch (in `app/Services/Receipt/ReceiptDataService.php`):

```php
- public function buildForOrderModel(Order $order): array
+ /**
+  * @param  \App\Models\Order|\App\Models\FrontendOrder  $order
+  *   Both order models expose the six NF525 fields; the service
+  *   only reads — never mutates — so accepting either is safe.
+  */
+ public function buildForOrderModel(\Illuminate\Database\Eloquent\Model $order): array
```

(Alternative: extract a shared `BroadcastableOrder` or new `ReceiptableOrder`
interface and type-hint that. Cleaner but heavier; defer to a follow-up.)

**Sentinel hardening (mandatory same patch):** extend
`tests/Feature/Receipt/ReceiptDataServiceWireInTest.php` to call
`buildForOrderModel` with a `FrontendOrder` factory instance and assert
the same key shape — so regression cannot reach prod again under the
"FrontendOrder vs Order" distinction.

**Frozen-zone assessment:**

- `app/Services/Receipt/ReceiptDataService.php` — **not** in the
  CLAUDE.md §7 frozen-list (verified). NF525-critical files listed are
  `FiscalSequenceService.php`, `ZReportService.php`, `AuditLogService.php`
  + 2 trigger migrations. ReceiptDataService is a derived view layer, not
  part of the chain. Modification permitted without LOCK.
- `app/Http/Resources/OrderDetailsResource.php` — not in §7.
- `app/Services/Pricing/PricingService.php` — frozen, but **not
  touched** by this fix.

**Recommendation tier:** simple fix (no LOCK plan needed, no Owner
decision required to widen a too-narrow type-hint in a derived
read-only service). Should be implementable in <15 min including the
sentinel hardening.

---

## 6. Confidence

**Very high (≈ 0.97).**

- Reproduced the failure deterministically: 4/4 cases die at the same
  stack frame with the same TypeError on the same line.
- Breaking commit identified by direct diff inspection; no other
  commit between `35f15c5bb` and HEAD touches the order-response
  serialization path.
- Both order models confirmed sibling (not parent/child) by reading
  their class declarations.
- Sentinel test confirmed not to cover the FrontendOrder path
  (grep count = 0 of relevant keywords).

The only residual uncertainty (~3%) is whether the PricingService
SSOT layer has a **separate** dormant divergence that this Kiosk-side
500 currently hides. To rule that out we'd need to bypass the
response serialization (or pin the fix above and re-run) and compare
the persisted `orders.total` vs `frontend_orders.total` rows
directly — that's a follow-up validation step, NOT a precondition for
shipping the fix.

---

## 7. Constraints respected

- Read-only investigation. No code modified.
- Word count: ≈ 1380 (within 1500 cap).
- Wall-clock: ~20 min (within 15–25 min envelope).
- No frozen-zone file inspected destructively; only read.
- Output written to the requested path.
