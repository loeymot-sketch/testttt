<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @FK-ID RED-DASH-02 (P0, ultra-audit 2026-06-10) | @plan plans/GOAL_ULTRA_AUDIT_SYSTEMES_2026-06-10.md LOT B
 *
 * Off-book settlement guard: the generic change-payment-status endpoints must
 * NEVER settle a sale (→ PAID) when the order carries zero tender trace
 * (no fiscal_sequence_no, no OrderPayment, no Transaction). Live repro before
 * fix: order 4496 flipped PENDING_COUNTER→PAID via admin dropdown with HTTP
 * 200 and no fiscal allocation — an off-book sale (NF525 integrity breach).
 * Legitimate settlement paths (PaymentService::confirmCounterPayment, gateway
 * flows) always record a tender trace BEFORE/WITH the status flip.
 */
class ChangePaymentStatusOffBookGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
        $this->admin  = User::factory()->create(['branch_id' => 0]);
        $this->admin->assignRole('Admin');
    }

    private function makeOrder(int $paymentStatus, int $orderType = OrderType::TAKEAWAY): Order
    {
        return Order::factory()->create([
            'user_id'        => $this->admin->id,
            'branch_id'      => $this->branch->id,
            'order_type'     => $orderType,
            'payment_status' => $paymentStatus,
            'status'         => OrderStatus::PENDING,
        ]);
    }

    public function test_pending_counter_to_paid_without_tender_trace_is_blocked_422(): void
    {
        $order = $this->makeOrder(PaymentStatus::PENDING_COUNTER);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/online-order/change-payment-status/' . $order->id, [
                'payment_status' => PaymentStatus::PAID,
            ]);

        $response->assertStatus(422);
        $this->assertSame(
            PaymentStatus::PENDING_COUNTER,
            (int) Order::withoutGlobalScopes()->findOrFail($order->id)->payment_status,
            'Off-book settlement must NOT mutate the order.'
        );
    }

    public function test_unpaid_to_paid_without_tender_trace_is_blocked_422(): void
    {
        $order = $this->makeOrder(PaymentStatus::UNPAID);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/online-order/change-payment-status/' . $order->id, [
                'payment_status' => PaymentStatus::PAID,
            ]);

        $response->assertStatus(422);
        $this->assertSame(
            PaymentStatus::UNPAID,
            (int) Order::withoutGlobalScopes()->findOrFail($order->id)->payment_status
        );
    }

    public function test_unpaid_to_paid_with_transaction_trace_passes(): void
    {
        $order = $this->makeOrder(PaymentStatus::UNPAID);
        Transaction::create([
            'order_id'       => $order->id,
            'transaction_no' => 'test-trace-' . $order->id,
            'amount'         => (float) $order->total,
            'payment_method' => '1',
            'type'           => 'payment',
            'sign'           => '+',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/online-order/change-payment-status/' . $order->id, [
                'payment_status' => PaymentStatus::PAID,
            ]);

        $response->assertSuccessful();
        $this->assertSame(
            PaymentStatus::PAID,
            (int) Order::withoutGlobalScopes()->findOrFail($order->id)->payment_status,
            'A gateway-style flow (Transaction trace recorded first) must still be able to flip to PAID.'
        );
    }

    /**
     * FISC-EXH-CPS-01 (defense-in-depth, ultra-review 2026-06-14). The tender-trace
     * guard lets a flip to PAID through when an OrderPayment/Transaction trace exists
     * — but if that trace carries NO fiscal_sequence_no, the now-realized PAID sale
     * escapes every Z (ZReportService aggregates whereNotNull(seq)) and the retry-cron
     * never sees it (no fiscal_alloc_error_at). changePaymentStatus must allocate a
     * gap-free seq on the PAID flip when one is missing, mirroring the kiosk/COD paths.
     */
    public function test_paid_flip_with_trace_but_no_seq_allocates_fiscal_sequence(): void
    {
        $order = $this->makeOrder(PaymentStatus::UNPAID);
        Transaction::create([
            'order_id'       => $order->id,
            'transaction_no' => 'cps-trace-' . $order->id,
            'amount'         => (float) $order->total,
            'payment_method' => '1',
            'type'           => 'payment',
            'sign'           => '+',
        ]);
        $this->assertNull(
            Order::withoutGlobalScopes()->findOrFail($order->id)->fiscal_sequence_no,
            'precondition: trace exists, no seq yet'
        );

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/online-order/change-payment-status/' . $order->id, [
                'payment_status' => PaymentStatus::PAID,
            ]);

        $response->assertSuccessful();
        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status);
        $this->assertNotNull(
            $fresh->fiscal_sequence_no,
            'A PAID flip on a traced-but-seq-less order MUST allocate a fiscal_sequence_no (NF525 exhaustivity).'
        );
    }

    public function test_pending_counter_with_fiscal_sequence_passes(): void
    {
        $order = $this->makeOrder(PaymentStatus::PENDING_COUNTER);
        $order->forceFill(['fiscal_sequence_no' => 424242])->save();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/online-order/change-payment-status/' . $order->id, [
                'payment_status' => PaymentStatus::PAID,
            ]);

        $response->assertSuccessful();
        $this->assertSame(
            PaymentStatus::PAID,
            (int) Order::withoutGlobalScopes()->findOrFail($order->id)->payment_status
        );
    }
}
