<?php

namespace Tests\Feature\Cash;

use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * [AUDIT-F-003 / Sub-task 4] PaymentService cash hooks.
 *
 * Verrouille:
 *   I-A confirmCounterPayment(CASH) avec session OPEN → CashMovement
 *       order_payment / direction=in, amount=order.total, orderId=order.id
 *   I-B confirmCounterPayment(CASH) sans session → 0 movement, log warning,
 *       l'order reste PAID (jamais bloquant).
 *   I-C confirmCounterPayment(CARD) → 0 movement (pas cash).
 *   I-D cashBack avec session OPEN → CashMovement cashback / direction=out.
 *   I-E hook idempotent: re-confirm sur déjà PAID → 0 nouveau movement
 *       (l'hook ne se déclenche que sur la transition réussie).
 */
class PaymentServiceCashHookTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $cashier;
    private CashDrawerService $cashService;
    private PaymentService $paymentService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Event::fake();

        $this->branch = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');
        $this->cashier->givePermissionTo('pos');

        $this->cashService = app(CashDrawerService::class);
        $this->paymentService = app(PaymentService::class);

        $this->actingAs($this->cashier);
    }

    private function makeKioskCashOrder(float $total = 25.00): Order
    {
        return Order::factory()->create([
            'branch_id'           => $this->branch->id,
            'user_id'             => $this->cashier->id,
            'total'               => $total,
            'subtotal'            => $total,
            'discount'            => 0,
            'total_tax'           => 0,
            'delivery_charge'     => 0,
            'status'              => OrderStatus::PENDING,
            'payment_status'      => PaymentStatus::UNPAID,
            'payment_method'      => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method'  => PosPaymentMethod::COUNTER_DEFERRED,
            'source_surface'      => 'kiosk',
        ]);
    }

    /** I-A — CASH + session OPEN → movement créé */
    public function test_confirm_counter_cash_with_open_session_records_in_movement(): void
    {
        $session = $this->cashService->openSession($this->branch->id, $this->cashier->id, 100.00);
        $order = $this->makeKioskCashOrder(42.50);

        $this->paymentService->confirmCounterPayment($order, PosPaymentMethod::CASH);

        $movements = CashMovement::query()
            ->where('cash_drawer_session_id', $session->id)
            ->get();

        $this->assertCount(1, $movements);
        $this->assertSame($order->id, $movements->first()->order_id);
        $this->assertSame('42.50', (string) $movements->first()->amount);
        $this->assertSame(CashMovement::DIRECTION_IN, $movements->first()->direction);
        $this->assertSame(CashMovement::TYPE_ORDER_PAYMENT, $movements->first()->type);
        $this->assertSame($this->branch->id, $movements->first()->branch_id);
    }

    /** I-B — CASH sans session → 0 movement, order toujours PAID */
    public function test_confirm_counter_cash_without_session_skips_movement_but_order_paid(): void
    {
        $order = $this->makeKioskCashOrder(20.00);

        $result = $this->paymentService->confirmCounterPayment($order, PosPaymentMethod::CASH);

        $this->assertSame(0, CashMovement::query()->count());
        $this->assertSame(PaymentStatus::PAID, (int) $result->payment_status);
    }

    /** I-C — CARD → 0 movement */
    public function test_confirm_counter_card_does_not_record_cash_movement(): void
    {
        $this->cashService->openSession($this->branch->id, $this->cashier->id, 100.00);
        $order = $this->makeKioskCashOrder(33.33);

        $this->paymentService->confirmCounterPayment($order, PosPaymentMethod::CARD);

        $this->assertSame(0, CashMovement::query()->count());
    }

    /** I-D — cashBack → movement direction=out */
    public function test_cashback_records_out_movement_on_open_session(): void
    {
        $session = $this->cashService->openSession($this->branch->id, $this->cashier->id, 100.00);

        $order = Order::factory()->create([
            'branch_id'      => $this->branch->id,
            'user_id'        => $this->cashier->id,
            'total'          => 15.00,
            'subtotal'       => 15.00,
            'discount'       => 0,
            'total_tax'      => 0,
            'delivery_charge' => 0,
            'payment_status' => PaymentStatus::PAID,
            'status'         => OrderStatus::DELIVERED,
        ]);

        // Pré-condition: transaction "payment" existe (cashBack le requiert)
        \App\Models\Transaction::create([
            'order_id'       => $order->id,
            'transaction_no' => 'TXN-INIT-1',
            'amount'         => $order->total,
            'payment_method' => 'cash',
            'sign'           => '+',
            'type'           => 'payment',
        ]);

        $this->paymentService->cashBack($order, 'cash', 'TXN-CASHBACK-1');

        $movements = CashMovement::query()
            ->where('cash_drawer_session_id', $session->id)
            ->get();

        $this->assertCount(1, $movements);
        $this->assertSame(CashMovement::DIRECTION_OUT, $movements->first()->direction);
        $this->assertSame(CashMovement::TYPE_CASHBACK, $movements->first()->type);
        $this->assertSame($order->id, $movements->first()->order_id);
    }

    /** I-E — re-confirm sur déjà PAID → 0 nouveau movement (hook ne se déclenche pas) */
    public function test_confirm_counter_cash_on_already_paid_order_does_not_double_record(): void
    {
        $this->cashService->openSession($this->branch->id, $this->cashier->id, 100.00);

        $order = $this->makeKioskCashOrder(10.00);
        $this->paymentService->confirmCounterPayment($order, PosPaymentMethod::CASH);
        $this->assertSame(1, CashMovement::query()->count());

        // Re-confirm — premier appel a déjà fait l'order PAID, le 2e doit être no-op (paid=false interne)
        $order->refresh();
        $this->paymentService->confirmCounterPayment($order, PosPaymentMethod::CASH);

        $this->assertSame(1, CashMovement::query()->count(), 'No second movement on already-paid order');
    }

    /** Hook ne casse pas l'order si session reconcilied */
    public function test_confirm_counter_cash_with_reconciled_session_does_not_block_order(): void
    {
        $session = $this->cashService->openSession($this->branch->id, $this->cashier->id, 100.00);
        $this->cashService->closeSession($session->id, 100.00);
        $this->cashService->reconcileSession($session->id);

        $order = $this->makeKioskCashOrder(10.00);
        $result = $this->paymentService->confirmCounterPayment($order, PosPaymentMethod::CASH);

        $this->assertSame(PaymentStatus::PAID, (int) $result->payment_status);
        // No movement (session not OPEN), but order paid OK
        $this->assertSame(0, CashMovement::query()->count());
    }
}
