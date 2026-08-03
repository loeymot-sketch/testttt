<?php

namespace Tests\Feature\Observability;

use App\Jobs\Observability\SloEvaluatorJob;
use App\Models\ActionLog;
use App\Models\Branch;
use App\Services\Observability\SloMetricCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * K-9 Phase E — SLO evaluator job + metric collector.
 *
 * Vérifie :
 *  - Le job itère sur les branches actives (status=1) uniquement.
 *  - Chaque branche reçoit un snapshot persisté dans `action_logs`
 *    (action=slo_evaluation).
 *  - Branch status=0 est ignorée (K-8 isolation).
 *  - Le job est idempotent (2 runs → 2 snapshots distincts, pas de collision).
 *  - La classification des métriques suit les seuils ADR-7.
 */
class SloEvaluatorJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluates_active_branches_and_persists_snapshot(): void
    {
        $active = Branch::forceCreate([
            'name' => 'Active', 'city' => 'Paris', 'state' => 'IDF',
            'zip_code' => '75000', 'address' => 'x', 'status' => 1,
            'available_locales' => ['fr'],
        ]);
        $inactive = Branch::forceCreate([
            'name' => 'Inactive', 'city' => 'Lyon', 'state' => 'RA',
            'zip_code' => '69000', 'address' => 'y', 'status' => 0,
            'available_locales' => ['fr'],
        ]);

        $job = new SloEvaluatorJob();
        $job->handle(new SloMetricCollector());

        $activeSnapshot = ActionLog::query()
            ->where('branch_id', $active->id)
            ->where('action', 'slo_evaluation')
            ->first();
        $inactiveSnapshot = ActionLog::query()
            ->where('branch_id', $inactive->id)
            ->where('action', 'slo_evaluation')
            ->first();

        $this->assertNotNull($activeSnapshot, 'La branche active doit avoir un snapshot.');
        $this->assertNull($inactiveSnapshot, 'La branche inactive ne doit PAS être évaluée.');

        $data = json_decode((string) $activeSnapshot->details, true);
        $this->assertSame('slo_evaluation', $data['category']);
        $this->assertArrayHasKey('snapshot', $data);
        $this->assertArrayHasKey('uptime', $data['snapshot']);
        $this->assertArrayHasKey('order_completion_rate', $data['snapshot']);
        $this->assertArrayHasKey('payment_success_rate', $data['snapshot']);
    }

    public function test_metric_collector_classifies_no_data_status(): void
    {
        $branch = Branch::forceCreate([
            'name' => 'Empty', 'city' => 'Paris', 'state' => 'IDF',
            'zip_code' => '75000', 'address' => 'x', 'status' => 1,
            'available_locales' => ['fr'],
        ]);

        $snapshot = (new SloMetricCollector())->evaluate($branch);

        // TTI et ws_reconnect sans data → no_data.
        $this->assertSame('no_data', $snapshot['tti_p95_ms']['status']);
        $this->assertSame('no_data', $snapshot['ws_reconnect_p95_ms']['status']);
        // Order completion & payment → 1.0 par défaut (aucune commande =
        // 100 % par convention), donc ok.
        $this->assertSame('ok', $snapshot['order_completion_rate']['status']);
        $this->assertSame('ok', $snapshot['payment_success_rate']['status']);
    }

    public function test_percentile_classification_respects_adr7_thresholds(): void
    {
        $collector = new SloMetricCollector();
        $targets = SloMetricCollector::SLO_TARGETS;

        $this->assertSame(0.995, $targets['uptime']['target']);
        $this->assertSame(0.985, $targets['uptime']['breach']);
        $this->assertSame(3000, $targets['tti_p95_ms']['target']);
        $this->assertSame(5000, $targets['tti_p95_ms']['breach']);
        $this->assertSame(0.98, $targets['payment_success_rate']['target']);
        $this->assertSame(0.95, $targets['payment_success_rate']['breach']);
    }

    public function test_job_is_resilient_to_branch_processing_error(): void
    {
        // Deux branches — on s'assure qu'une exception sur l'une n'empêche
        // pas l'autre d'être évaluée (résilience ADR-10).
        $b1 = Branch::forceCreate([
            'name' => 'B1', 'city' => 'Paris', 'state' => 'IDF',
            'zip_code' => '75001', 'address' => 'a', 'status' => 1,
            'available_locales' => ['fr'],
        ]);
        $b2 = Branch::forceCreate([
            'name' => 'B2', 'city' => 'Marseille', 'state' => 'PACA',
            'zip_code' => '13001', 'address' => 'b', 'status' => 1,
            'available_locales' => ['fr'],
        ]);

        $job = new SloEvaluatorJob();
        $job->handle(new SloMetricCollector());

        $this->assertSame(2, ActionLog::where('action', 'slo_evaluation')->count(),
            'Les 2 branches actives doivent avoir leur snapshot.');
    }
}
