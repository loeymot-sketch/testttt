# A10 — POS Cash Payment Flow — 2026-05-11

**Agent**: A10 — Cash Payment Flow
**HEAD**: `a220b9bd8`
**Branch**: `feature/mobile-app-le-cayenne-2026-05-10`
**Scope**: Cash tender end-to-end (POS direct CASH + kiosk-cash collection at POS).
**Discipline**: READ-ONLY. file:line verified.

## In-scope files actually present

- `app/Services/PaymentService.php` (485 lines) — note: path differs from
  master plan (`app/Services/Payments/PaymentService.php` does NOT exist).
- `app/Services/OrderService.php` — contains `posOrderStore` (line 563)
  and `collectKioskCash` (line 1954).
- `app/Http/Controllers/Admin/PosController.php` — 137 lines, delegates
  to `OrderService::posOrderStore`.
- `app/Http/Requests/PosOrderRequest.php` (181 lines) — cash tender
  validation is **shape-only**; authoritative server check elsewhere.
- `app/Models/OrderPayment.php` — `cascadeOnDelete` FK confirmed in
  migration `2026_05_06_180000_create_order_payments_table.php:32`.
- `tests/Feature/PosCashEndpointSentinelTest.php` (2 copies — duplicate).
- `tests/Feature/PosCollectKioskCashRouteTest.php`.
- `tests/e2e/02-pos-cash.spec.js` — rewritten since 2026-05-09 but still
  contains `test.fixme(true,…)` early-return (line 125).

## P0-13 verification (fresh read of 02-pos-cash.spec.js)

**Status**: PARTIALLY FALSIFIED, but not fully recovered.

The spec at HEAD a220b9bd8 has been substantially rewritten (header says
"P0-13 adversarial-grade rewrite iter15"). Real `.click()` and `.fill()`
calls are present (tile click line 104, addCta click line 112, payBtn
click line 128, cashModeBtn click line 134, tenderedInput fill line 140,
confirmPay click line 147). **However:**

- Line 118-127: if `[data-testid="pos-v5-pay"]` is not visible within
  5s, the test calls `test.fixme(true, 'pos-v5-pay non visible : env
  catalogue vide ou panier non amorcé.')` and returns. In a CI run
  against an empty-catalogue branch (or without dataset seed), **the
  cash flow assertion path is silently skipped** — the test reports
  "fixme" rather than failure, but no real cash payment confirmation
  is exercised end-to-end.
- Line 84-97: cash-drawer open is best-effort with `isVisible…catch`
  fallbacks. If the test runs against a branch with no open drawer
  and no `kiosk-cash-open` button, the cashier-supervised F-003
  acceptance path is bypassed.
- Line 155-163: final assertion is **OR-coupled**:
  `hasTicket || hasEmptyCart`. "Panier vide" is true on the empty-cart
  initial state as well — a test that adds an item, clicks Pay, and
  then renders an unrelated empty state passes this assertion.

→ The spec is no longer pure-textual, but its acceptance gates allow
silent partial completion. Verdict: **P1** rather than P0-as-stated,
still needs hardening.

## Findings

### P0-A10-01 — `collectKioskCash` discards the actual tendered amount

`OrderService::collectKioskCash` (line 1954-1962) always passes
`received = (float) $order->total` to `PaymentService::confirmCounterPayment`.
The cashier never enters the real tendered amount when collecting a
deferred kiosk cash payment. Consequences:

1. **Change cannot be calculated** — `change_amount` is never persisted
   on either `orders` or `order_payments` for kiosk-cash collection.
2. **NF525 reconciliation gap** — Z-report cash drawer reconciliation
   loses the float-vs-tendered comparison: every kiosk-collected cash
   row reports `received == total` regardless of what the cashier
   physically put in the drawer. Over/under-rings are invisible.
3. F-003 (cashier-supervised cash) Option-A invariant violated: the
   audit decision required *recording* the cashier's tendered count.

Severity: **P0** (NF525 audit trail integrity for kiosk-cash subset).

### P0-A10-02 — `confirmCounterPayment` does not persist `change_amount` for direct POS CASH either

`PaymentService::confirmCounterPayment` (line 130-237) only writes
`pos_received_amount = $received ?? $total` (line 184-186) but never
computes nor persists `change_amount`. The `order_payments` table
defines `change_amount` (migration line 27) but the legacy single-tender
path never inserts an `order_payments` row — it stays on the legacy
`orders.pos_payment_method` + `pos_received_amount` only. Net effect:
the migration *added* a `change_amount` column but no code writes it
when split_payment is OFF (`config('split_payment.enabled', false)`,
which is the V1 default per PosOrderRequest line 36).

