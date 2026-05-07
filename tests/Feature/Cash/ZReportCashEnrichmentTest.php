<?php

namespace Tests\Feature\Cash;

use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Cash\CashDrawerService;
use App\Services\Fiscal\ZReportCashEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * [AUDIT-F-003 / Sub-task 5] ZReport cash enrichment (decorator pattern).
 *
 * Verrouille:
 *  Z-1 aggregateForWindow somme variance des sessions reconciled dans (from, to]
 *  Z-2 enrich() merge non-destructif des champs cash_* dans aggregates
 *  Z-3 persistForClosedReport() update les colonnes cash sans toucher signature
 *  Z-4 ZReportService::aggregate() reste inchangé (frozen-zone preserved) →
 *      meme calcul total_ttc/total_ht/total_tva que sans le décorateur
 */
class ZReportCashEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $cashier;
    private CashDrawerService $cashService;
    private ZReportCashEnrichmentService $enricher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);

        $this->cashService = app(CashDrawerService::class);
        $this->enricher = app(ZReportCashEnrichmentService::class);

        $this->actingAs($this->cashier);
    }

    /** Z-1 — aggregateForWindow somme variance correctement */
    public function test_aggregate_sums_variance_for_reconciled_sessions_in_window(): void
    {
        $from = now()->subHours(8);
        $to = now();

        // Session 1 : variance +5
        $s1 = $this->cashService->openSession($this->branch->id, $this->cashier->id, 100.00);
        $this->cashService->recordMovement($s1->id, CashMovement::TYPE_ORDER_PAYMENT, 20.00, CashMovement::DIRECTION_IN);
        $this->cashService->closeSession($s1->id, 125.00);
        $this->cashService->reconcileSession($s1->id);

        // Re-open & reconcile session 2 : variance -3
        $s2 = $this->cashService->openSession($this->branch->id, $this->cashier->id, 50.00);
        $this->cashService->recordMovement($s2->id, CashMovement::TYPE_ORDER_PAYMENT, 10.00, CashMovement::DIRECTION_IN);
        $this->cashService->closeSession($s2->id, 57.00); // expected 60, variance -3
        $this->cashService->reconcileSession($s2->id);

        $cash = $this->enricher->aggregateForWindow($this->branch->id, $from, $to);

        $this->assertSame(150.00, $cash['cash_opening_amount']);
        $this->assertSame(182.00, $cash['cash_closing_amount']);
        $this->assertSame(2.00, $cash['cash_variance']); // +5 - 3
        $this->assertSame(2, $cash['cash_movements_count']);
    }

    /** Z-1 — sessions hors fenêtre exclues */
    public function test_aggregate_excludes_sessions_outside_window(): void
    {
        // Session future fictive (closed_at après "to") — créée et reconcilied via service,
        // puis on triche le closed_at à un instant futur via DB.
        $s = $this->cashService->openSession($this->branch->id, $this->cashier->id, 100.00);
        $this->cashService->closeSession($s->id, 100.00);
        $this->cashService->reconcileSession($s->id);

        // Force closed_at hors fenêtre
        \DB::table('cash_drawer_sessions')->where('id', $s->id)->update([
            'closed_at' => now()->addDay()->toDateTimeString(),
        ]);

        $cash = $this->enricher->aggregateForWindow($this->branch->id, now()->subHour(), now());

        $this->assertSame(0.00, $cash['cash_variance']);
        $this->assertSame(0, $cash['cash_movements_count']);
    }

    /** Z-1 — sessions OPEN ou CLOSED (pas reconciled) ignorées */
    public function test_aggregate_ignores_non_reconciled_sessions(): void
    {
        $s1 = $this->cashService->openSession($this->branch->id, $this->cashier->id, 100.00);
        $this->cashService->closeSession($s1->id, 100.00); // CLOSED, pas RECONCILED

        $cash = $this->enricher->aggregateForWindow($this->branch->id, now()->subHour(), now());
        $this->assertSame(0.00, $cash['cash_variance']);
    }

    /** Z-1 — branch isolation au sein du décorateur */
    public function test_aggregate_isolates_by_branch_id(): void
    {
        $branchOther = Branch::factory()->create();
        $userOther = User::factory()->create(['branch_id' => $branchOther->id]);

        // Notre cashier a une session +5
        $s1 = $this->cashService->openSession($this->branch->id, $this->cashier->id, 100.00);
        $this->cashService->closeSession($s1->id, 105.00);
        $this->cashService->reconcileSession($s1->id);

        // Other cashier a une session -10
        $this->actingAs($userOther);
        $s2 = $this->cashService->openSession($branchOther->id, $userOther->id, 100.00);
        $this->cashService->closeSession($s2->id, 90.00);
        $this->cashService->reconcileSession($s2->id);

        $cashOurs = $this->enricher->aggregateForWindow($this->branch->id, now()->subHour(), now());
        $this->assertSame(5.00, $cashOurs['cash_variance']);

        $cashOther = $this->enricher->aggregateForWindow($branchOther->id, now()->subHour(), now());
        $this->assertSame(-10.00, $cashOther['cash_variance']);
    }

    /** Z-2 — enrich() merge sans destruction */
    public function test_enrich_merges_cash_into_existing_aggregates(): void
    {
        $original = [
            'total_ttc'   => 100.00,
            'total_ht'    => 83.33,
            'total_tva'   => 16.67,
            'order_count' => 5,
        ];

        $merged = $this->enricher->enrich($original, $this->branch->id, now()->subHour(), now());

        $this->assertSame(100.00, $merged['total_ttc']);
        $this->assertSame(5, $merged['order_count']);
        $this->assertArrayHasKey('cash_variance', $merged);
        $this->assertArrayHasKey('cash_movements_count', $merged);
    }

    /** Z-3 — persistForClosedReport update sans toucher signature */
    public function test_persist_for_closed_report_updates_cash_columns_without_changing_signature(): void
    {
        // Créer un ZReport fictif "closed" avec une signature
        $report = ZReport::create([
            'branch_id'         => $this->branch->id,
            'sequence_no'       => 1,
            'opened_at'         => now()->subHours(8),
            'closed_at'         => now(),
            'total_ht'          => 100.00,
            'total_ttc'         => 120.00,
            'total_tva'         => 20.00,
            'total_by_method'   => [],
            'total_by_tax_rate' => [],
            'order_count'       => 5,
            'cancel_count'      => 0,
            'refund_count'      => 0,
            'signature'         => str_repeat('a', 64),
            'prev_hash'         => str_repeat('0', 64),
            'status'            => ZReport::STATUS_CLOSED,
        ]);

        $originalSignature = $report->signature;

        // Une session reconcilied dans la fenêtre
        $s = $this->cashService->openSession($this->branch->id, $this->cashier->id, 100.00);
        $this->cashService->closeSession($s->id, 110.00); // variance +10
        $this->cashService->reconcileSession($s->id);

        $updated = $this->enricher->persistForClosedReport($report);

        $this->assertSame(100.00, (float) $updated->cash_opening_amount);
        $this->assertSame(110.00, (float) $updated->cash_closing_amount);
        $this->assertSame(10.00, (float) $updated->cash_variance);

        // Signature non modifiée — c'est l'invariant critique de Sub-task 5
        $this->assertSame($originalSignature, $updated->signature);
        // Aggrégats originels non modifiés
        $this->assertSame(120.00, (float) $updated->total_ttc);
        $this->assertSame(5, $updated->order_count);
    }

    /** Z-3 — non-CLOSED reports skipped */
    public function test_persist_for_closed_report_noop_on_open_report(): void
    {
        $report = ZReport::create([
            'branch_id'         => $this->branch->id,
            'sequence_no'       => 1,
            'opened_at'         => now()->subHour(),
            'total_by_method'   => [],
            'total_by_tax_rate' => [],
            'status'            => ZReport::STATUS_OPEN,
        ]);

        $result = $this->enricher->persistForClosedReport($report);
        $this->assertNull($result->cash_variance);
    }
}
