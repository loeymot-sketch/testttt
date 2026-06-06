<?php

namespace Tests\Feature\Cash;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Order\RefundWithCounterEntryService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * CASH-01 — NF525 cash trail for unsessioned cash-OUT (refund / cashback).
 *
 * Symmetric to CASH-01's IN-path (CashSkipTrailNf525Test / M10-01). When cash
 * LEAVES the drawer for a refund or cashback but NO drawer session is open, the
 * existing OUT paths (RefundWithCounterEntryService:280-289 +
 * PaymentService::recordCashBackMovement:620-627) only wrote a `Log::info` and
 * returned — leaving NO durable trace. Result: the day's expected cash is
 * OVERSTATED (money left but no OUT movement subtracts it) and the EOD detector
 * cannot find it.
 *
 * Whereas the IN skip UNDERstates expected cash, the OUT skip OVERstates it — so
 * the two are surfaced in SEPARATE summary keys (`unrecorded_cash` for IN,
 * `unrecorded_cash_out` for OUT). They must NEVER be netted into one figure.
 *
 * Consequence asserted (anti-theater):
 *   (a) an unsessioned cashback leaves a queryable `cash_movement_out_skipped_at`
 *       marker on the order;
 *   (b) that order is surfaced in the `unrecorded_cash_out` block of the
 *       cash-overview reconciliation endpoint.
 */
class CashOutSkipTrailNf525Test extends TestCase
{
    use RefreshDatabase;

    private function paidCashOrder(Branch $branch, User $cashier, float $total): Order
    {
        $order = Order::factory()->create([
            'branch_id'   => $branch->id,
            'user_id'     => $cashier->id,
            'order_type'  => OrderType::POS,
            'total'       => $total,
            'subtotal'    => $total,
            'total_tax'   => 0,
        ]);

        // A prior CASH `payment` transaction — cashBack() requires one to fire.
        Transaction::create([
            'order_id'       => $order->id,
            'transaction_no' => 'PAY-'.$order->id,
            'amount'         => $total,
            'payment_method' => 'cash',
            'sign'           => '+',
            'type'           => 'payment',
        ]);

        return $order;
    }

    public function test_cashback_without_open_session_persists_queryable_out_skip_marker(): void
    {
        $branch  = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $order   = $this->paidCashOrder($branch, $cashier, 18.00);

        // NO open cash-drawer session for the cashier.
        $this->actingAs($cashier, 'sanctum');
        app(PaymentService::class)->cashBack($order, 'cash', 'RTN-'.$order->id);

        $fresh = $order->fresh();
        $this->assertNotNull(
            $fresh->cash_movement_out_skipped_at,
            'unsessioned cash-OUT (cashback) must leave a queryable cash_movement_out_skipped_at marker (CASH-01)'
        );
        // No OUT movement was recorded (no session) — the cash physically left
        // the drawer but nothing subtracts it from expected cash.
        $this->assertFalse(
            CashMovement::where('order_id', $order->id)
                ->where('direction', CashMovement::DIRECTION_OUT)
                ->exists(),
            'with no open session there is no OUT cash_movement — that is the gap being marked'
        );
    }

    public function test_cashback_with_open_session_records_out_movement_and_no_skip(): void
    {
        $branch  = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $order   = $this->paidCashOrder($branch, $cashier, 18.00);

        $session = CashDrawerSession::create([
            'branch_id'         => $branch->id,
            'opened_by_user_id' => $cashier->id,
            'opened_at'         => now(),
            'opening_amount'    => 100.00,
            'status'            => CashDrawerSession::STATUS_OPEN,
        ]);

        $this->actingAs($cashier, 'sanctum');
        app(PaymentService::class)->cashBack($order, 'cash', 'RTN-'.$order->id);

        $fresh = $order->fresh();
        $this->assertNull(
            $fresh->cash_movement_out_skipped_at,
            'with an open session an OUT cash_movement is recorded — no skip marker'
        );
        $this->assertTrue(
            CashMovement::where('cash_drawer_session_id', $session->id)
                ->where('direction', CashMovement::DIRECTION_OUT)
                ->exists(),
            'an OUT cash_movement must be recorded against the open session'
        );
    }

    /**
     * Consequence (b): the marked OUT-skip order is surfaced at the
     * reconciliation endpoint, in a key DISTINCT from the IN-skip block.
     */
    public function test_unrecorded_cash_out_block_flags_cashback_without_session(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch  = Branch::factory()->create();
        $admin   = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $cashier = User::factory()->create(['branch_id' => $branch->id]);

        $today = Carbon::now('Europe/Paris')->startOfDay()->addHours(10);
        $order = $this->paidCashOrder($branch, $cashier, 18.00);
        Order::query()->whereKey($order->id)->update(['created_at' => $today, 'updated_at' => $today]);

        $this->actingAs($cashier, 'sanctum');
        app(PaymentService::class)->cashBack($order->fresh(), 'cash', 'RTN-'.$order->id);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/cash-overview');

        $response->assertOk();
        $this->assertSame(1, $response->json('unrecorded_cash_out.count'));
        $this->assertEquals(18.00, (float) $response->json('unrecorded_cash_out.total'));
        $this->assertContains($order->id, $response->json('unrecorded_cash_out.order_ids'));
        $this->assertNotNull($response->json('unrecorded_cash_out.message'));
    }

