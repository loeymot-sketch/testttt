<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentTerminal;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Fiscal\ZReportCashEnrichmentService;
use App\Services\Order\RefundWithCounterEntryService;
use App\Services\Payments\SplitPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * [Sprint H2 P1-Z7-01 2026-05-17] terminal_id must flow from request to
 * OrderPayment rows for BOTH SplitPaymentService::persistTranches AND
 * RefundWithCounterEntryService write paths. Sprint 1C added the schema
 * (payment_terminals + OrderPayment.terminal_id + ZReportCashEnrichmentService::
 * aggregateByTerminal) but no service ever wrote the column. The Z-report
 * TPE breakdown was returning a single "Sans TPE" bucket with fees_total=0.
 *
 * NF525 §8 invariants preserved: writes are additive, no signature change,
 * legacy callers without terminal_id stay backward-compatible (NULL).
 *
 * Stage B (PosComponent.vue terminal selector UI) is a separate dispatch.
 * V1.0.1 accepts "Sans TPE" as default when no terminal selected (MASTER §5
 * Risk #4).
 */
class TerminalIdWireInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // Required by SplitPaymentService::persistTranches (split_payment feature flag)
        // + AuditLogService secret (NF525 chain-hash). Mirrors SplitPaymentServiceTest.
        config(['split_payment.enabled' => true, 'split_payment.max_tranches' => 12]);
        config(['fiscal.audit_secret' => 'test-fiscal-secret-' . str_repeat('a', 40)]);
    }

    private function createPaymentTerminal(Branch $branch, string $name = 'TPE-1'): PaymentTerminal
    {
        return PaymentTerminal::query()->create([
            'branch_id'    => $branch->id,
            'name'         => $name,
            'gateway_type' => PaymentTerminal::GATEWAY_INGENICO,
            'fee_percent'  => 1.50,
            'fee_fixed'    => 0.10,
            'status'       => PaymentTerminal::STATUS_ACTIVE,
        ]);
    }

    private function makeCashierActor(Branch $branch): User
    {
        $cashier = User::factory()->create([
            'branch_id' => $branch->id,
            'phone'     => fake()->unique()->numerify('06########'),
        ]);
        $cashier->assignRole('POS Operator');
        $this->actingAs($cashier);

        return $cashier;
    }

    /** @test
     *  Stage A core: terminal_id from a CARD tranche payload lands on the
     *  OrderPayment row. Pure CARD path (no cash drawer session required).
     */
    public function test_split_payment_persists_terminal_id_when_provided(): void
    {
        $branch = Branch::factory()->create();
        $terminal = $this->createPaymentTerminal($branch);
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'total'     => 20.00,
            'subtotal'  => 20.00,
        ]);

        $this->makeCashierActor($branch);

        $service = app(SplitPaymentService::class);
        $service->persistTranches($order, [
            [
                'mode'        => PosPaymentMethod::CARD,
                'amount'      => 20.00,
                'reference'   => '1234',
                'terminal_id' => $terminal->id,
            ],
        ]);

        $payment = OrderPayment::where('order_id', $order->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame(
            (int) $terminal->id,
            (int) $payment->terminal_id,
            'SplitPaymentService MUST write terminal_id from tranche payload'
        );
    }

    /** @test
     *  [F-SPLIT-PHANTOM-CARD-001 2026-05-17] CONTRACT CHANGED — terminal_id
     *  is now MANDATORY for every CARD tranche (phantom-CARD theft vector
     *  fix). A CARD tranche without terminal_id MUST be rejected at the
     *  service layer (defense-in-depth, even for non-HTTP callers).
     *
     *  The original "legacy nullable" contract still applies to non-CARD
     *  modes (CASH/MOBILE_BANKING/OTHER/TICKET_RESTAURANT) — see
     *  test_split_payment_terminal_id_remains_nullable_for_non_card.
     */
    public function test_split_payment_card_without_terminal_id_is_rejected(): void
    {
        $branch = Branch::factory()->create();
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'total'     => 20.00,
            'subtotal'  => 20.00,
        ]);

        $this->makeCashierActor($branch);

        $service = app(SplitPaymentService::class);

        $threw = false;
        try {
            $service->persistTranches($order, [
                ['mode' => PosPaymentMethod::CARD, 'amount' => 20.00, 'reference' => '1234'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $threw = true;
            $this->assertArrayHasKey(
                'payment_breakdown.0.terminal_id',
                $e->errors(),
                'Error key MUST point to the offending CARD tranche terminal_id.'
            );
        }
        $this->assertTrue($threw, 'Expected ValidationException on CARD tranche without terminal_id.');
        $this->assertSame(
            0,
            OrderPayment::query()->count(),
            'No OrderPayment should be persisted when CARD tranche lacks terminal_id.'
        );
    }

    /** @test
     *  Non-CARD modes (CASH/MOBILE_BANKING/OTHER/TICKET_RESTAURANT) keep the
     *  V1.0.1 legacy behaviour: terminal_id stays NULL when not provided.
     *  MASTER §5 Risk #4 — "Sans TPE" bucket still valid for cash-equivalent
     *  paths that do not transit a physical TPE.
     */
    public function test_split_payment_terminal_id_remains_nullable_for_non_card(): void
    {
        $branch = Branch::factory()->create();
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'total'     => 20.00,
            'subtotal'  => 20.00,
        ]);

        $cashier = $this->makeCashierActor($branch);
        app(\App\Services\Cash\CashDrawerService::class)
            ->openSession((int) $branch->id, $cashier->id, 100.00);

        $service = app(SplitPaymentService::class);
        $service->persistTranches($order, [
            ['mode' => PosPaymentMethod::CASH, 'amount' => 20.00, 'tendered' => 20.00],
        ]);

        $payment = OrderPayment::where('order_id', $order->id)->first();
        $this->assertNotNull($payment);
        $this->assertNull(
            $payment->terminal_id,
            'CASH tranche keeps terminal_id NULL — phantom-CARD fix only constrains CARD mode.'
        );
    }

    /** @test
     *  RefundWithCounterEntryService mirrors parent OrderPayment rows when
     *  creating the post-Z RETURN_OF mirror. terminal_id MUST be carried
     *  forward so the Z-report TPE breakdown stays balanced (refund debits
     *  the same terminal's fees).
     *
     *  Uses the same Z-seal pattern as
     *  Tests\Feature\Refund\RefundMirrorSplitPaymentTest::makeParentWithSplit —
     *  parent's created_at sits inside a manually-sealed Z window.
     */
    public function test_refund_with_counter_entry_persists_terminal_id(): void
    {
        $branch = Branch::factory()->create();
        $terminal = $this->createPaymentTerminal($branch);

        $cashier = $this->makeCashierActor($branch);

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
            'total'          => 50.00,
            'subtotal'       => 50.00,
            'total_tax'      => 0,
            'created_at'     => $opened->copy()->addHours(2),
        ]);
        $parent->fiscal_sequence_no = 100;
        $parent->save();

        OrderPayment::query()->create([
            'order_id'      => $parent->id,
            'branch_id'     => $branch->id,
            'mode'          => PosPaymentMethod::CARD,
            'amount'        => 50.00,
            'tendered'      => 50.00,
            'change_amount' => 0,
            'reference'     => '1234',
            'terminal_id'   => $terminal->id,
            'paid_at'       => $opened->copy()->addHours(2),
        ]);

        $mirror = app(RefundWithCounterEntryService::class)
            ->execute($parent->fresh(), 'customer return', $cashier->id);

        $mirrorPayments = OrderPayment::withoutGlobalScopes()
            ->where('order_id', $mirror->id)
            ->get();

        $this->assertCount(1, $mirrorPayments, 'Mirror should have one OrderPayment per parent payment.');
        $this->assertSame(
            (int) $terminal->id,
            (int) $mirrorPayments->first()->terminal_id,
            'RefundWithCounterEntryService MUST mirror terminal_id from parent payment.'
        );
        // Sanity : amount is negated, terminal_id is preserved.
        $this->assertEquals(-50.00, (float) $mirrorPayments->first()->amount);
    }

    /** @test
     *  End-to-end: writes flow through Z-report aggregation. Two CARD
     *  tranches on the same terminal are grouped into a single bucket
     *  with the correct fees_total. The "Sans TPE" bucket should NOT
     *  appear when every payment has a terminal_id.
     */
    public function test_zreport_tpe_breakdown_includes_terminal_id_writes(): void
    {
        $branch = Branch::factory()->create();
        $terminal = $this->createPaymentTerminal($branch);

        $this->makeCashierActor($branch);

        $order = Order::factory()->create([
            'branch_id'      => $branch->id,
            'total'          => 100.00,
            'subtotal'       => 100.00,
            'payment_status' => PaymentStatus::PAID,
        ]);

        $splitService = app(SplitPaymentService::class);
        $splitService->persistTranches($order, [
            ['mode' => PosPaymentMethod::CARD, 'amount' => 60.00, 'reference' => '1234', 'terminal_id' => $terminal->id],
            ['mode' => PosPaymentMethod::CARD, 'amount' => 40.00, 'reference' => '5678', 'terminal_id' => $terminal->id],
        ]);

        $enricher = app(ZReportCashEnrichmentService::class);
        $breakdown = $enricher->aggregateByTerminal(
            (int) $branch->id,
            now()->subHour(),
            now()->addMinute()
        );

        $this->assertCount(1, $breakdown, 'Expected single bucket for the only terminal — no "Sans TPE" leak.');
        $this->assertSame((int) $terminal->id, (int) $breakdown[0]['terminal_id']);
        $this->assertSame(100.00, (float) $breakdown[0]['card_total']);
        $this->assertSame(2, (int) $breakdown[0]['transactions_count']);

        // fees_total = 100 * 1.50/100 + 2 * 0.10 = 1.50 + 0.20 = 1.70
        $this->assertEqualsWithDelta(1.70, (float) $breakdown[0]['fees_total'], 0.001);
    }
}
