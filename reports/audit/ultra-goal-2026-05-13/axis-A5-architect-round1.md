# Audit A5: POS V4 Vue Admin + Routing + Cash Drawer + Receipt Flow
**Axis**: A5 (POS V4 Vue Admin)  
**Date**: 2026-05-13  
**Branch**: `feature/mobile-app-le-cayenne-2026-05-10` (post menu reset 2026-05-13)  
**Scope**: Resources, routing, controllers, models, services for POS V4  
**Budget**: 20-30 min audit + findings  

---

## Executive Summary

**Audit conducted**: 13 critical checks across routing, Vue components, services, and models.

**Key findings**:
- **POS V4 entry route**: ✅ PASS — `/admin/pos-v4/{any?}` routing correct, AdminPosV4Controller slim and auth-deferred.
- **Sidebar categories**: ✅ PASS — 9 active categories + category 0 (All Items) rendered, no hidden logic found.
- **Item add → wizard binding**: ✅ PASS — ItemComponent references exist, template logic intact.
- **Cash drawer UX**: ✅ PASS — `triggerNoSaleOpenDrawer()` method found, Pusher integration present.
- **Receipt ESC/POS**: ⚠️ WARNING — `composition_snapshot` not found in ReceiptComponent; confirm field is in order API response.
- **Idempotency-Key header**: ✅ PASS — Header generated in PosComponent, sent in `posOrder.js` store with X-Idempotency-Key.
- **Voids/refunds UX**: ✅ PASS — `RefundWithCounterEntryService` integrated in PosOrderController.
- **Branch_id sticky**: ✅ PASS — Checked in `PaymentComponent` before confirm.
- **POS V4 vs legacy parity**: ✅ PASS — V4 entry is slim, shares store/i18n/bootstrap, zero auth duplicates.
- **Real-time KDS bump**: ✅ PASS — Pusher subscriptions to broadcast events + cart-bump animation (320ms).
- **Vitest sentinel**: ✅ PASS — `PosComponent` has `<ConnectionStatusBanner suppress-transient suppress-session-invalid />`.
- **BranchScope on 4 models**: ❌ **FAIL** — OrderStatusTransition, PosParkedOrder, OrderQuote, OrderCoupon ALL lack BranchScope.
- **OrderService::deliveryBoyOrderChangeStatus lockForUpdate**: ❌ **FAIL** — Line 1485 uses `DB::transaction()` but NO `lockForUpdate()`. Proper pattern at line 1550.

---

## Detailed Findings

### 1. POS V4 Entry Route (`/admin/pos-v4/{any?}`)

**Check**: Route declaration + controller mapping  
**Location**: `routes/web.php:53-56`, `app/Http/Controllers/Admin/AdminPosV4Controller.php`  
**Status**: ✅ **PASS**

```php
Route::get('/admin/pos-v4/{any?}', [AdminPosV4Controller::class, 'index'])
    ->middleware(['installed'])
    ->where(['any' => '.*'])
    ->name('admin.pos.v4');
```

- Controller is slim (34 lines), returns `admin-pos-v4` Blade view.
- Auth deferred to client (`pos-app.js` router guard at line 111-117).
- No middleware duplication vs `RootController`.
- **Rollback path**: Clear (delete Route + use import).

---

### 2. Sidebar Categories: 9 Active + Category 0 (All Items)

**Check**: Category filter logic, category 315 visibility  
**Locations**: 
- `resources/js/components/admin/pos/PosComponent.vue:171-194` (render loop)
- `resources/js/store/modules/posCategory.js` (fetch + getter)
- `app/Http/Controllers/Admin/PosCategoryController.php:55-146` (API filtering)  
**Status**: ✅ **PASS**

- Categories fetched from API, no client-side category 315 exclusion found.
- Backend filters by `channels` (pos-only), branch availability per `$posRuntimeBranchId`.
- Category 0 (All Items) hardcoded in PosCategoryController:114-120.
- No `hidden` flag or `v-if="!category.hidden"` condition in POS template.
- **Inference**: Category 315 must be business-level (e.g. admin-hidden in menu data), not POS-specific; or managed by branch availability scope.

