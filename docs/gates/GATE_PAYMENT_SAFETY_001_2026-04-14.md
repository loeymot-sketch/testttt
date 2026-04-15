# Gate Brief – PAYMENT_SAFETY_001 – 2026-04-14

## Trigger
E2 (F-04) requires adding loyalty point refund logic in `changeStatus()` for CANCELED/REJECTED orders in two frozen zone files:
- `app/Services/OrderService.php` (POS order cancellation)
- `app/Services/FrontendOrderService.php` (kiosk/frontend order cancellation)

Both files are frozen zones per `.cursor/rules/project-invariants.mdc`.

## Affected Subsystems
- `OrderService::changeStatus()` (L.1312-1326) — staff cancellation path inside DB::transaction
- `OrderService::changeStatus()` (L.1277-1284) — customer self-cancellation path
- `FrontendOrderService::changeStatus()` (L.593-599) — kiosk order cancellation

## What Changes

**OrderService.php — staff path (~L.1325, after cashBack block):**
```php
// Refund loyalty points if order used them
if ($order->loyalty_customer_code) {
    $loyaltyUser = \App\Models\User::where('loyalty_code', $order->loyalty_customer_code)
        ->where('status', 1)->first();
    if ($loyaltyUser && $order->discount > 0) {
        $rate = (int) \App\Models\Settings::group('loyalty_setup')->get('loyalty_points_for_1_euro_discount', 100);
        if ($rate <= 0) $rate = 100;
        $pointsToRefund = (int) ceil($order->discount * $rate);
        \Illuminate\Support\Facades\DB::table('users')->where('id', $loyaltyUser->id)->increment('loyalty_points', $pointsToRefund);
        \App\Models\LoyaltyTransaction::create([
            'user_id' => $loyaltyUser->id,
            'loyalty_code' => $loyaltyUser->loyalty_code,
            'order_id' => $order->id,
            'type' => 'refund',
            'points' => $pointsToRefund,
            'balance_after' => $loyaltyUser->loyalty_points + $pointsToRefund,
            'source_surface' => 'pos',
            'description' => 'Remboursement fidélité suite annulation commande',
        ]);
    }
}
```
Same pattern in the customer self-cancellation path (L.1284).

**FrontendOrderService.php — kiosk cancel path (~L.599, after cashBack block):**
Same pattern, with `source_surface => 'kiosk'`.

## Invariants at Risk
- **Backend pricing SSOT** — Not affected (no price calculation changes)
- **OrderService / FrontendOrderService symmetry** — Same refund pattern applied to both
- **Dispatch after DB commit** — Refund happens INSIDE the DB::transaction (OrderService staff path) or before save (FrontendOrderService), consistent with existing cashBack placement
- **Frozen zones** — Both files are frozen; gate required

## Impact Assessment
- **Existing orders:** Unaffected (no migration, no retroactive refund)
- **Future cancellations:** Loyalty points will be re-credited to the customer, LoyaltyTransaction ledger entry with `type='refund'` created
- **Risk:** Low — additive logic only, no modification of existing cashBack or status change logic
- **Rollback:** Remove the loyalty refund block — zero data impact (refunded points remain credited but no structural damage)

## Decision Required
Approve modification of both frozen zone files (OrderService.php + FrontendOrderService.php) for the sole purpose of adding loyalty point refund on order cancellation.

## Options
1. Approve — add loyalty refund to both services (symmetric, fixes F-04 CRITICAL)
2. Defer — skip E2, document the loyalty refund gap for a future cycle
3. Cancel cycle

## Approval
[x] Approved — option selected: 1
[ ] Cancelled
Approved by: Kossay (human)
Date: 2026-04-14
