<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Order\RefundWithCounterEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * @FK-ID WAVE5-POS-001
 * @source reports/execution/audit_2026-05-07/FINAL_REPORT_v3_WAVE5_CONSOLIDATED_2026-05-08.md §1.2
 *
 * Pre-fix, RefundWithCounterEntryService::execute() only checked that the
 * parent had a non-null `fiscal_sequence_no` — but POS orders receive a fiscal
 * sequence at posOrderStore time, BEFORE Z closure. So a parent created at 10h
 * inside the still-open Z could trigger a mirror counter-entry at 10h05, AND a
 * separate cashier could subsequently transition the still-mutable parent to
 * RETURNED via the standard pre-Z path → 2 negatives for 1 sale in the same Z.
 *
 * Fix: SealedOrderGuard::assertSealed() must be invoked before the mirror
 * transaction. This sentinel locks the invariant in place.
 *
 * 4 cases:
 *   1. Pre-Z parent (no CLOSED Z window) → throws InvalidArgumentException, no mirror row
 *   2. Post-Z parent (CLOSED Z window matches) → success
 *   3. Already-mirror'd parent (status=RETURNED) → existing duplicate-mirror guard still active
 *   4. Feature-flag rollback (sealed_z_guard_enabled=false) → guard no-op
 */
class RefundCounterEntryRequiresSealedParentSentinelTest extends TestCase
{
    use RefreshDatabase;

    private function makeFiscalOrderAt(int $branchId, Carbon $createdAt, int $fiscalSeq = 100): Order
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

    private function sealZ(int $branchId, Carbon $opened, Carbon $closed, int $seq = 1): ZReport
    {
        return ZReport::create([
            'branch_id'   => $branchId,
            'sequence_no' => $seq,
            'opened_at'   => $opened,
            'closed_at'   => $closed,
            'status'      => ZReport::STATUS_CLOSED,
        ]);
    }

    public function test_pre_z_parent_blocks_mirror_creation(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Auth::setUser($user);

        // Parent order with fiscal_sequence_no but NO closed Z covering it
        // → "Z still open at 10h05" scenario.
        $parent = $this->makeFiscalOrderAt($branch->id, Carbon::now()->subMinutes(5));

        $orderCountBefore = Order::count();

        try {
            app(RefundWithCounterEntryService::class)->execute($parent, 'pre-Z attempt');
            $this->fail('RefundWithCounterEntryService must reject pre-Z parents.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('refund-with-counter-entry', $e->getMessage());
            $this->assertSame(422, $e->getCode());
        }

        // Atomic guarantee: NO mirror row created (transaction did not begin).
        $this->assertSame($orderCountBefore, Order::count(),
            'Mirror order must NOT be persisted when parent is pre-Z.');
    }

    public function test_post_z_parent_allows_mirror_creation(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Auth::setUser($user);

        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        $this->sealZ($branch->id, $opened, $closed);

        // Parent inside the CLOSED Z window.
        $parent = $this->makeFiscalOrderAt($branch->id, $opened->copy()->addHours(2));

        $mirror = app(RefundWithCounterEntryService::class)
            ->execute($parent, 'legitimate post-Z refund');

        $this->assertNotNull($mirror);
        $this->assertSame((int) $parent->id, (int) $mirror->parent_order_id);
        $this->assertSame(OrderStatus::RETURNED, (int) $mirror->status);
        $this->assertEqualsWithDelta(-50.0, (float) $mirror->total, 0.01);
    }

    public function test_already_mirrored_parent_still_rejected(): void
    {
        // Defense-in-depth: the existing "parent already RETURNED" guard
        // remains active after the new sealed check.
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Auth::setUser($user);

        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        $this->sealZ($branch->id, $opened, $closed);

        $parent = $this->makeFiscalOrderAt($branch->id, $opened->copy()->addHours(2));
        $parent->status = OrderStatus::RETURNED;
        $parent->save();

        $this->expectException(\InvalidArgumentException::class);
        app(RefundWithCounterEntryService::class)->execute($parent->fresh(), 'duplicate attempt');
    }

    public function test_feature_flag_disabled_bypasses_seal_check(): void
    {
        // Emergency rollback knob — fiscal.sealed_z_guard_enabled=false
        // disables BOTH assertMutable() and assertSealed() for symmetry.
        config(['fiscal.sealed_z_guard_enabled' => false]);

        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Auth::setUser($user);

        // Pre-Z parent (no closed Z) — would normally be rejected.
        $parent = $this->makeFiscalOrderAt($branch->id, Carbon::now()->subMinutes(5));

        $mirror = app(RefundWithCounterEntryService::class)
            ->execute($parent, 'rollback-mode pre-Z refund');

        $this->assertNotNull($mirror, 'With guard disabled, pre-Z mirror must succeed (legacy behavior).');
        $this->assertSame(OrderStatus::RETURNED, (int) $mirror->status);
    }
}
