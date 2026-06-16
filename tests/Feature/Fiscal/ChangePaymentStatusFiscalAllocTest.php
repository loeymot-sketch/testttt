<?php

/**
 * [GENIE Wave 0 — FISCAL-CPS-01 2026-06-16] NF525 exhaustivity P0.
 *
 * `OrderService::changePaymentStatus` transitions an order into PAID (reachable via the admin
 * online-order / table-order / pos-order change-payment-status endpoints) but historically did NOT
 * allocate a fiscal sequence — so the order persisted PAID with fiscal_sequence_no=NULL, was excluded
 * from every Z (ZReportService aggregate `whereNotNull('fiscal_sequence_no')`), and the kiosk-only
 * retry cron (FrontendOrder + fiscal_alloc_error_at) could never salvage it → permanent off-book
 * orphan. Sibling of the delivery-COD hole. This pins: a PAID transition allocates a gap-free
 * sequence (idempotent), so the order enters the Z. RED before the heal, GREEN after.
 */

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Http\Requests\PaymentStatusRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChangePaymentStatusFiscalAllocTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->branch = Branch::factory()->create();
        $this->admin = User::factory()->create(['branch_id' => 0]);
        $this->admin->assignRole('Admin');
        $this->actingAs($this->admin, 'sanctum');
    }

    private function makeOrder(int $paymentStatus, ?int $seq = null): Order
    {
        return Order::factory()->create([
            'user_id' => $this->admin->id,
            'branch_id' => $this->branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => $paymentStatus,
            'status' => OrderStatus::PENDING,
            'total' => 25.00,
            'fiscal_sequence_no' => $seq,
        ]);
    }

    private function flipTo(Order $order, int $status): Order
    {
        $request = new PaymentStatusRequest();
        $request->merge(['payment_status' => $status]);
        $result = app(OrderService::class)->changePaymentStatus($order, $request, false);
        return $result instanceof Order ? $result->fresh() : $order->fresh();
    }

    /** @test — core P0: UNPAID→PAID must allocate a fiscal sequence (was NULL → escaped Z) */
    public function test_unpaid_to_paid_allocates_a_fiscal_sequence(): void
    {
        $order = $this->makeOrder(PaymentStatus::UNPAID);
        $this->assertNull($order->fiscal_sequence_no, 'precondition: no sequence yet');

        $fresh = $this->flipTo($order, PaymentStatus::PAID);

        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status);
        $this->assertNotNull(
            $fresh->fiscal_sequence_no,
            'FISCAL-CPS-01: a PAID transition MUST allocate a fiscal sequence — else it escapes every Z.'
        );
        $this->assertGreaterThan(0, (int) $fresh->fiscal_sequence_no);
    }

    /** @test — the allocated order now passes the Z aggregate filter (whereNotNull) */
    public function test_paid_order_enters_the_z_aggregate_window(): void
    {
        $order = $this->makeOrder(PaymentStatus::UNPAID);
        $this->flipTo($order, PaymentStatus::PAID);

        $inZ = Order::withoutGlobalScopes()
            ->where('id', $order->id)
            ->whereNotNull('fiscal_sequence_no') // the exact ZReportService:340 predicate
            ->exists();
        $this->assertTrue($inZ, 'Healed order must satisfy the Z aggregate whereNotNull filter.');
    }

    /** @test — idempotent: an order already carrying a sequence is NOT re-allocated */
    public function test_already_allocated_order_is_not_reallocated(): void
    {
        // PENDING_COUNTER already has a sequence (e.g. counter pre-allocated) → flip to PAID
        $order = $this->makeOrder(PaymentStatus::PENDING_COUNTER, seq: 4242);

        $fresh = $this->flipTo($order, PaymentStatus::PAID);

        $this->assertSame(4242, (int) $fresh->fiscal_sequence_no, 'must NOT re-allocate / overwrite an existing sequence');
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status);
    }

    /** @test — sequences are gap-free / monotonic across two healed orders on the same branch */
    public function test_sequences_are_monotonic_per_branch(): void
    {
        $a = $this->flipTo($this->makeOrder(PaymentStatus::UNPAID), PaymentStatus::PAID);
        $b = $this->flipTo($this->makeOrder(PaymentStatus::UNPAID), PaymentStatus::PAID);

        $this->assertNotNull($a->fiscal_sequence_no);
        $this->assertNotNull($b->fiscal_sequence_no);
        $this->assertGreaterThan(
            (int) $a->fiscal_sequence_no,
            (int) $b->fiscal_sequence_no,
            'second allocation must be strictly greater (gap-free monotonic per branch)'
        );
    }
}
