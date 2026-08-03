<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentTerminal;
use App\Models\User;
use App\Services\Fiscal\ZReportCashEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [Wave F F-2 / Sprint 1C] Z report breakdown by payment terminal.
 *
 * Verrouille :
 *   T-1 aggregateByTerminal retourne une row par TPE pour la fenêtre
 *   T-2 fees_total = (cash+card) * fee_percent/100 + count * fee_fixed
 *   T-3 enrich() merge by_terminal + fees_total + net_after_fees
 *   T-4 paiements sans terminal_id sont regroupés sous "Sans TPE" (fees=0)
 *   T-5 branch isolation : paiements de l'autre branche exclus
 *   T-6 fenêtre exclut paiements hors (from, to]
 *   T-7 ZReportService inchangé (frozen-zone preserve) — read-only enrichment
 */
class ZReportTerminalBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $user;
    private ZReportCashEnrichmentService $enricher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->actingAs($this->user);

        $this->enricher = app(ZReportCashEnrichmentService::class);
    }

    /** T-1, T-2 — 2 terminals + 5 paiements → breakdown correct, fees attendus */
    public function test_breakdown_aggregates_by_terminal_with_correct_fees(): void
    {
        $from = now()->subHours(8);
        $to   = now()->addMinute();

        // TPE A : 1.5% + 0.05€ fixe
        $tpeA = PaymentTerminal::query()->create([
            'branch_id'    => $this->branch->id,
            'name'         => 'TPE Comptoir',
            'gateway_type' => 'ingenico',
            'fee_percent'  => 1.5,
            'fee_fixed'    => 0.05,
            'status'       => PaymentTerminal::STATUS_ACTIVE,
        ]);

        // TPE B : 1.0% + 0.00€ fixe
        $tpeB = PaymentTerminal::query()->create([
            'branch_id'    => $this->branch->id,
            'name'         => 'TPE Borne',
            'gateway_type' => 'stripe',
            'fee_percent'  => 1.0,
            'fee_fixed'    => 0.00,
            'status'       => PaymentTerminal::STATUS_ACTIVE,
        ]);

        // 3 paiements sur TPE A : 50€ card, 30€ card, 20€ cash → 100€ total, 3 tx
        // Expected fees A : 100 * 1.5/100 + 3 * 0.05 = 1.50 + 0.15 = 1.65
        $this->createPayment($tpeA, PosPaymentMethod::CARD, 50.00);
        $this->createPayment($tpeA, PosPaymentMethod::CARD, 30.00);
        $this->createPayment($tpeA, PosPaymentMethod::CASH, 20.00);

        // 2 paiements sur TPE B : 40€ card, 60€ card → 100€ total, 2 tx
        // Expected fees B : 100 * 1.0/100 + 2 * 0 = 1.00
        $this->createPayment($tpeB, PosPaymentMethod::CARD, 40.00);
        $this->createPayment($tpeB, PosPaymentMethod::CARD, 60.00);

        $breakdown = $this->enricher->aggregateByTerminal($this->branch->id, $from, $to);

        $this->assertCount(2, $breakdown);

        // Sorted alphabetically (TPE Borne < TPE Comptoir)
        $borne   = $breakdown[0];
        $comptoir = $breakdown[1];

        $this->assertSame('TPE Borne', $borne['name']);
        $this->assertSame('stripe', $borne['gateway_type']);
        $this->assertSame(100.00, $borne['card_total']);
        $this->assertSame(0.00, $borne['cash_total']);
        $this->assertSame(2, $borne['transactions_count']);
        $this->assertSame(1.00, $borne['fees_total']);

        $this->assertSame('TPE Comptoir', $comptoir['name']);
        $this->assertSame('ingenico', $comptoir['gateway_type']);
        $this->assertSame(80.00, $comptoir['card_total']);
        $this->assertSame(20.00, $comptoir['cash_total']);
        $this->assertSame(3, $comptoir['transactions_count']);
        $this->assertSame(1.65, $comptoir['fees_total']);
    }

    /** T-3 — enrich() merge sans casser le payload d'origine */
    public function test_enrich_merges_breakdown_and_net_after_fees(): void
    {
        $from = now()->subHour();
        $to   = now()->addMinute();

        $tpe = PaymentTerminal::query()->create([
            'branch_id'    => $this->branch->id,
            'name'         => 'TPE Unique',
            'gateway_type' => 'ingenico',
            'fee_percent'  => 2.0,
            'fee_fixed'    => 0.10,
            'status'       => PaymentTerminal::STATUS_ACTIVE,
        ]);

        // 2 paiements 50€ card = 100€ total, fees = 100 * 0.02 + 2 * 0.10 = 2.20
        $this->createPayment($tpe, PosPaymentMethod::CARD, 50.00);
        $this->createPayment($tpe, PosPaymentMethod::CARD, 50.00);

        $original = ['total_ttc' => 100.00, 'total_ht' => 95.00, 'order_count' => 2];
        $enriched = $this->enricher->enrich($original, $this->branch->id, $from, $to);

        // Original fields preserved
        $this->assertSame(100.00, $enriched['total_ttc']);
        $this->assertSame(2, $enriched['order_count']);

        // New fields added
        $this->assertArrayHasKey('by_terminal', $enriched);
        $this->assertArrayHasKey('fees_total', $enriched);
        $this->assertArrayHasKey('net_after_fees', $enriched);

        $this->assertCount(1, $enriched['by_terminal']);
        $this->assertSame(2.20, $enriched['fees_total']);
        // net_after_fees = sum(card+cash) - fees = 100.00 - 2.20 = 97.80
        $this->assertSame(97.80, $enriched['net_after_fees']);

        // Cash agg keys also present (from aggregateForWindow)
        $this->assertArrayHasKey('cash_variance', $enriched);
    }

    /** T-4 — paiements legacy sans terminal_id regroupés en "Sans TPE" avec fees=0 */
    public function test_legacy_payments_without_terminal_grouped_with_zero_fees(): void
    {
        $from = now()->subHour();
        $to   = now()->addMinute();

        // 2 paiements legacy : terminal_id = NULL
        $this->createPayment(null, PosPaymentMethod::CASH, 25.00);
        $this->createPayment(null, PosPaymentMethod::CARD, 15.00);

        $breakdown = $this->enricher->aggregateByTerminal($this->branch->id, $from, $to);

        $this->assertCount(1, $breakdown);
        $this->assertNull($breakdown[0]['terminal_id']);
        $this->assertSame('Sans TPE', $breakdown[0]['name']);
        $this->assertSame('unknown', $breakdown[0]['gateway_type']);
        $this->assertSame(25.00, $breakdown[0]['cash_total']);
        $this->assertSame(15.00, $breakdown[0]['card_total']);
        $this->assertSame(2, $breakdown[0]['transactions_count']);
        $this->assertSame(0.00, $breakdown[0]['fees_total']);  // no terminal → no fees
    }

    /** T-5 — branch isolation : paiements de l'autre branche exclus */
    public function test_branch_isolation_excludes_other_branch_payments(): void
    {
        $from = now()->subHour();
        $to   = now()->addMinute();

        $otherBranch = Branch::factory()->create();
        $tpeOther = PaymentTerminal::query()->create([
            'branch_id'    => $otherBranch->id,
            'name'         => 'TPE Concurrent',
            'gateway_type' => 'stripe',
            'fee_percent'  => 5.0,
            'fee_fixed'    => 1.00,
            'status'       => PaymentTerminal::STATUS_ACTIVE,
        ]);

        $tpeMine = PaymentTerminal::query()->create([
            'branch_id'    => $this->branch->id,
            'name'         => 'TPE Mine',
            'gateway_type' => 'ingenico',
            'fee_percent'  => 1.0,
            'fee_fixed'    => 0.00,
            'status'       => PaymentTerminal::STATUS_ACTIVE,
        ]);

        // Mes paiements : 30€ card
        $this->createPayment($tpeMine, PosPaymentMethod::CARD, 30.00);

        // Paiements de l'autre branche : 1000€ card (devrait être exclu)
        $this->createPayment($tpeOther, PosPaymentMethod::CARD, 1000.00, $otherBranch->id);

        $breakdown = $this->enricher->aggregateByTerminal($this->branch->id, $from, $to);

        $this->assertCount(1, $breakdown);
        $this->assertSame('TPE Mine', $breakdown[0]['name']);
        $this->assertSame(30.00, $breakdown[0]['card_total']);
        // Concurrent's terminal not in our breakdown
        foreach ($breakdown as $row) {
            $this->assertNotSame('TPE Concurrent', $row['name']);
        }
    }

    /** T-6 — paiement hors fenêtre exclu */
    public function test_payments_outside_window_excluded(): void
    {
        $tpe = PaymentTerminal::query()->create([
            'branch_id'    => $this->branch->id,
            'name'         => 'TPE Window',
            'gateway_type' => 'stripe',
            'fee_percent'  => 1.0,
            'fee_fixed'    => 0.00,
            'status'       => PaymentTerminal::STATUS_ACTIVE,
        ]);

        // Paiement aujourd'hui
        $this->createPayment($tpe, PosPaymentMethod::CARD, 100.00, null, now());

        // Paiement il y a 2 jours
        $this->createPayment($tpe, PosPaymentMethod::CARD, 500.00, null, now()->subDays(2));

        // Fenêtre = derniers 1h
        $breakdown = $this->enricher->aggregateByTerminal(
            $this->branch->id,
            now()->subHour(),
            now()->addMinute()
        );

        $this->assertCount(1, $breakdown);
        $this->assertSame(100.00, $breakdown[0]['card_total']);  // only today's payment
        $this->assertSame(1, $breakdown[0]['transactions_count']);
    }

    /** T-7 — aucune corruption du Z report : signature reste recalculable */
    public function test_enrich_is_read_only_no_zreport_mutation(): void
    {
        $from = now()->subHour();
        $to   = now()->addMinute();

        $tpe = PaymentTerminal::query()->create([
            'branch_id'    => $this->branch->id,
            'name'         => 'TPE Audit',
            'gateway_type' => 'ingenico',
            'fee_percent'  => 1.5,
            'fee_fixed'    => 0.05,
            'status'       => PaymentTerminal::STATUS_ACTIVE,
        ]);

        $this->createPayment($tpe, PosPaymentMethod::CARD, 50.00);

        $original = ['total_ttc' => 50.00, 'sequence_no' => 1, 'signature' => 'fake-hmac'];
        $enriched = $this->enricher->enrich($original, $this->branch->id, $from, $to);

        // Original signature byte-identical
        $this->assertSame('fake-hmac', $enriched['signature']);
        $this->assertSame(1, $enriched['sequence_no']);

        // No z_reports row mutated (enrich is purely computational)
        $this->assertSame(0, \DB::table('z_reports')->count());
    }

    /**
     * Helper to insert an order + order_payment.
     */
    private function createPayment(?PaymentTerminal $terminal, int $mode, float $amount, ?int $branchOverride = null, $paidAt = null): OrderPayment
    {
        $branchId = $branchOverride ?? $this->branch->id;

        $order = Order::factory()->create([
            'branch_id'      => $branchId,
            'status'         => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'total'          => $amount,
            'subtotal'       => $amount,
            'total_tax'      => 0,
        ]);

        return OrderPayment::query()->create([
            'order_id'      => $order->id,
            'branch_id'     => $branchId,
            'mode'          => $mode,
            'amount'        => $amount,
            'tendered'      => $amount,
            'change_amount' => 0,
            'reference'     => 'TEST',
            'terminal_id'   => $terminal?->id,
            'paid_at'       => $paidAt ?? now(),
        ]);
    }
}