Severity: **P0** for fiscal reporting (Z-report cannot report total cash
returned as change → drawer reconciliation drifts).

### P0-A10-03 — `posOrderStore` cash branch never inserts `order_payments` row in single-tender mode

Lines 888-895 only **validate** `pos_received_amount >= total`. No
`OrderPayment` is created. Combined with split_payment flag OFF (V1
default), the canonical `order_payments` table is empty for legitimate
direct-POS CASH sales. This makes `change_amount`, `tendered`, and
per-tender Z aggregation queries return zero rows for the V1 cash
volume — they only fire on the multi-tender flag.

Severity: **P0** (fiscal completeness).

### P1-A10-04 — `collect-kiosk-cash` route lacks `idempotency` middleware

`routes/api.php:789-799` mounts `POST /admin/pos/collect-kiosk-cash/{order}`
with `throttle:pos-order-update` only — **no `idempotency` middleware**.
The compensating logic is internal: `confirmCounterPayment` early-returns
when `payment_status == PAID` (line 164-167), and `Transaction` insert
is via `firstOrCreate` (line 190-201). This works for *exact* duplicates
but does not match the canonical X-Idempotency-Key contract used by the
POS direct store route (line 728) and frontend store routes. Confirms
the test in `PosCollectKioskCashRouteTest.php:54-59` is the only check
— good enough for V1 only because of the internal lock+early-return,
but architecturally inconsistent.

Severity: **P1** (consistency + future regression risk if early-return
guard is ever removed).

### P1-A10-05 — Migration `order_payments` cascades on order delete

Migration line 32: `cascadeOnDelete()` on `order_id` FK. Net effect:
when `OrderService::destroy()` deletes an order, all `order_payments`
rows go with it. For pre-Z-seal orders this is desirable (cleanup), but
when combined with the missing trigger guards on `orders` itself for
fiscally-sealed lines, this creates a **silent loss of cash audit
breadcrumb** if a non-Admin somehow elevates and deletes a pre-seal
order. Current `destroy` (line 1998-2042) does check Z-seal at line
2026-2038 — gating is correct **today**, but the cascade is a footgun.

Severity: **P1** (defense-in-depth — recommend `restrictOnDelete` or
soft-delete).

### P1-A10-06 — `pos_received_amount` rule too permissive

`PosOrderRequest.php:102`:
```php
'pos_received_amount' => request('pos_payment_method') === PosPaymentMethod::CASH
    ? ['required', 'numeric', 'min:0']
    : ['nullable', 'numeric', 'min:0']
```
`min:0` allows zero on CASH. The strict check happens server-side at
`OrderService::posOrderStore:888-895` (`< total` throws). But the rule
on the FormRequest itself is misleading — should be `gte:0` is fine
but the rule does not encode the business constraint. The shape check
in `withValidator` (line 148-152) only runs when `request->filled('total')`
which is now nullable. A request with no `total` and `pos_received_amount=0`
passes FormRequest, and only fails inside `posOrderStore`.

Severity: **P1** (defense-in-depth; current backend recompute catches
it but FormRequest should match real invariant).

### P2-A10-07 — Duplicate sentinel test files

Two files share the class name `PosCashEndpointSentinelTest` :

- `tests/Feature/PosCashEndpointSentinelTest.php`
- `tests/Feature/Sentinels/PosCashEndpointSentinelTest.php`

Both declare `class PosCashEndpointSentinelTest extends TestCase` but in
different namespaces (`Tests\Feature` vs `Tests\Feature\Sentinels`). The
first uses `Route::getRoutes()->getByName(...)`, asserts URI text and
`file_get_contents(routes/api.php)` for a literal string — brittle. The
second uses `Route::has(...)` — clean. The first should be removed.

Severity: **P2** (test maintenance + brittle string match).

### P2-A10-08 — `PosCollectKioskCashRouteTest` does not assert change/tendered

The test at line 61-67 only asserts the persisted state has `payment_status=PAID`,
`pos_payment_method=CASH`, `fiscal_sequence_no=1`. It does **not** assert
`pos_received_amount` value, `change_amount`, or any `order_payments`
row. Given P0-A10-01, the test confirms the bug without flagging it.

Severity: **P2** (test gap — perpetuates the bug).

### P3-A10-09 — `confirmCounterPayment` short-circuits if already PAID without idempotency-key contract

