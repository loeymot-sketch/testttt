<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M10-01 — NF525 cash trail for unsessioned cash collection.
 *
 * When a CASH order is collected with NO open cash-drawer session, the order
 * goes PAID but no cash_movement row is written. Previously the only trace was
 * a transient in-memory `cash_movement_skipped` flag (HTTP-response-only) that
 * vanished — EOD reconciliation could not find the under-counted cash. The fix
 * persists `orders.cash_movement_skipped_at` so the gap is queryable.
 */
class CashSkipTrailNf525Test extends TestCase
{
    use RefreshDatabase;

    private function deferredKioskOrder(Branch $branch): Order
    {
        return Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create(['branch_id' => $branch->id])->id,
            'order_type' => OrderType::POS,
            'status' => OrderStatus::ACCEPT,
            'source_surface' => 'kiosk',
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'total' => 15.00,
        ]);
    }

    public function test_cash_collected_without_open_session_persists_queryable_skip_marker(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $order = $this->deferredKioskOrder($branch);

        // NO open cash-drawer session for the cashier.
        $this->actingAs($cashier, 'sanctum');
        app(PaymentService::class)->confirmCounterPayment($order, PosPaymentMethod::CASH, 15.00, 'no-session collect');

        $fresh = $order->fresh();
        $this->assertNotNull(
            $fresh->cash_movement_skipped_at,
            'unsessioned cash collection must leave a queryable cash_movement_skipped_at marker (M10-01)'
        );
        // EOD reconciliation can find it.
        $this->assertTrue(
            Order::whereNotNull('cash_movement_skipped_at')->whereKey($order->id)->exists()
        );
    }

    public function test_cash_collected_with_open_session_records_movement_and_no_skip(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $order = $this->deferredKioskOrder($branch);

        $session = app(CashDrawerService::class)->openSession($branch->id, $cashier->id, 100.00);

        $this->actingAs($cashier, 'sanctum');
        app(PaymentService::class)->confirmCounterPayment($order, PosPaymentMethod::CASH, 15.00, 'sessioned collect');

        $fresh = $order->fresh();
        $this->assertNull(
            $fresh->cash_movement_skipped_at,
            'with an open session a cash_movement is recorded — no skip marker'
        );
        $this->assertTrue(
            CashMovement::where('cash_drawer_session_id', $session->id)->exists(),
            'a cash_movement must be recorded against the open session'
        );
    }
}