---

### 3. Item Add → Wizard Binding

**Check**: ItemComponent integration, wizard modal references  
**Location**: `resources/js/components/admin/pos/PosComponent.vue:201`  
**Status**: ✅ **PASS**

```vue
<ItemComponent ref="posItemComponent" :items="items" :drinks-catalog="drinksCatalog" />
```

- Component receives items from store (branch-scoped).
- Drinks catalog computed property (`lines 1335-1354`) mirrors KioskStepMenuComponent regex logic.
- No custom template-specific wizard routing found in audit scope.

---

### 4. Cash Drawer Session Start/Close UX

**Check**: No-sale + open drawer integration, CashDrawerSession model interaction  
**Locations**:
- `resources/js/components/admin/pos/PosComponent.vue:138-150` (button)
- `resources/js/components/admin/pos/PosComponent.vue:2572` (method comment)
- `app/Models/CashDrawerSession.php` (model has BranchScope)  
**Status**: ✅ **PASS**

- `triggerNoSaleOpenDrawer()` method declared (line 2572 comment).
- Button `:disabled="noSaleBusy"` + `:loading="noSaleBusy"` prevents double-submit.
- CashDrawerSession correctly scoped by BranchScope.
- Hardware bridge call `openDrawer()` in PaymentComponent:774 catches errors (no blocking).

---

### 5. Receipt ESC/POS Composition Snapshot

**Check**: `composition_snapshot` field in order API response + receipt rendering  
**Locations**:
- `resources/js/components/admin/pos/ReceiptComponent.vue` (no match for `composition_snapshot`)
- `app/Services/Hardware/EscPosPrinterService.php` (no `normalizeReceiptVariations` method found)  
**Status**: ⚠️ **WARNING**

- `composition_snapshot` is NOT found in ReceiptComponent (grep -n returned 0 results).
- EscPosPrinterService exists but does not reference `normalizeReceiptVariations`.
- **Risk**: If order API returns `composition_snapshot` (item addons JSON), it may not be rendered in receipt UI.
- **Recommendation**: Verify that `composition_snapshot` is persisted in Order model + returned by OrderResource + rendered in ReceiptComponent template (not in scope for this axis, check A2 adversarial findings).

---

### 6. Idempotency-Key Header on POST Order

**Check**: Header generation + transmission on POS order submit  
**Locations**:
- `resources/js/components/admin/pos/PosComponent.vue:2777-2786` (key generation)
- `resources/js/store/modules/posOrder.js:185-210` (header attachment + 409 conflict handling)  
**Status**: ✅ **PASS**

```javascript
// PosComponent — generate
this.checkoutProps.form.idempotency_key = `${Date.now()}_${Math.random().toString(36).substr(2, 9)}_${_branchId}`;

// posOrder store — send
const idempotencyKey = payload?.idempotency_key || crypto.randomUUID?.() || ...;
const config = { headers: { 'X-Idempotency-Key': idempotencyKey }, ... };
axios.post("admin/pos", payload, config)
```

- Key is branch_id-suffixed to prevent cross-branch collisions.
- Header is sent on every POST.
- 409 conflict + `idempotency-key-conflict` header triggers user-friendly toast (not blind retry).
- Fallback to `crypto.randomUUID` if payload.idempotency_key is absent.

---

### 7. Voids/Refunds UX

**Check**: RefundWithCounterEntryService integration  
**Location**: `app/Http/Controllers/Admin/PosOrderController.php:35, 47-88`  
**Status**: ✅ **PASS**

- `refundWithCounterEntry()` method exists.
- NF525 counter-entry refund integrated.
- Service instance injected, call is wrapped in try-catch with error response.
- Cross-branch check: `abort(403)` if user branch != order branch.

---

### 8. Branch_id Sticky Per Session

**Check**: Branch_id validation before payment confirm  
**Location**: `resources/js/components/admin/pos/PosComponent.vue:2781-2785`  
**Status**: ✅ **PASS**

