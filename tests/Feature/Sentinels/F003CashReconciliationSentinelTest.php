<?php

namespace Tests\Feature\Sentinels;

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
use App\Services\Fiscal\ZReportCashEnrichmentService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [AUDIT-F-003] Sentinel — verrouille les 5 invariants du finding F-003.
 *
 * Ce test est anti-régression : il échoue immédiatement si une refonte casse :
 *   F003-INV-1 Schema cash_drawer_sessions + cash_movements + colonnes z_reports cash_*
 *   F003-INV-2 PAID cash order → cash_movement order_payment direction=in lié
 *   F003-INV-3 cashBack PAID order → cash_movement cashback direction=out lié
 *   F003-INV-4 reconcileSession variance = closing_amount − (opening + Σ signed movements)
 *   F003-INV-5 ZReportService.php frozen-zone preservée (décorateur séparé,
 *              signature HMAC inchangée par l'enrichment cash)
 *
 * Reference: HANDOFF_TO_EXECUTOR_2026-05-07 §invariants #8
 * "Cash-reconcile : tout order cash PAID a un cash_movement lié ; Z report
 * calcule variance."
 */
class F003CashReconciliationSentinelTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $cashier;

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
        $this->actingAs($this->cashier);

        // [Sprint 1D / F-4] F003 sentinel checks the arithmetic invariants
        // of reconcile (INV-4) with +5€/-5€ scenarios — pre-dates the
        // variance gate. Neutralise the gate here.
        Config::set('cash.variance_threshold_eur', 999.0);
        Config::set('cash.variance_manager_approval_required', false);
    }

    /** F003-INV-1 — Schema complet, colonnes critiques présentes */
    public function test_F003_INV_1_schema_required_columns_present(): void
    {
        // cash_drawer_sessions
        $this->assertTrue(Schema::hasTable('cash_drawer_sessions'));
        foreach ([
            'id', 'branch_id', 'opened_by_user_id', 'opened_at', 'closed_at',
            'opening_amount', 'closing_amount', 'expected_closing_amount',
            'variance', 'variance_reason', 'status',
        ] as $col) {
            $this->assertTrue(Schema::hasColumn('cash_drawer_sessions', $col), "F003-INV-1: cash_drawer_sessions.{$col} missing");
        }

        // cash_movements
        $this->assertTrue(Schema::hasTable('cash_movements'));
        foreach ([
            'id', 'cash_drawer_session_id', 'branch_id', 'order_id',
            'type', 'amount', 'direction', 'notes',
        ] as $col) {
            $this->assertTrue(Schema::hasColumn('cash_movements', $col), "F003-INV-1: cash_movements.{$col} missing");
        }

        // z_reports — colonnes additives cash (NON signées)
        foreach (['cash_opening_amount', 'cash_closing_amount', 'cash_variance', 'cash_movements_count'] as $col) {
            $this->assertTrue(Schema::hasColumn('z_reports', $col), "F003-INV-1: z_reports.{$col} missing");
        }
    }

    /** F003-INV-2 — PAID cash → movement order_payment IN avec orderId/branchId/amount */
    public function test_F003_INV_2_paid_cash_creates_linked_movement_in(): void
    {
        $cashService = app(CashDrawerService::class);
        $paymentService = app(PaymentService::class);

        $session = $cashService->openSession($this->branch->id, $this->cashier->id, 100.00);

        $order = Order::factory()->create([
            'branch_id'           => $this->branch->id,
            'user_id'             => $this->cashier->id,
            'total'               => 17.50,
            'subtotal'            => 17.50,
            'discount'            => 0,
            'total_tax'           => 0,
            'delivery_charge'     => 0,
            'status'              => OrderStatus::PENDING,
            'payment_status'      => PaymentStatus::UNPAID,
            'payment_method'      => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method'  => PosPaymentMethod::COUNTER_DEFERRED,
            'source_surface'      => 'kiosk',
        ]);

        $paymentService->confirmCounterPayment($order, PosPaymentMethod::CASH);

        $m = CashMovement::query()->where('cash_drawer_session_id', $session->id)->first();
        $this->assertNotNull($m, 'F003-INV-2: cash_movement absent for PAID cash order');
        $this->assertSame(CashMovement::TYPE_ORDER_PAYMENT, $m->type);
        $this->assertSame(CashMovement::DIRECTION_IN, $m->direction);
        $this->assertSame($order->id, $m->order_id);
        $this->assertSame($this->branch->id, $m->branch_id);
        $this->assertSame('17.50', (string) $m->amount);
    }

    /** F003-INV-3 — cashBack PAID → movement cashback OUT */
    public function test_F003_INV_3_cashback_creates_linked_movement_out(): void
    {
        $cashService = app(CashDrawerService::class);
        $paymentService = app(PaymentService::class);

        $session = $cashService->openSession($this->branch->id, $this->cashier->id, 100.00);

        $order = Order::factory()->create([
            'branch_id'      => $this->branch->id,
            'user_id'        => $this->cashier->id,
            'total'          => 8.00,
            'subtotal'       => 8.00,
            'discount'       => 0,
            'total_tax'      => 0,
            'delivery_charge' => 0,
            'payment_status' => PaymentStatus::PAID,
            'status'         => OrderStatus::DELIVERED,
        ]);

        \App\Models\Transaction::create([
            'order_id'       => $order->id,
            'transaction_no' => 'TXN-INIT-1',
            'amount'         => $order->total,
            'payment_method' => 'cash',
            'sign'           => '+',
            'type'           => 'payment',
        ]);

        $paymentService->cashBack($order, 'cash', 'TXN-CB-1');

        $m = CashMovement::query()
            ->where('cash_drawer_session_id', $session->id)
            ->where('type', CashMovement::TYPE_CASHBACK)
            ->first();
        $this->assertNotNull($m, 'F003-INV-3: cashback movement absent');
        $this->assertSame(CashMovement::DIRECTION_OUT, $m->direction);
        $this->assertSame($order->id, $m->order_id);
    }

    /** F003-INV-4 — reconcile variance arithmétique stricte */
    public function test_F003_INV_4_reconcile_variance_arithmetic(): void
    {
        $cashService = app(CashDrawerService::class);

        $session = $cashService->openSession($this->branch->id, $this->cashier->id, 80.00);
        $cashService->recordMovement($session->id, CashMovement::TYPE_ORDER_PAYMENT, 30.00, CashMovement::DIRECTION_IN);
        $cashService->recordMovement($session->id, CashMovement::TYPE_CASHBACK, 5.00, CashMovement::DIRECTION_OUT);
        // expected = 80 + 30 - 5 = 105
        $cashService->closeSession($session->id, 110.00); // declared physical
        $result = $cashService->reconcileSession($session->id);

        $this->assertSame(105.00, $result['expected'], 'F003-INV-4: expected mismatch');
        $this->assertSame(5.00, $result['variance'], 'F003-INV-4: variance mismatch');
        $this->assertSame(CashDrawerSession::STATUS_RECONCILED, $result['session']->status);
    }

    /** F003-INV-5 — ZReportService.php frozen-zone preservée */
    public function test_F003_INV_5_zreportservice_frozen_zone_preserved(): void
    {
        $reflector = new \ReflectionClass(\App\Services\Fiscal\ZReportService::class);
        $aggregateMethod = $reflector->getMethod('aggregate');

        // L'aggregate signature DOIT rester identique : pas de paramètre cash_*,
        // pas de retour additionné cash_variance dans le signed payload.
        $params = $aggregateMethod->getParameters();
        $paramNames = array_map(fn ($p) => $p->getName(), $params);
        $this->assertSame(['branchId', 'from', 'to'], $paramNames,
            'F003-INV-5: ZReportService::aggregate signature changed — frozen-zone broken');

        // Verifier qu'il existe un service décorateur séparé (pattern)
        $this->assertTrue(class_exists(ZReportCashEnrichmentService::class),
            'F003-INV-5: ZReportCashEnrichmentService decorator must exist (NON modifier le coeur Fiscal)');

        // Le décorateur ne doit JAMAIS toucher la signature.
        $enricher = app(ZReportCashEnrichmentService::class);
        $report = \App\Models\ZReport::create([
            'branch_id'         => $this->branch->id,
            'sequence_no'       => 1,
            'opened_at'         => now()->subHour(),
            'closed_at'         => now(),
            'total_ht'          => 50.00,
            'total_ttc'         => 60.00,
            'total_tva'         => 10.00,
            'total_by_method'   => [],
            'total_by_tax_rate' => [],
            'order_count'       => 1,
            'cancel_count'      => 0,
            'refund_count'      => 0,
            'signature'         => str_repeat('f', 64),
            'prev_hash'         => str_repeat('0', 64),
            'status'            => \App\Models\ZReport::STATUS_CLOSED,
        ]);
        $originalSig = $report->signature;

        $enricher->persistForClosedReport($report);
        $report->refresh();
        $this->assertSame($originalSig, $report->signature,
            'F003-INV-5: persistForClosedReport modified the HMAC signature — chain validation broken');
    }
}
