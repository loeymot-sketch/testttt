<?php

namespace Tests\Feature\Refund;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Order\RefundWithCounterEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * [iter15-P0-10] Sentinel: refund mirror Order MUST also mirror split-payment
 * tranches with negated amounts.
 *
 * Pre-fix: `RefundWithCounterEntryService::execute()` created a mirror Order
 * with negated total/subtotal/tax + items but ZERO order_payments rows. The Z
 * report aggregator (`ZReportService::aggregate()`) breaks out per-mode
 * totals from `order_payments` — so split-payment refunds (e.g. 10€ cash +
 * 40€ card) were under-credited per mode: cash kept its full +10€ and
 * card kept its full +40€ in the Z, even though the customer was refunded.
 *
 * Post-fix invariants:
 *   1. For every parent OrderPayment row, a mirror row exists on the mirror
 *      Order with `amount` and `change_amount` negated, same `mode`,
 *      reference suffixed `-REFUND`.
 *   2. Σ mirror payments == -Σ parent payments (per mode and total).
 *   3. Idempotent: a duplicate execute() attempt on an already-RETURNED
 *      parent throws (existing guard) — no double mirror payment rows.
 */
class RefundMirrorSplitPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function branch(): Branch
    {
        return Branch::factory()->create();
    }

    private function actingUserOnBranch(Branch $branch): User
    {
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Auth::setUser($user);
        return $user;
    }

    private function sealZ(int $branchId, Carbon $opened, Carbon $closed): ZReport
    {
        return ZReport::create([
            'branch_id'   => $branchId,
            'sequence_no' => 1,
            'opened_at'   => $opened,
            'closed_at'   => $closed,
            'status'      => ZReport::STATUS_CLOSED,
        ]);
    }

    /**
     * Build a parent Order inside a CLOSED Z window with the given split tranches.
     * Each tranche is [mode_int, amount_float, ?reference_string].
     */
    private function makeParentWithSplit(Branch $branch, array $tranches, float $totalOverride = null): Order
    {
        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        $this->sealZ($branch->id, $opened, $closed);

        $sum = $totalOverride ?? array_sum(array_column($tranches, 1));

        $order = Order::factory()->create([
            'branch_id'      => $branch->id,
            'status'         => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'subtotal'       => $sum,
            'total'          => $sum,
            'total_tax'      => 0,
            'created_at'     => $opened->copy()->addHours(2),
        ]);
        $order->fiscal_sequence_no = 100;
        $order->save();

        foreach ($tranches as [$mode, $amount, $ref]) {
            OrderPayment::create([
                'order_id'      => $order->id,
                'branch_id'     => $branch->id,
                'mode'          => $mode,
                'amount'        => $amount,
                'tendered'      => $amount,
                'change_amount' => 0,
                'reference'     => $ref,
                'paid_at'       => now(),
            ]);
        }

        return $order->fresh();
    }

    public function test_refund_mirror_creates_negated_payment_rows_per_tranche(): void
    {
        $branch = $this->branch();
        $this->actingUserOnBranch($branch);

        // Split: 10€ cash + 40€ card → total 50€
        $parent = $this->makeParentWithSplit($branch, [
            [1, 10.00, 'CASH-REG-1'],   // mode 1 = CASH
            [2, 40.00, 'VISA-1234'],    // mode 2 = CARD
        ]);

        $mirror = app(RefundWithCounterEntryService::class)
            ->execute($parent, 'split-payment refund');

        $this->assertNotNull($mirror);
        $this->assertSame(OrderStatus::RETURNED, (int) $mirror->status);

        $mirrorPayments = OrderPayment::withoutGlobalScopes()
            ->where('order_id', $mirror->id)
            ->orderBy('mode')
            ->get();

        $this->assertCount(2, $mirrorPayments, 'One mirror OrderPayment per parent tranche.');

        // Tranche cash mirrored as -10€
        $cashMirror = $mirrorPayments->firstWhere('mode', 1);
        $this->assertNotNull($cashMirror);
        $this->assertEqualsWithDelta(-10.00, (float) $cashMirror->amount, 0.001);
        $this->assertSame('CASH-REG-1-REFUND', (string) $cashMirror->reference);

        // Tranche card mirrored as -40€
        $cardMirror = $mirrorPayments->firstWhere('mode', 2);
        $this->assertNotNull($cardMirror);
        $this->assertEqualsWithDelta(-40.00, (float) $cardMirror->amount, 0.001);
        $this->assertSame('VISA-1234-REFUND', (string) $cardMirror->reference);
    }

    public function test_refund_z_reconciliation_under_credits_zero_after_fix(): void
    {
        $branch = $this->branch();
        $this->actingUserOnBranch($branch);

        // Original: 25€ cash + 75€ card.
        $parent = $this->makeParentWithSplit($branch, [
            [1, 25.00, null],
            [2, 75.00, 'PAN-9999'],
        ], totalOverride: 100.00);

        app(RefundWithCounterEntryService::class)
            ->execute($parent, 'full refund');

        // Per-mode aggregate across BOTH parent and mirror should net to 0.
        $perModeNet = OrderPayment::withoutGlobalScopes()
            ->whereIn('order_id', [$parent->id, $parent->id + 1])
            ->selectRaw('mode, SUM(amount) as net')
            ->groupBy('mode')
            ->pluck('net', 'mode');

        // Both mode 1 (cash) and mode 2 (card) must net to 0.
        $this->assertEqualsWithDelta(0.0, (float) $perModeNet[1], 0.001, 'Cash mode net must be 0 after refund.');
        $this->assertEqualsWithDelta(0.0, (float) $perModeNet[2], 0.001, 'Card mode net must be 0 after refund.');
    }

    public function test_refund_idempotent_no_double_mirror_payments(): void
    {
        $branch = $this->branch();
        $this->actingUserOnBranch($branch);

        $parent = $this->makeParentWithSplit($branch, [
            [1, 30.00, null],
        ]);

        try {
            app(RefundWithCounterEntryService::class)->execute($parent, 'first refund');
        } catch (\Throwable $t) {
            $this->fail('First refund unexpectedly threw: ' . get_class($t) . ' :: ' . $t->getMessage() . "\n" . $t->getTraceAsString());
        }

        // Second attempt: the parent itself remains immutable per NF525 (status
        // is NOT mutated to RETURNED on the parent — the mirror carries the
        // RETURNED status). The existing duplicate-mirror guard is "parent
        // status === RETURNED", which fires only when an OUT-OF-BAND process
        // had already flipped the parent. To prove idempotency for our P0-10
        // payment-mirror addition, we forcibly set parent.status = RETURNED
        // and confirm the guard still rejects + no second payment row leaks.
        $parent->status = OrderStatus::RETURNED;
        $parent->save();

        $orderCountBefore = Order::count();
        $paymentCountBefore = OrderPayment::withoutGlobalScopes()->count();

        $threw = false;
        try {
            app(RefundWithCounterEntryService::class)
                ->execute($parent->fresh(), 'duplicate attempt');
        } catch (\InvalidArgumentException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Duplicate refund must be rejected by the existing guard.');
        $this->assertSame($orderCountBefore, Order::count(), 'No new mirror order on duplicate.');
        $this->assertSame($paymentCountBefore, OrderPayment::withoutGlobalScopes()->count(), 'No double mirror payments on duplicate.');
    }
}