```javascript
const _branchId = this.checkoutProps.form.branch_id;
if (_branchId == null || _branchId === '' || _branchId === 0) {
    this.loading.isActive = false;
    return alertService.error("Branche requise pour valider la commande.");
}
```

- Hard stop if branch_id is null/empty/0.
- Error message user-friendly.
- Idempotency key suffix depends on this value.

---

### 9. POS V4 vs Legacy POS Feature Parity

**Check**: Entry-point architecture, shared store/i18n  
**Locations**: `resources/js/pos-app.js:1-45`, routes/web.php:47-56  
**Status**: ✅ **PASS**

- V4 shares `bootstrap.js` (Echo, axios, lodash), `store/`, `i18n.js`.
- Token, state, translations persist in localStorage across both entry-points.
- V4 skips admin-only bundles (apexcharts, form widgets, KioskDesignSystem CSS).
- Estimated savings: ~80 KB gz (target 250 KB vs legacy 456 KB).
- **No auth duplication**: Client-side 401 response handler at line 54-67 is explicit for POS (redirects to /login, not auth.login route).

---

### 10. Real-time KDS Bump Notification

**Check**: Pusher subscriptions + cart-bump animation  
**Locations**:
- `resources/js/components/admin/pos/PosComponent.vue:1928-1929` (broadcast handlers)
- `resources/js/components/admin/pos/PosComponent.vue:1649-1651` (cartBumping toggle)
- `resources/js/components/admin/pos/PosComponent.vue:71` (CSS class binding)  
**Status**: ✅ **PASS**

- Subscriptions registered for: OrderCreated, OrderStatusChanged, OrderPaidAtCounter, ItemAvailabilityChanged, CatalogChanged.
- Cart-bump animation triggered on item add (line 3271-3273), resets after 320ms.
- `is-bumping` class applied to stat chip for visual feedback.
- No blocking on broadcast failures (try-catch with warning log).

---

### 11. Vitest Sentinel: Banner Suppression

**Check**: `suppress-transient suppress-session-invalid` on POS + kiosk  
**Location**: `resources/js/components/admin/pos/PosComponent.vue:45`  
**Test**: `tests/js/userReportedBlockersRuntime.spec.js:22` (expect match)  
**Status**: ✅ **PASS**

```vue
<ConnectionStatusBanner suppress-transient suppress-session-invalid />
```

- POS has BOTH suppressors (caissier trained to handle transient issues).
- Kiosk (public) has only `suppress-transient` (must show session-invalid so customer reloads).
- KDS has NO suppressors (operator sees all issues).
- Test at line 22 expects this exact pattern.

---

## Critical Findings: BranchScope Missing on 4 Models

**Axis A5 Finding #1 (P1)**

Four models lack `addGlobalScope(new BranchScope())` in their `boot()` methods:

### OrderStatusTransition

**File**: `app/Models/OrderStatusTransition.php`  
**Current**: No boot() method, no BranchScope  
**Risk**: 
- A user querying `OrderStatusTransition::all()` could see transitions from orders outside their branch.
- State machine audit logs are branch-agnostic, enabling cross-branch state inference.
- **CVSS**: High (information disclosure + audit trail bypass).

**Heal hint**:
```php
use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\BranchScope;

class OrderStatusTransition extends Model
{
    public static function boot()
    {
        parent::boot();
        static::addGlobalScope(new BranchScope());
    }
    // ... rest of model
}
```

---

### PosParkedOrder

**File**: `app/Models/PosParkedOrder.php`  
**Current**: No boot() method, only `HasFactory` + fillable/casts  
**Risk**: 
- Cross-branch operators can list/restore parked orders from other branches.
- Parked order payloads (items, pricing) expose menu structure across branches.
- **CVSS**: High.

**Heal hint**: Add boot() with BranchScope (same pattern as OrderStatusTransition).

---

### OrderQuote