Line 164-167 early-returns silently if `payment_status==PAID`. The
response is `OrderDetailsResource` of the existing order. Good for
double-click protection but the caller has no signal that a no-op
happened (no `X-Idempotency-Replay: 1` header, no `replayed: true`
field). For external integrations this is opaque.

Severity: **P3** (observability).

## 5 Real Playwright scenarios to replace 02-pos-cash.spec.js

These replace the fragile fixme-fallback flow. All assume `admin@lecayenne.fr`
or `pos@lecayenne.fr` seeded user, and a non-empty catalogue (gate via
`test.beforeAll` that creates a seed item if needed using API).

**S1 — POS cash exact amount (no change due)**
1. Login `pos@…`. URL `/admin/pos`.
2. API-seed an item via `POST /api/admin/items` (or fall back to first
   `.pos-v5-tile`); add to cart.
3. Read total (`[data-testid="pos-v5-total"]`).
4. Click `[data-testid="pos-v5-pay"]`, then `[data-testid="pos-payment-mode-cash"]`.
5. Fill `input[name="tendered"]` with exact total. Confirm.
6. Assertions :
   - response 200 + JSON contains `order_serial_no`.
   - DB row `orders` has `payment_status=PAID`, `fiscal_sequence_no >= 1`,
     `pos_received_amount == total`.
   - DB row `audit_logs` action=`order.counter_payment_confirmed` exists
     for the order id.
   - Receipt modal/toast shows total.

**S2 — POS cash with change due**
- Same as S1 but tender = total + 5.00 €.
- Assert : response payload exposes `change_due = 5.00`; DB
  `pos_received_amount` reflects 5 € over. (Will FAIL today → P0-A10-02.)

**S3 — POS cash below total rejected**
- Same as S1 but tender = total - 0.50 €.
- Click Confirm.
- Assertion : response 422, error key `received` or `pos_received_amount`,
  toast visible. Order NOT created (no new `orders` row, no fiscal_sequence
  consumed → query `MAX(fiscal_sequence_no)` unchanged).

**S4 — Kiosk-cash collection at POS (end-to-end)**
- Login `pos@…`. Pre-seed an `Order` with
  `payment_method=CASH_ON_DELIVERY`, `pos_payment_method=COUNTER_DEFERRED`,
  `source_surface=kiosk`, `status=ACCEPT`, `total=12`.
- Navigate to POS kiosk-pending list (`/admin/pos?tab=kiosk-cash` or
  whatever surface — verify path live).
- Click "Collect cash" for that order. Fill tendered = 15 €. Confirm.
- Assertion :
  - DB row `orders` has `payment_status=PAID`, `pos_payment_method=CASH`,
    `pos_received_amount=15`, `change_amount=3` (will FAIL today →
    P0-A10-01), `fiscal_sequence_no` allocated.
  - Event `OrderPaidAtCounter` dispatched (assert via test hook or
    Eloquent observer log).

**S5 — Idempotency replay on collect-kiosk-cash**
- Same as S4. After first success, send the *same* POST again from
  Playwright via `page.evaluate(fetch…)` with same body.
- Assertion : second call also 2xx, response identical, only ONE
  `audit_logs` row for `order.counter_payment_confirmed`, only ONE
  `transactions` row of type `payment`. (Currently passes due to
  `firstOrCreate`, but if internal guard is ever weakened the test
  pins the contract.)

## Cross-references to other agents

- **A06 / A08** — fiscal_sequence + Z report aggregation depend on
  `order_payments` rows being created (P0-A10-03). If A08 finds Z
  cash totals match `orders.total` directly (bypassing breakdown), the
  P0-A10-03 is partly mitigated for V1 single-tender; but flag rollout
  will break.
- **A09** — cash drawer session linkage (`recordCashOrderMovement`,
  line 243-281 in PaymentService) is best-effort and logs `[F-003] No
  open cash drawer session` warning. A09 should confirm whether the
  V1 invariant requires session OPEN before any cash sale (current
  code permits cash without session — see line 258-265).
- **A14** — `permission:pos` gate is set on
  `PosController@store` (constructor line 39) and on
  `collect-kiosk-cash` route via inline `abort_unless` (route line 790).

## Verdict

**4 P0 / 3 P1 / 2 P2 / 1 P3** all newly identified or sharpened
relative to past audit. The most fiscally severe is **P0-A10-01 +
P0-A10-02**: change_amount and tendered are not persisted, breaking
NF525 drawer reconciliation for the kiosk-cash path and the direct-POS
single-tender path. Block V1 cash GA until P0-A10-01+02+03 closed.
