<?php

namespace Tests\Feature\Cash;

use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [SEC-FALSIFY-2026-06-08 POS-1-01] Pre-Z refund (PaymentService::cashBack) must
 * record a cash-drawer OUT for ONLY the cash-settled portion of the refunded
 * order — NOT the full order total method-blind.
 *
 * The bug: a counter-collected CARD / TICKET_RESTAURANT / online sale records NO
 * cash IN (confirmCounterPayment's IN hook fires only for CASH), but the pre-Z
 * cashBack recorded a DIRECTION_OUT cash_movement of the FULL order->total. That
 * understated expected drawer cash at close → a false OVERAGE variance (can trip
 * the manager-approval gate). The post-Z sister path
 * (RefundWithCounterEntryService) already refunds only the CASH tranches; this
 * brings the pre-Z path into agreement.
 *
 * Invariant: cashback OUT amount == cash that actually entered the till for the
 * sale (CASH single-tender full; non-CASH zero; split = CASH tranches only).
 */
class PreZRefundCashPortionOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function openSession(Branch $branch, User $cashier): CashDrawerSession
    {
        return CashDrawerSession::create([
            'branch_id'         => $branch->id,
            'opened_by_user_id' => $cashier->id,
            'opened_at'         => now(),
            'opening_amount'    => 100.00,
            'status'            => CashDrawerSession::STATUS_OPEN,
        ]);
    }

    private function paidCounterOrder(Branch $branch, User $cashier, int $mode, float $total): Order
    {
        $order = Order::factory()->create([
            'branch_id'          => $branch->id,
            'user_id'            => $cashier->id,
            'order_type'         => OrderType::POS,
            'total'              => $total,
            'subtotal'           => $total,
            'total_tax'          => 0,
            'pos_payment_method' => $mode, // set by confirmCounterPayment:326 in prod
        ]);

        // cashBack() requires a prior `payment` transaction to fire.
        Transaction::create([
            'order_id'       => $order->id,
            'transaction_no' => 'PAY-'.$order->id,
            'amount'         => $total,
            'payment_method' => $mode === PosPaymentMethod::CASH ? 'counter_cash' : 'counter_card',
            'sign'           => '+',
            'type'           => 'payment',
        ]);

        return $order;
    }

    public function test_card_counter_refund_records_no_cashback_out_movement(): void
    {
        $branch  = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $session = $this->openSession($branch, $cashier);
        $order   = $this->paidCounterOrder($branch, $cashier, PosPaymentMethod::CARD, 24.00);

        $this->actingAs($cashier, 'sanctum');
        app(PaymentService::class)->cashBack($order, 'card', 'RTN-'.$order->id);

        // No cash physically entered the till for a CARD sale, so no cash leaves on refund.
        $this->assertFalse(
            CashMovement::where('order_id', $order->id)
                ->where('direction', CashMovement::DIRECTION_OUT)
                ->exists(),
            'a CARD counter refund must NOT record a cash-drawer OUT (phantom drawer deficit)'
        );
        // And it must NOT be mis-flagged as an unsessioned cash-OUT skip either —
        // there simply is no cash leg for this sale.
        $this->assertNull(
            $order->fresh()->cash_movement_out_skipped_at,
            'a CARD refund has no cash leg → no OUT-skip marker'
        );
    }

    public function test_cash_counter_refund_still_records_full_out_movement(): void
    {
        $branch  = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $session = $this->openSession($branch, $cashier);
        $order   = $this->paidCounterOrder($branch, $cashier, PosPaymentMethod::CASH, 24.00);

        $this->actingAs($cashier, 'sanctum');
        app(PaymentService::class)->cashBack($order, 'cash', 'RTN-'.$order->id);

        $out = CashMovement::where('order_id', $order->id)
            ->where('direction', CashMovement::DIRECTION_OUT)
            ->first();
        $this->assertNotNull($out, 'a CASH counter refund must record the cash-drawer OUT (unchanged)');
        $this->assertEquals(24.00, (float) $out->amount, 'CASH refund OUT == full total (cash that entered the till)');
    }

    public function test_split_tender_refund_records_only_the_cash_tranche(): void
    {
        $branch  = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $session = $this->openSession($branch, $cashier);

        // POS direct split-tender: 30 CASH + 20 CARD = 50 total. Only 30 cash entered the till.
        $order = Order::factory()->create([
            'branch_id'  => $branch->id,
            'user_id'    => $cashier->id,
            'order_type' => OrderType::POS,
            'total'      => 50.00,
            'subtotal'   => 50.00,
            'total_tax'  => 0,
        ]);
        OrderPayment::create(['order_id' => $order->id, 'branch_id' => $branch->id, 'mode' => PosPaymentMethod::CASH, 'amount' => 30.00]);
        OrderPayment::create(['order_id' => $order->id, 'branch_id' => $branch->id, 'mode' => PosPaymentMethod::CARD, 'amount' => 20.00]);
        Transaction::create([
            'order_id' => $order->id, 'transaction_no' => 'PAY-'.$order->id, 'amount' => 50.00,
            'payment_method' => 'split', 'sign' => '+', 'type' => 'payment',
        ]);

        $this->actingAs($cashier, 'sanctum');
        app(PaymentService::class)->cashBack($order, 'cash', 'RTN-'.$order->id);

        $out = CashMovement::where('order_id', $order->id)
            ->where('direction', CashMovement::DIRECTION_OUT)
            ->first();
        $this->assertNotNull($out, 'a split refund records the CASH tranche OUT');
        $this->assertEquals(30.00, (float) $out->amount, 'split refund OUT == CASH tranche only (30), not full total (50)');
    }
}
