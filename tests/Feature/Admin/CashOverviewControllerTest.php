<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [Wave X — X4 2026-05-21] Admin unified cash & transactions overview.
 *
 * Owner mandate (verbatim translated):
 *   « Toutes les commandes encaissées (POS direct, borne cash-collected,
 *     livreur) au MÊME ENDROIT en base. Admin part + caisse voient tout.
 *     Pour chaque transaction : source (borne/caisse/livreur) + mode
 *     paiement + total. Totaux par source + grand total. Permet de
 *     détecter écarts (cash manquant). »
 *
 * Endpoint : GET /api/admin/cash-overview
 * Permission : reuses `cash-sessions-report` (same role gate as Wave O O4).
 *
 * Branch isolation : Transaction has no BranchScope; this test pins that
 *   the controller flows isolation through Order.branch_id, with admin
 *   (branch_id=0) bypassing the global scope and Branch Manager scoped
 *   automatically.
 */
class CashOverviewControllerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;
    private Branch $branchB;
    private User $admin;
    private User $managerA;
    private User $cashierA;
    private User $deliveryBoyA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branchA = Branch::factory()->create();
        $this->branchB = Branch::factory()->create();

        $this->admin = User::factory()->create(['branch_id' => 0]);
        $this->admin->assignRole('Admin');

        $this->managerA = User::factory()->create(['branch_id' => $this->branchA->id]);
        $this->managerA->assignRole('Branch Manager');

        $this->cashierA = User::factory()->create(['branch_id' => $this->branchA->id]);
        $this->cashierA->assignRole('POS Operator');

        $this->deliveryBoyA = User::factory()->create(['branch_id' => $this->branchA->id]);
    }

    /**
     * Helper — creates an Order + a paired Transaction for the test.
     * `source` controls the derived source bucket :
     *   - 'caisse'  : POS direct (no kiosk hint, no delivery_boy)
     *   - 'borne'   : kiosk borne (order_type=KIOSK + source_surface='kiosk')
     *   - 'livreur' : delivery (delivery_boy_id NOT NULL)
     */
    private function makeOrderTransaction(
        Branch $branch,
        string $source,
        string $paymentMethodSlug,
        float $amount,
        Carbon $createdAt
    ): Transaction {
        $orderAttrs = [
            'branch_id'       => $branch->id,
            'user_id'         => $this->cashierA->id,
            'queue_number'    => random_int(100, 999),
            'order_serial_no' => 'TEST-'.uniqid(),
            'subtotal'        => $amount,
            'total'           => $amount,
            'total_tax'       => 0,
            'discount'        => 0,
            'delivery_charge' => 0,
            'order_type'      => OrderType::POS,
            'status'          => 1,
            'payment_status'  => 1,
            'created_at'      => $createdAt,
            'updated_at'      => $createdAt,
        ];
        $isLivreur = false;
        switch ($source) {
            case 'borne':
                $orderAttrs['order_type'] = OrderType::KIOSK;
                $orderAttrs['source_surface'] = 'kiosk';
                break;
            case 'livreur':
                $orderAttrs['order_type'] = OrderType::DELIVERY;
                $isLivreur = true;
                break;
            case 'caisse':
            default:
                $orderAttrs['source_surface'] = 'pos';
                break;
        }
        $order = Order::create($orderAttrs);
        if ($isLivreur) {
            // delivery_boy_id is not in $fillable on Order, set via raw
            // update so the BranchScope + factory machinery aren't blocked.
            Order::query()->where('id', $order->id)
                ->update(['delivery_boy_id' => $this->deliveryBoyA->id]);
            $order->refresh();
        }

        $txn = Transaction::create([
            'order_id'       => $order->id,
            'transaction_no' => 'TXN-'.$order->id,
            'amount'         => $amount,
            'payment_method' => $paymentMethodSlug,
            'sign'           => '+',
            'type'           => 'payment',
        ]);
        // Force the same created_at as the order so date filters are
        // deterministic.
        Transaction::query()
            ->where('id', $txn->id)
            ->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);

        return $txn->fresh();
    }

    /**
     * [GOAL-2026-05-29 F7] Summary totals MUST cover ALL matching rows in the
     * window, not just the MAX_ROWS-capped rendered list. Pre-fix, summarize()
     * iterated the capped collection → grand_total under-reported on a
     * >500-transaction window (wrong reconciliation). Proven here with 501 rows:
     * summary aggregates all 501 while the rendered list stays capped at 500.
     */
    public function test_summary_aggregates_beyond_the_rendered_list_cap(): void
    {
        $today = Carbon::now('Europe/Paris')->startOfDay()->addHours(10);
        $count = 501; // > CashOverviewController::MAX_ROWS (500)

        for ($i = 0; $i < $count; $i++) {
            $this->makeOrderTransaction($this->branchA, 'caisse', 'cash', 1.00, $today);
        }

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/cash-overview');

        $response->assertOk();
        // Summary covers ALL 501 (uncapped) — the bug summed only the capped 500.
        $this->assertSame($count, (int) $response->json('summary.count'));
        $this->assertEquals(501.00, (float) $response->json('summary.total'));
        // The rendered list stays capped for performance.
        $this->assertLessThanOrEqual(500, count($response->json('data')));
    }

    public function test_rejects_unauthenticated(): void
    {
        $this->getJson('/api/admin/cash-overview')->assertStatus(401);
    }

    public function test_rejects_user_without_fiscal_permission(): void
    {
        $this->actingAs($this->cashierA, 'sanctum')
            ->getJson('/api/admin/cash-overview')
            ->assertStatus(403);
    }

    public function test_admin_sees_all_branches_with_default_today_window(): void
    {
        $today = Carbon::now('Europe/Paris')->startOfDay()->addHours(10);

        $tA = $this->makeOrderTransaction($this->branchA, 'caisse', 'cash',  10.00, $today);
        $tB = $this->makeOrderTransaction($this->branchB, 'caisse', 'cash',  20.00, $today);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/cash-overview');

        $response->assertOk();
        $response->assertJsonPath('status', true);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($tA->id, $ids);
        $this->assertContains($tB->id, $ids);

        $this->assertEquals(30.00, $response->json('summary.total'));
        $this->assertSame(2,        $response->json('summary.count'));
    }

    public function test_branch_manager_only_sees_own_branch(): void
    {
        $today = Carbon::now('Europe/Paris')->startOfDay()->addHours(11);

        $tA = $this->makeOrderTransaction($this->branchA, 'caisse', 'cash', 15.00, $today);
        $tB = $this->makeOrderTransaction($this->branchB, 'caisse', 'cash', 25.00, $today);

        $response = $this->actingAs($this->managerA, 'sanctum')
            ->getJson('/api/admin/cash-overview');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($tA->id, $ids);
        $this->assertNotContains(
            $tB->id,
            $ids,
            'Branch Manager must not see other-branch transactions (isolation via Order.branch_id)'
        );
    }

    public function test_derives_source_bucket_per_transaction(): void
    {
        $today = Carbon::now('Europe/Paris')->startOfDay()->addHours(12);

        $tCaisse  = $this->makeOrderTransaction($this->branchA, 'caisse',  'cash',         10.00, $today);
        $tBorne   = $this->makeOrderTransaction($this->branchA, 'borne',   'counter_cash', 15.00, $today);
        $tLivreur = $this->makeOrderTransaction($this->branchA, 'livreur', 'cash',         20.00, $today);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/cash-overview');

        $response->assertOk();
        $rows = collect($response->json('data'))->keyBy('id');

        $this->assertSame('caisse',  $rows[$tCaisse->id]['source']);
        $this->assertSame('borne',   $rows[$tBorne->id]['source']);
        $this->assertSame('livreur', $rows[$tLivreur->id]['source']);
    }

    public function test_derives_payment_bucket_per_transaction(): void
    {
        $today = Carbon::now('Europe/Paris')->startOfDay()->addHours(13);

        $cases = [
            ['cash',                       'cash'],
            ['credit',                     'card'],
            ['counter_card',               'card'],
            ['stripe',                     'card'],
            ['counter_mobile_banking',     'mobile'],
            ['counter_ticket_restaurant',  'ticket'],
            ['counter_other',              'other'],
        ];

        $expected = [];
        foreach ($cases as [$slug, $bucket]) {
            $tx = $this->makeOrderTransaction($this->branchA, 'caisse', $slug, 1.00, $today);
            $expected[$tx->id] = $bucket;
        }

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/cash-overview');

        $response->assertOk();
        $rows = collect($response->json('data'))->keyBy('id');
        foreach ($expected as $id => $expectedBucket) {
            $this->assertSame(
                $expectedBucket,
                $rows[$id]['mode_bucket'],
                "Transaction $id payment_method bucket mismatch"
            );
        }
    }

    public function test_aggregates_summary_by_source_and_mode(): void
    {
        $today = Carbon::now('Europe/Paris')->startOfDay()->addHours(14);

        $this->makeOrderTransaction($this->branchA, 'caisse',  'cash',         10.00, $today);
        $this->makeOrderTransaction($this->branchA, 'caisse',  'credit',       20.00, $today);
        $this->makeOrderTransaction($this->branchA, 'borne',   'counter_cash', 5.00,  $today);
        $this->makeOrderTransaction($this->branchA, 'livreur', 'cash',         15.00, $today);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/cash-overview');

        $response->assertOk();
        $this->assertEquals(50.00, $response->json('summary.total'));
        $this->assertSame(4,        $response->json('summary.count'));

        // by_source totals.
        $this->assertEquals(30.00, $response->json('summary.by_source.caisse.total'));
        $this->assertEquals(5.00,  $response->json('summary.by_source.borne.total'));
        $this->assertEquals(15.00, $response->json('summary.by_source.livreur.total'));
        $this->assertSame(2, $response->json('summary.by_source.caisse.count'));

        // by_mode totals : cash=30 (10+5+15), card=20.
        $this->assertEquals(30.00, $response->json('summary.by_mode.cash.total'));
        $this->assertEquals(20.00, $response->json('summary.by_mode.card.total'));
    }

    public function test_filter_by_source_only_returns_matching_rows(): void
    {
        $today = Carbon::now('Europe/Paris')->startOfDay()->addHours(15);

        $tCaisse  = $this->makeOrderTransaction($this->branchA, 'caisse',  'cash', 10.00, $today);
        $tBorne   = $this->makeOrderTransaction($this->branchA, 'borne',   'counter_cash', 20.00, $today);
        $tLivreur = $this->makeOrderTransaction($this->branchA, 'livreur', 'cash', 30.00, $today);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/cash-overview?source=borne');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($tBorne->id, $ids);
        $this->assertNotContains($tCaisse->id, $ids);
        $this->assertNotContains($tLivreur->id, $ids);
    }

    public function test_filter_by_mode_cash_returns_only_cash_buckets(): void
    {
        $today = Carbon::now('Europe/Paris')->startOfDay()->addHours(16);

        $tCash    = $this->makeOrderTransaction($this->branchA, 'caisse', 'cash',         10.00, $today);
        $tCounter = $this->makeOrderTransaction($this->branchA, 'borne',  'counter_cash', 20.00, $today);
        $tCard    = $this->makeOrderTransaction($this->branchA, 'caisse', 'credit',       30.00, $today);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/cash-overview?mode=cash');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($tCash->id, $ids);
        $this->assertContains($tCounter->id, $ids);
        $this->assertNotContains($tCard->id, $ids);
    }

    public function test_filter_by_date_range(): void
    {
        $today = Carbon::now('Europe/Paris')->startOfDay()->addHours(10);
        $yesterday = (clone $today)->subDay();

        $tYesterday = $this->makeOrderTransaction($this->branchA, 'caisse', 'cash', 50.00, $yesterday);
        $tToday     = $this->makeOrderTransaction($this->branchA, 'caisse', 'cash', 20.00, $today);

        $from = $today->toDateString();
        $to   = $today->toDateString();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/cash-overview?from=$from&to=$to");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($tToday->id, $ids);
        $this->assertNotContains($tYesterday->id, $ids);
    }

    public function test_excludes_cash_back_rows_from_aggregate(): void
    {
        $today = Carbon::now('Europe/Paris')->startOfDay()->addHours(17);

        $payment = $this->makeOrderTransaction($this->branchA, 'caisse', 'cash', 100.00, $today);

        // Add a cash_back row on the same order — must NOT count.
        $cashBack = Transaction::create([
            'order_id'       => $payment->order_id,
            'transaction_no' => 'REFUND-'.$payment->order_id,
            'amount'         => 100.00,
            'payment_method' => 'cash',
            'sign'           => '-',
            'type'           => 'cash_back',
        ]);
        Transaction::query()
            ->where('id', $cashBack->id)
            ->update(['created_at' => $today, 'updated_at' => $today]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/cash-overview');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($payment->id, $ids);
        $this->assertNotContains(
            $cashBack->id,
            $ids,
            'cash_back rows must be excluded so the écart calculation reflects net cash IN only'
        );
        $this->assertEquals(100.00, $response->json('summary.total'));
    }

    public function test_invalid_date_returns_422(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/cash-overview?from=not-a-date')
            ->assertStatus(422);
    }

    public function test_cash_session_reconciliation_for_branch_manager(): void
    {
        $today = Carbon::now('Europe/Paris')->startOfDay()->addHours(9);

        // Open drawer in branch A with 100€ opening.
        $session = CashDrawerSession::create([
            'branch_id'         => $this->branchA->id,
            'opened_by_user_id' => $this->cashierA->id,
            'opened_at'         => $today,
            'opening_amount'    => 100.00,
            'status'            => CashDrawerSession::STATUS_OPEN,
        ]);

        // [CASH-JOIN-01/SEM-02] expected_cash is now derived from the SESSION's
        // signed CashMovements (the authoritative reconcileSession source), not a
        // per-branch+day Transaction sum. Cash IN movements: 30 + 20 = 50.
        \App\Models\CashMovement::create([
            'cash_drawer_session_id' => $session->id, 'branch_id' => $this->branchA->id,
            'type' => \App\Models\CashMovement::TYPE_ORDER_PAYMENT,
            'direction' => \App\Models\CashMovement::DIRECTION_IN, 'amount' => 30.00,
        ]);
        \App\Models\CashMovement::create([
            'cash_drawer_session_id' => $session->id, 'branch_id' => $this->branchA->id,
            'type' => \App\Models\CashMovement::TYPE_ORDER_PAYMENT,
            'direction' => \App\Models\CashMovement::DIRECTION_IN, 'amount' => 20.00,
        ]);
        // Transactions populate the list; a card txn must NOT inflate expected_cash
        // (it creates no cash CashMovement).
        $this->makeOrderTransaction($this->branchA, 'caisse', 'cash',         30.00, (clone $today)->addHours(2));
        $this->makeOrderTransaction($this->branchA, 'caisse', 'credit',       80.00, (clone $today)->addHours(4));

        $response = $this->actingAs($this->managerA, 'sanctum')
            ->getJson('/api/admin/cash-overview');

        $response->assertOk();
        $this->assertEquals(100.00, $response->json('cash_session.opening_amount'));
        $this->assertEquals(50.00,  $response->json('cash_session.cash_collected'));
        $this->assertEquals(150.00, $response->json('cash_session.expected_cash'));
    }

    /**
     * [CASH-JOIN-01 + CASH-SEM-02] expected_cash must (a) net cash-OUT/cashback
     * movements and (b) NOT include cash from another session of the same day.
     */
    public function test_expected_cash_nets_cashout_and_isolates_session(): void
    {
        $today = Carbon::now('Europe/Paris')->startOfDay()->addHours(9);

        // A PREVIOUS closed session of the same day with its own cash — must NOT leak.
        $closed = CashDrawerSession::create([
            'branch_id' => $this->branchA->id, 'opened_by_user_id' => $this->cashierA->id,
            'opened_at' => (clone $today)->subHours(1), 'opening_amount' => 500.00,
            'status' => CashDrawerSession::STATUS_CLOSED,
        ]);
        \App\Models\CashMovement::create([
            'cash_drawer_session_id' => $closed->id, 'branch_id' => $this->branchA->id,
            'type' => \App\Models\CashMovement::TYPE_ORDER_PAYMENT,
            'direction' => \App\Models\CashMovement::DIRECTION_IN, 'amount' => 999.00,
        ]);

        // The CURRENT open session: opening 100, +60 cash in, -25 cashback out.
        $session = CashDrawerSession::create([
            'branch_id' => $this->branchA->id, 'opened_by_user_id' => $this->cashierA->id,
            'opened_at' => $today, 'opening_amount' => 100.00,
            'status' => CashDrawerSession::STATUS_OPEN,
        ]);
        \App\Models\CashMovement::create([
            'cash_drawer_session_id' => $session->id, 'branch_id' => $this->branchA->id,
            'type' => \App\Models\CashMovement::TYPE_ORDER_PAYMENT,
            'direction' => \App\Models\CashMovement::DIRECTION_IN, 'amount' => 60.00,
        ]);
        \App\Models\CashMovement::create([
            'cash_drawer_session_id' => $session->id, 'branch_id' => $this->branchA->id,
            'type' => \App\Models\CashMovement::TYPE_CASHBACK,
            'direction' => \App\Models\CashMovement::DIRECTION_OUT, 'amount' => 25.00,
        ]);

        $response = $this->actingAs($this->managerA, 'sanctum')->getJson('/api/admin/cash-overview');

        $response->assertOk();
        // Net = 60 - 25 = 35 (the closed session's 999 must NOT leak).
        $this->assertEquals(35.00, $response->json('cash_session.cash_collected'),
            'cash_collected must net cash-OUT and be scoped to the open session only.');
        $this->assertEquals(135.00, $response->json('cash_session.expected_cash'),
            'expected_cash = opening 100 + (60 - 25) = 135; the prior session 999 must not leak.');
    }

    public function test_cash_session_null_for_admin_without_branch_filter(): void
    {
        $today = Carbon::now('Europe/Paris')->startOfDay()->addHours(10);
        // No drawer to compare against → cash_session = null (admin doing
        // cross-branch view has no single drawer to reconcile).
        $this->makeOrderTransaction($this->branchA, 'caisse', 'cash', 10.00, $today);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/cash-overview');

        $response->assertOk();
        $this->assertNull($response->json('cash_session'));
    }

    /**
     * [TRAP-3 2026-06-04] The cash-trail hole must surface DURABLY at the
     * cash-overview / reconciliation surface — not only via an ephemeral
     * collect-time toast. A cash `payment` Transaction whose order has NO
     * order_payment/in cash_movement (collected with no open drawer session)
     * is flagged in the `unrecorded_cash` block with count + total + a FR
     * message the cash-overview UI renders as a discrepancy.
     */
    public function test_unrecorded_cash_block_flags_cash_collected_without_session(): void
    {
        $today = Carbon::now('Europe/Paris')->startOfDay()->addHours(10);

        // Order A — cash collected, NO cash_movement (the gap).
        $orphan = $this->makeOrderTransaction($this->branchA, 'borne', 'counter_cash', 12.50, $today);

        // Order B — cash collected WITH a proper order_payment/in movement.
        $session = CashDrawerSession::create([
            'branch_id'         => $this->branchA->id,
            'opened_by_user_id' => $this->cashierA->id,
            'opened_at'         => $today,
            'opening_amount'    => 0.00,
            'status'            => CashDrawerSession::STATUS_OPEN,
        ]);
        $linked = $this->makeOrderTransaction($this->branchA, 'caisse', 'cash', 30.00, $today);
        \App\Models\CashMovement::create([
            'cash_drawer_session_id' => $session->id,
            'branch_id' => $this->branchA->id,
            'order_id'  => $linked->order_id,
            'type'      => \App\Models\CashMovement::TYPE_ORDER_PAYMENT,
            'direction' => \App\Models\CashMovement::DIRECTION_IN,
            'amount'    => 30.00,
        ]);

        // Order C — CARD payment, must never be flagged as missing cash.
        $this->makeOrderTransaction($this->branchA, 'caisse', 'credit', 99.00, $today);

        // Order D — LIVREUR cash, NO cash_movements row (its movement lands in
        // the SEPARATE delivery_boy_cash_movements system). Must NOT be flagged
        // as a drawer cash-trail gap (otherwise: cry-wolf false positive).
        $livreur = $this->makeOrderTransaction($this->branchA, 'livreur', 'cash', 77.00, $today);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/cash-overview');

        $response->assertOk();

        // Only the orphan DRAWER cash order is flagged.
        $this->assertSame(1, $response->json('unrecorded_cash.count'));
        $this->assertEquals(12.50, (float) $response->json('unrecorded_cash.total'));
        $this->assertContains($orphan->order_id, $response->json('unrecorded_cash.order_ids'));
        $this->assertNotContains($linked->order_id, $response->json('unrecorded_cash.order_ids'));
        $this->assertNotContains(
            $livreur->order_id,
            $response->json('unrecorded_cash.order_ids'),
            'Livreur cash is reconciled via delivery_boy_cash_movements — must NOT be flagged as a drawer cash-trail gap.'
        );
        $this->assertNotNull($response->json('unrecorded_cash.message'));
        $this->assertStringContainsString(
            'sans session caisse',
            $response->json('unrecorded_cash.message')
        );
    }

    /**
     * [TRAP-3 2026-06-04] Healthy case — every cash collection landed in a
     * session → no false discrepancy (count 0, null message).
     */
    public function test_unrecorded_cash_block_clean_when_all_cash_recorded(): void
    {
        $today = Carbon::now('Europe/Paris')->startOfDay()->addHours(10);

        $session = CashDrawerSession::create([
            'branch_id'         => $this->branchA->id,
            'opened_by_user_id' => $this->cashierA->id,
            'opened_at'         => $today,
            'opening_amount'    => 0.00,
            'status'            => CashDrawerSession::STATUS_OPEN,
        ]);
        $linked = $this->makeOrderTransaction($this->branchA, 'caisse', 'cash', 40.00, $today);
        \App\Models\CashMovement::create([
            'cash_drawer_session_id' => $session->id,
            'branch_id' => $this->branchA->id,
            'order_id'  => $linked->order_id,
            'type'      => \App\Models\CashMovement::TYPE_ORDER_PAYMENT,
            'direction' => \App\Models\CashMovement::DIRECTION_IN,
            'amount'    => 40.00,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/cash-overview');

        $response->assertOk();
        $this->assertSame(0, $response->json('unrecorded_cash.count'));
        $this->assertEquals(0.0, (float) $response->json('unrecorded_cash.total'));
        $this->assertNull($response->json('unrecorded_cash.message'));
    }

    public function test_query_count_bounded_no_n_plus_1(): void
    {
        $today = Carbon::now('Europe/Paris')->startOfDay()->addHours(10);
        for ($i = 0; $i < 25; $i++) {
            $this->makeOrderTransaction(
                $this->branchA,
                $i % 3 === 0 ? 'borne' : ($i % 3 === 1 ? 'livreur' : 'caisse'),
                'cash',
                (float) ($i + 1),
                $today
            );
        }

        DB::enableQueryLog();
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/cash-overview');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertLessThan(
            20,
            $queries,
            'Query count must be bounded (no N+1) — actual: '.$queries
        );
    }
}
