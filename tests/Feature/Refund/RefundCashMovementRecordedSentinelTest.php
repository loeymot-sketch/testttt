<?php

namespace Tests\Feature\Refund;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Cash\CashDrawerService;
use App\Services\Order\RefundWithCounterEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [GOAL-K2-HEAL-07 2026-05-24] Phase K.5 NEW-2 P1
 *
 * Sentinel: RefundWithCounterEntryService MUST record a cash_movement
 * (TYPE_CASHBACK + DIRECTION_OUT) for every CASH tranche being refunded
 * via the counter-entry (post-Z) path.
 *
 * Pre-heal: the counter-entry path skipped CashMovement entirely. The Z
 * report still balanced via mirror order_payments and the NF525 audit
 * chain captured the event, but the drawer-session reconciliation
 * surface had NO refund line. Result: `expected_closing_amount`
 * silently skewed by the refunded cash amount — cashier reconciling
 * at end of shift saw an unexplained negative variance equal to the
 * day's CASH counter-entry refunds.
 *
 * Post-heal contract:
 *   1. CASH tranches → one cash_movement row per tranche, type=cashback,
 *      direction=out, amount=positive, order_id=mirror.id.
 *   2. Non-CASH tranches (card / ticket / mobile) → NO cash_movement
 *      (those modes don't touch the physical drawer).
 *   3. Drawer expected_closing_amount = opening + Σ signedAmount, where
 *      direction=out resolves to negative — so the refund is correctly
 *      subtracted from the expected count.
 *   4. Best-effort: no open drawer session → log + continue (mirrors I.1
 *      silent-fail observability discipline). Counter-entry succeeds.
 */
class RefundCashMovementRecordedSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();

        // Variance gate off so we can reconcile freely in assertions.
        Config::set('cash.variance_threshold_eur', 999.0);
        Config::set('cash.variance_manager_approval_required', false);
        Config::set('cash.manager_gate_routine_close', false);
    }

    private function sealZ(int $branchId, Carbon $opened, Carbon $closed): void
    {
        ZReport::create([
            'branch_id'   => $branchId,
            'sequence_no' => 1,
            'opened_at'   => $opened,
            'closed_at'   => $closed,
            'status'      => ZReport::STATUS_CLOSED,
        ]);
    }

    /**
     * Build a parent Order inside a CLOSED Z window with the given tranches.
     * Each tranche is [mode_int, amount_float, ?reference_string].
     */
    private function makeParentWithSplit(Branch $branch, array $tranches): Order
    {
        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        $this->sealZ($branch->id, $opened, $closed);

        $sum = array_sum(array_column($tranches, 1));

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

    /**
     * Primary invariant: CASH counter-entry refund records cash_movement
     * with the correct shape (type, direction, amount, order_id linkage).
     */
    public function test_cash_refund_via_counter_entry_records_cash_movement(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->assignRole('Admin');
        Auth::setUser($cashier);

        // 1. Open drawer session before processing the refund.
        $cashService = app(CashDrawerService::class);
        $session = $cashService->openSession($branch->id, $cashier->id, 100.00);

        // 2. Parent order paid CASH 30€ in a CLOSED Z window.
        $parent = $this->makeParentWithSplit($branch, [
            [PosPaymentMethod::CASH, 30.00, 'CASH-REG-1'],
        ]);

        $movementsBefore = CashMovement::withoutGlobalScopes()
            ->where('cash_drawer_session_id', $session->id)
            ->count();

        // 3. Trigger refund via counter-entry path.
        $mirror = app(RefundWithCounterEntryService::class)
            ->execute($parent, 'sentinel cash-refund counter-entry');

        $this->assertNotNull($mirror, 'Mirror order must be created.');

        // 4. cash_movement row created with the expected shape.
        $movement = CashMovement::withoutGlobalScopes()
            ->where('cash_drawer_session_id', $session->id)
            ->where('order_id', $mirror->id)
            ->first();

        $this->assertNotNull(
            $movement,
            'A cash_movement row MUST exist for the refund, attached to the mirror order.'
        );
        $this->assertSame(
            CashMovement::TYPE_CASHBACK,
            $movement->type,
            'Refund cash_movement must use TYPE_CASHBACK (existing semantically-correct enum value).'
        );
        $this->assertSame(
            CashMovement::DIRECTION_OUT,
            $movement->direction,
            'Refund cash_movement must use DIRECTION_OUT (sign flip happens via direction, not amount).'
        );
        $this->assertEqualsWithDelta(
            30.00,
            (float) $movement->amount,
            0.001,
            'Refund cash_movement amount must be positive 30.00 (recordMovement rejects negative amounts).'
        );
        $this->assertSame(
            (int) $mirror->id,
            (int) $movement->order_id,
            'cash_movement must reference the mirror (NF525 RETURN_OF) order, not the immutable parent.'
        );
        $this->assertSame(
            $movementsBefore + 1,
            CashMovement::withoutGlobalScopes()
                ->where('cash_drawer_session_id', $session->id)
                ->count(),
            'Exactly one cash_movement row must be added for a single CASH tranche refund.'
        );

        // 5. signedAmount semantics: direction=out → negative.
        $this->assertEqualsWithDelta(
            -30.00,
            $movement->signedAmount(),
            0.001,
            'signedAmount() must return -30.00 so expected_closing_amount aggregates correctly.'
        );
    }

    /**
     * Drawer reconciliation math: expected = opening + Σ signedAmount.
     * After (opening 100 + cash sale 30 + refund -30) the expected count
     * must be 100 — refund correctly subtracted.
     */
    public function test_drawer_expected_count_reflects_cash_refund_correctly(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->assignRole('Admin');
        Auth::setUser($cashier);

        $cashService = app(CashDrawerService::class);
        $session = $cashService->openSession($branch->id, $cashier->id, 100.00);

        // Simulate a CASH order intake first: +30€ via TYPE_ORDER_PAYMENT / DIRECTION_IN.
        // This mirrors what PaymentService::recordCashOrderMovement does on a live sale.
        $intake = $cashService->recordMovement(
            sessionId: (int) $session->id,
            type: CashMovement::TYPE_ORDER_PAYMENT,
            amount: 30.00,
            direction: CashMovement::DIRECTION_IN,
            orderId: null,
            notes: 'sentinel intake',
        );
        $this->assertNotNull($intake);

        // Now process the refund. Parent uses the same 30€ CASH amount so the
        // intake and the refund cancel out exactly.
        $parent = $this->makeParentWithSplit($branch, [
            [PosPaymentMethod::CASH, 30.00, null],
        ]);

        app(RefundWithCounterEntryService::class)
            ->execute($parent, 'reconciliation math sentinel');

        // Close drawer at exactly opening amount (100€) — physical cash
        // matches the expected because intake +30 and refund -30 net to 0.
        $cashService->closeSession($session->id, 100.00);
        $result = $cashService->reconcileSession($session->id);

        $this->assertEqualsWithDelta(
            100.00,
            (float) $result['expected'],
            0.001,
            'expected_closing_amount must = opening (100) + intake (30) - refund (30) = 100.'
        );
        $this->assertEqualsWithDelta(
            0.00,
            (float) $result['variance'],
            0.001,
            'Variance must be 0 — physical cash 100 matches expected 100 with refund correctly applied.'
        );
    }

    /**
     * Inverse: a CARD refund must NOT touch the drawer. The drawer expected
     * count is independent of card refunds (card refunds debit the payment
     * processor, not the physical drawer).
     */
    public function test_card_refund_does_not_record_cash_movement(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->assignRole('Admin');
        Auth::setUser($cashier);

        $cashService = app(CashDrawerService::class);
        $session = $cashService->openSession($branch->id, $cashier->id, 100.00);

        $parent = $this->makeParentWithSplit($branch, [
            [PosPaymentMethod::CARD, 50.00, 'VISA-1234'],
        ]);

        $mirror = app(RefundWithCounterEntryService::class)
            ->execute($parent, 'sentinel card-refund counter-entry');

        $this->assertNotNull($mirror);

        // No cash_movement attached to the mirror.
        $movementCount = CashMovement::withoutGlobalScopes()
            ->where('cash_drawer_session_id', $session->id)
            ->where('order_id', $mirror->id)
            ->count();

        $this->assertSame(
            0,
            $movementCount,
            'CARD refunds must NOT record cash_movement (card refunds bypass the physical drawer).'
        );
    }

    /**
     * Split-payment (CASH + CARD) refund: only the CASH tranche generates
     * a cash_movement row. CARD tranche is silently skipped.
     */
    public function test_split_payment_refund_records_cash_movement_only_for_cash_tranche(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->assignRole('Admin');
        Auth::setUser($cashier);

        $cashService = app(CashDrawerService::class);
        $session = $cashService->openSession($branch->id, $cashier->id, 100.00);

        $parent = $this->makeParentWithSplit($branch, [
            [PosPaymentMethod::CASH, 15.00, 'CASH-REG-1'],
            [PosPaymentMethod::CARD, 35.00, 'VISA-1234'],
        ]);

        $mirror = app(RefundWithCounterEntryService::class)
            ->execute($parent, 'sentinel split-refund counter-entry');

        $movements = CashMovement::withoutGlobalScopes()
            ->where('cash_drawer_session_id', $session->id)
            ->where('order_id', $mirror->id)
            ->get();

        $this->assertCount(
            1,
            $movements,
            'Split refund must record exactly ONE cash_movement (CASH tranche only).'
        );

        $movement = $movements->first();
        $this->assertSame(CashMovement::TYPE_CASHBACK, $movement->type);
        $this->assertSame(CashMovement::DIRECTION_OUT, $movement->direction);
        $this->assertEqualsWithDelta(
            15.00,
            (float) $movement->amount,
            0.001,
            'cash_movement amount must match the CASH tranche (15.00), not the total refund.'
        );
    }

    /**
     * Best-effort discipline: no open drawer session → log + continue.
     * Counter-entry MUST succeed (Z chain remains the source of truth for
     * the refund event; drawer gap surfaces in cron alerts per I.1).
     */
    public function test_refund_succeeds_when_no_open_drawer_session(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->assignRole('Admin');
        Auth::setUser($cashier);

        // Intentionally do NOT open a drawer session.

        $parent = $this->makeParentWithSplit($branch, [
            [PosPaymentMethod::CASH, 20.00, null],
        ]);

        $mirror = app(RefundWithCounterEntryService::class)
            ->execute($parent, 'sentinel no-session');

        $this->assertNotNull(
            $mirror,
            'Counter-entry refund MUST succeed even without an open drawer session.'
        );
        $this->assertSame(
            OrderStatus::RETURNED,
            (int) $mirror->status,
            'Mirror order must be created in RETURNED state regardless of drawer session presence.'
        );

        // No drawer session means no cash_movement could be linked.
        $this->assertSame(
            0,
            CashMovement::withoutGlobalScopes()->count(),
            'No cash_movement row should exist when no drawer session is open (silent-fail discipline).'
        );

        // Verify mirror order_payments still exist — Z chain remains the
        // authoritative refund record.
        $mirrorPayments = OrderPayment::withoutGlobalScopes()
            ->where('order_id', $mirror->id)
            ->count();
        $this->assertSame(
            1,
            $mirrorPayments,
            'Mirror order_payments MUST exist regardless of drawer session — Z chain unaffected.'
        );
    }
}