    /**
     * Day-bucketing: the OUT skip must be windowed on WHEN THE CASH LEFT THE
     * DRAWER (the refund moment), NOT when the order was placed. A cashback
     * processed TODAY on an order PLACED days ago, with no session, must surface
     * in TODAY's reconciliation window — that is when expected cash is
     * overstated. Anchoring on orders.created_at would mis-bucket it to the
     * prior day where no cash actually moved.
     */
    public function test_unrecorded_cash_out_buckets_by_refund_moment_not_order_date(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch  = Branch::factory()->create();
        $admin   = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $cashier = User::factory()->create(['branch_id' => $branch->id]);

        // Order PLACED 3 days ago.
        $order = $this->paidCashOrder($branch, $cashier, 22.00);
        $threeDaysAgo = Carbon::now('Europe/Paris')->subDays(3)->startOfDay()->addHours(12);
        Order::query()->whereKey($order->id)->update(['created_at' => $threeDaysAgo, 'updated_at' => $threeDaysAgo]);

        // Cashback fired NOW (no session) → marker stamped now().
        $this->actingAs($cashier, 'sanctum');
        app(PaymentService::class)->cashBack($order->fresh(), 'cash', 'RTN-'.$order->id);

        // Default window = today → the gap must still surface (cash left today).
        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/cash-overview');

        $response->assertOk();
        $this->assertSame(
            1,
            $response->json('unrecorded_cash_out.count'),
            'a cashback fired today on an old order must surface in today\'s OUT window (bucket by refund moment)'
        );
        $this->assertContains($order->id, $response->json('unrecorded_cash_out.order_ids'));
    }

    /**
     * Branch isolation — a Branch Manager only sees OUT skips on their branch.
     */
    public function test_unrecorded_cash_out_isolated_to_managers_branch(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branchA  = Branch::factory()->create();
        $branchB  = Branch::factory()->create();
        $managerA = User::factory()->create(['branch_id' => $branchA->id]);
        $managerA->assignRole('Branch Manager');
        $cashierA = User::factory()->create(['branch_id' => $branchA->id]);
        $cashierB = User::factory()->create(['branch_id' => $branchB->id]);

        $today = Carbon::now('Europe/Paris')->startOfDay()->addHours(10);

        $orderA = $this->paidCashOrder($branchA, $cashierA, 12.00);
        Order::query()->whereKey($orderA->id)->update(['created_at' => $today, 'updated_at' => $today]);
        $this->actingAs($cashierA, 'sanctum');
        app(PaymentService::class)->cashBack($orderA->fresh(), 'cash', 'RTN-A-'.$orderA->id);

        $orderB = $this->paidCashOrder($branchB, $cashierB, 99.00);
        Order::query()->whereKey($orderB->id)->update(['created_at' => $today, 'updated_at' => $today]);
        $this->actingAs($cashierB, 'sanctum');
        app(PaymentService::class)->cashBack($orderB->fresh(), 'cash', 'RTN-B-'.$orderB->id);

        $response = $this->actingAs($managerA, 'sanctum')->getJson('/api/admin/cash-overview');

        $response->assertOk();
        $this->assertSame(1, $response->json('unrecorded_cash_out.count'));
        $this->assertContains($orderA->id, $response->json('unrecorded_cash_out.order_ids'));
        $this->assertNotContains(
            $orderB->id,
            $response->json('unrecorded_cash_out.order_ids'),
            'Branch Manager must not see another branch\'s OUT-skip (isolation via Order.branch_id)'
        );
    }

    /**
     * The OTHER OUT skip site: the REAL post-Z refund flow
     * (RefundWithCounterEntryService). A sealed parent paid in CASH, refunded
     * with no open session → the mirror order carries the durable OUT-skip
     * marker (not just a Log::info).
     */
    public function test_refund_counter_entry_without_session_marks_mirror_out_skip(): void
    {
        $branch  = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        Auth::setUser($cashier);

        // Window 1 — sealed (closed Z) so the service accepts the parent.
        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        ZReport::create([
            'branch_id'   => $branch->id,
            'sequence_no' => 1,
            'opened_at'   => $opened,
            'closed_at'   => $closed,
            'status'      => ZReport::STATUS_CLOSED,
        ]);

        $parent = Order::factory()->create([
            'branch_id'      => $branch->id,
            'status'         => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'subtotal'       => 50.00,
            'total'          => 50.00,
            'total_tax'      => 0,
            'created_at'     => $opened->copy()->addHours(2),
        ]);
        $parent->fiscal_sequence_no = 10;
        $parent->save();

        // Parent paid in CASH — drives the cash-OUT loop in the service.
        OrderPayment::create([
            'order_id'  => $parent->id,
            'branch_id' => $branch->id,
            'mode'      => PosPaymentMethod::CASH,
            'amount'    => 50.00,
        ]);

        // NO open cash session for the cashier → the OUT movement is skipped.
        $mirror = app(RefundWithCounterEntryService::class)
            ->execute($parent, 'no-session refund out-skip check');

        $this->assertNotNull(
            $mirror->fresh()->cash_movement_out_skipped_at,
            'an unsessioned CASH refund counter-entry must mark the mirror with cash_movement_out_skipped_at (CASH-01)'
        );
        $this->assertFalse(
            CashMovement::where('order_id', $mirror->id)
                ->where('direction', CashMovement::DIRECTION_OUT)
                ->exists(),
            'no open session → no OUT cash_movement on the mirror'
        );
    }
}