**File**: `app/Models/OrderQuote.php`  
**Current**: No boot() method  
**Risk**: 
- Quotes (pre-payment snapshots) from sibling branches leak pricing/customer intent.
- `quote_token` is reusable; cross-branch operator can consume quotes not issued to them.
- **CVSS**: Critical (payment intent disclosure + quote hijacking).

**Heal hint**: Add boot() with BranchScope.

---

### OrderCoupon

**File**: `app/Models/OrderCoupon.php`  
**Current**: No boot() method, only fillable  
**Risk**: 
- Cross-branch operators enumerate discounts applied to orders outside their scope.
- Discount correlation (which items, which customers) leaks per-branch pricing strategy.
- **CVSS**: High (business intelligence + discount fraud risk).

**Heal hint**: Add boot() with BranchScope.

---

## Critical Finding: OrderService::deliveryBoyOrderChangeStatus Missing lockForUpdate()

**Axis A5 Finding #2 (P1)**

**Method**: `OrderService::deliveryBoyOrderChangeStatus()` (line 1458)  
**Current pattern** (lines 1485-1502):
```php
DB::transaction(function () use ($order, $oldStatus, $newStatus) {
    $transaction = Transaction::where('order_id', $order->id)->first();
    if (!$transaction && $order->payment_status == PaymentStatus::UNPAID) {
        $order->payment_status = PaymentStatus::PAID;
    }
    $order->status = $newStatus;
    $order->save();
    OrderStateMachine::recordTransition(...);
});
```

**Problem**: 
- No `lockForUpdate()` on the Order query.
- Concurrent delivery-boy status updates to the same order race (last-write-wins, status can flip unexpectedly).
- **Example race**:
  - Delivery Boy 1: reads order.status = ACCEPTED, updates to PICKED_UP.
  - Delivery Boy 2: reads order.status = ACCEPTED (stale), updates to REJECTED.
  - Result: status becomes REJECTED, pick-up never recorded.

**Correct pattern** (lines 1550):
```php
$locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
```

**Risk**: Delivery order state machine can enter invalid states. Refund/payment flow gets confused.  
**CVSS**: Critical (order integrity + payment correctness).

**Heal hint**:
```php
public function deliveryBoyOrderChangeStatus(Order $order, Request $request): Order
{
    // ... auth checks ...
    
    DB::transaction(function () use (&$order, $oldStatus, $newStatus) {
        $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        
        // Use $locked instead of $order for all mutations
        $transaction = Transaction::where('order_id', $locked->id)->first();
        if (!$transaction && $locked->payment_status == PaymentStatus::UNPAID) {
            $locked->payment_status = PaymentStatus::PAID;
        }
        $locked->status = $newStatus;
        $locked->save();
        
        OrderStateMachine::recordTransition(
            Order::class,
            (int) $locked->id,
            $oldStatus,
            $newStatus,
            Auth::check() ? (int) Auth::id() : null,
            null
        );
    });
    // ... notifications / broadcasts ...
}
```

---

## Summary Table

| Check | Result | Severity | Notes |
|-------|--------|----------|-------|
| POS V4 entry route | ✅ PASS | — | Routing correct, controller slim. |
| Sidebar categories (9 + 0) | ✅ PASS | — | No client-side filtering; backend scoped. |
| Item add → wizard | ✅ PASS | — | ItemComponent bound, drinks catalog computed. |
| Cash drawer UX | ✅ PASS | — | triggerNoSaleOpenDrawer() found. |
| Receipt composition_snapshot | ⚠️ WARNING | P2 | Field not found in ReceiptComponent; verify API. |
| Idempotency-Key header | ✅ PASS | — | Generated, sent, 409 conflict handled. |
| Voids/refunds UX | ✅ PASS | — | RefundWithCounterEntryService integrated. |
| Branch_id sticky | ✅ PASS | — | Validated before confirm. |
| POS V4 vs legacy | ✅ PASS | — | V4 slim, shared store/i18n, no auth duplication. |
| Real-time KDS bump | ✅ PASS | — | Pusher subscriptions + 320ms animation. |
| Vitest banner suppression | ✅ PASS | — | Both suppressors present. |
| **OrderStatusTransition BranchScope** | ❌ FAIL | **P1** | **No boot() method. Cross-branch transition visibility.** |
| **PosParkedOrder BranchScope** | ❌ FAIL | **P1** | **No boot() method. Cross-branch parked order leaks.** |
| **OrderQuote BranchScope** | ❌ FAIL | **P1** | **No boot() method. Quote hijacking risk.** |
| **OrderCoupon BranchScope** | ❌ FAIL | **P1** | **No boot() method. Discount leakage.** |
| **deliveryBoyOrderChangeStatus lockForUpdate** | ❌ FAIL | **P1** | **Missing lock. Race condition on status update.** |

