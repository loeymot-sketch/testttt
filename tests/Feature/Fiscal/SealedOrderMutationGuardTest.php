<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\OrderSealedException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Order\RefundWithCounterEntryService;
use App\Services\Order\SealedOrderGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * @FK-ID FK-VERIFY-08-02
 * @source docs/audit/plans/PLAN_P11_FISCAL_Z_OPEN_HARDENING_2026-05-06.md
 *
 * Validates SealedOrderGuard refuses RETURNED / REFUNDED transitions on
 * orders contained in a closed Z window, and that
 * RefundWithCounterEntryService creates a mirror order in the current Z
 * window with negated financial fields and a fresh fiscal_sequence_no.
 */
class SealedOrderMutationGuardTest extends TestCase
{
    use RefreshDatabase;

    private function createSealedZWindow(int $branchId, Carbon $opened, Carbon $closed): ZReport
    {
        return ZReport::create([
            'branch_id'   => $branchId,
            'sequence_no' => 1,
            'opened_at'   => $opened,
            'closed_at'   => $closed,
            'status'      => ZReport::STATUS_CLOSED,
        ]);
    }

    private function createSealedOrder(int $branchId, Carbon $createdAt, int $fiscalSeq = 5): Order
    {
        $order = Order::factory()->create([
            'branch_id'      => $branchId,
            'status'         => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'subtotal'       => 50.00,
            'total'          => 50.00,
            'total_tax'      => 0,
            'created_at'     => $createdAt,
        ]);
        $order->fiscal_sequence_no = $fiscalSeq;
        $order->save();
        return $order;
    }

    public function test_guard_blocks_returned_when_order_in_closed_z_window(): void
    {
        $branch = Branch::factory()->create();
        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        $this->createSealedZWindow($branch->id, $opened, $closed);

        $order = $this->createSealedOrder($branch->id, $opened->copy()->addHours(2));

        $guard = app(SealedOrderGuard::class);

        $this->expectException(OrderSealedException::class);
        $guard->assertMutable($order, 'changeStatus → RETURNED');
    }

    public function test_guard_allows_returned_when_order_in_open_z_window(): void
    {
        $branch = Branch::factory()->create();
        // No closed Z report → guard is no-op
        $order = $this->createSealedOrder($branch->id, Carbon::now());

        app(SealedOrderGuard::class)->assertMutable($order, 'changeStatus → RETURNED');
        $this->assertTrue(true); // no throw
    }

    public function test_guard_noop_for_unfiscalised_order(): void
    {
        $branch = Branch::factory()->create();
        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        $this->createSealedZWindow($branch->id, $opened, $closed);

        // Order with NO fiscal_sequence_no (legacy / draft / kiosk-pending)
        $order = Order::factory()->create([
            'branch_id'  => $branch->id,
            'status'     => OrderStatus::ACCEPT,
            'created_at' => $opened->copy()->addHours(2),
        ]);

        app(SealedOrderGuard::class)->assertMutable($order, 'changeStatus → RETURNED');
        $this->assertTrue(true);
    }

    public function test_guard_disabled_via_feature_flag(): void
    {
        config(['fiscal.sealed_z_guard_enabled' => false]);

        $branch = Branch::factory()->create();
        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        $this->createSealedZWindow($branch->id, $opened, $closed);
        $order = $this->createSealedOrder($branch->id, $opened->copy()->addHours(2));

        // Should NOT throw — guard disabled.
        app(SealedOrderGuard::class)->assertMutable($order, 'changeStatus → RETURNED');
        $this->assertTrue(true);
    }

    public function test_refund_with_counter_entry_creates_mirror_order_with_negated_total(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Auth::setUser($user);

        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        $this->createSealedZWindow($branch->id, $opened, $closed);

        // Set the next fiscal seq high enough to avoid collision with the seal Z (seq 1)
        $parent = $this->createSealedOrder($branch->id, $opened->copy()->addHours(2), fiscalSeq: 100);

        $service = app(RefundWithCounterEntryService::class);
        $mirror = $service->execute($parent, 'Customer requested refund post-Z');

        $this->assertNotNull($mirror);
        $this->assertSame((int) $parent->id, (int) $mirror->parent_order_id);
        $this->assertSame(OrderStatus::RETURNED, (int) $mirror->status);
        $this->assertSame(PaymentStatus::REFUNDED, (int) $mirror->payment_status);
        $this->assertEqualsWithDelta(-50.00, (float) $mirror->total, 0.01);
        $this->assertEqualsWithDelta(-50.00, (float) $mirror->subtotal, 0.01);
        $this->assertGreaterThan((int) $parent->fiscal_sequence_no, (int) $mirror->fiscal_sequence_no);
    }

    public function test_refund_double_call_throws(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Auth::setUser($user);

        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        $this->createSealedZWindow($branch->id, $opened, $closed);
        $parent = $this->createSealedOrder($branch->id, $opened->copy()->addHours(2), fiscalSeq: 100);

        $service = app(RefundWithCounterEntryService::class);
        $service->execute($parent, 'first refund');

        // Update parent to RETURNED (simulating downstream status sync) then try again
        $parent->status = OrderStatus::RETURNED;
        $parent->save();

        $this->expectException(\InvalidArgumentException::class);
        $service->execute($parent->fresh(), 'duplicate attempt');
    }

    public function test_refund_requires_non_empty_reason(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Auth::setUser($user);

        $parent = $this->createSealedOrder($branch->id, Carbon::now(), fiscalSeq: 100);

        $this->expectException(\InvalidArgumentException::class);
        app(RefundWithCounterEntryService::class)->execute($parent, '   ');
    }

    public function test_refund_requires_parent_with_fiscal_sequence(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Auth::setUser($user);

        // Parent without fiscal_sequence_no
        $parent = Order::factory()->create([
            'branch_id'      => $branch->id,
            'status'         => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'total'          => 50.00,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        app(RefundWithCounterEntryService::class)->execute($parent, 'reason');
    }
}