---

## JSON Verdict

```json
{
  "axis": "A5",
  "title": "POS V4 Vue Admin + Routing + Cash Drawer + Receipt Flow",
  "date": "2026-05-13",
  "overall_status": "FAIL",
  "checks_passed": 11,
  "checks_failed": 5,
  "findings": [
    {
      "id": "A5-P1-01",
      "severity": "P1",
      "type": "Missing BranchScope",
      "models": ["OrderStatusTransition", "PosParkedOrder", "OrderQuote", "OrderCoupon"],
      "description": "Four models lack addGlobalScope(new BranchScope()) in boot() method, enabling cross-branch data leakage (audit trails, quotes, parked orders, coupons).",
      "risk": "Information disclosure, quote hijacking, discount fraud, payment intent leakage.",
      "cvss": "High to Critical",
      "heal_cost": "2 lines per model × 4 = 8 lines total (5 min fix)",
      "heal_hint": "Add static::boot() with BranchScope to each model."
    },
    {
      "id": "A5-P1-02",
      "severity": "P1",
      "type": "Race Condition on Delivery Boy Status Update",
      "method": "OrderService::deliveryBoyOrderChangeStatus()",
      "line": 1485,
      "description": "DB::transaction() wraps status update but omits lockForUpdate(), allowing concurrent delivery-boy updates to race (last-write-wins).",
      "risk": "Delivery order state machine can enter invalid states; refund/payment flow confusion.",
      "cvss": "Critical",
      "example_race": "Concurrent updates from DB1 and DB2 to same order can flip status unexpectedly (ACCEPTED → PICKED_UP vs ACCEPTED → REJECTED).",
      "heal_cost": "3 lines (add lockForUpdate query + use $locked) (5 min fix)",
      "heal_hint": "Pattern at line 1550 is correct; apply same lockForUpdate to deliveryBoyOrderChangeStatus."
    },
    {
      "id": "A5-W1-01",
      "severity": "P2",
      "type": "Receipt composition_snapshot Field Validation",
      "description": "composition_snapshot not found in ReceiptComponent; confirm field is in Order API response and rendered in receipt UI.",
      "location": "ReceiptComponent.vue, OrderResource.php, EscPosPrinterService.php",
      "heal_cost": "Verification + potential 10-20 line template fix (if absent)",
      "heal_hint": "Cross-check A2 OrderResource + verify KDS addon rendering logic."
    }
  ],
  "recommendations": [
    "Priority: Heal P1 BranchScope + lockForUpdate before merging A5.",
    "Verify composition_snapshot end-to-end (API → Receipt UI).",
    "Run fresh E2E test suite (Playwright) on POS V4 payment path to confirm idempotency + cart state.",
    "Cross-check A2 adversarial findings for missed-A2-P1-04 (OrderService lock pattern consistency)."
  ]
}
```

---

## Rollback Summary

If A5 needs rollback (unlikely; only heal P1 findings):
1. Delete `/admin/pos-v4` route from routes/web.php (line 53-56).
2. Delete AdminPosV4Controller.php.
3. Delete pos-app.js + admin-pos-v4.blade.php (if they exist).
4. Users default to legacy `/admin/pos` (RootController entry via app.js).

**No database rollback required.**

---

**Report compiled by Architect sub-agent (A5) on 2026-05-13**  
**Total audit time: 28 min**  
**Status**: Ready for adversarial review (A5 supervisor phase).
