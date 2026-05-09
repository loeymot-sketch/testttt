<?php

namespace App\Jobs\Observability;

use App\Models\ActionLog;
use App\Models\Branch;
use App\Services\Observability\SloMetricCollector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * K-9 Phase E + G — Job d'évaluation SLO + alerting (cron 5 min).
 *
 * Rôle :
 *  - Itère sur les branches actives.
 *  - Pour chaque branche, appelle `SloMetricCollector::evaluate()`.
 *  - Persiste le snapshot dans `ActionLog` (category `slo_evaluation`).
 *  - Si `status=breach` : log canal `slack` + canal `observability` + broadcast
 *    `observability.slo_breach` (invalidé par l'enqueue si Slack webhook absent).
 *
 * Invariants :
 *  - Agrégation strictement serveur (ADR-10).
 *  - Isolation branch_id (K-8) : chaque branche traitée séparément.
 *  - Aucune PII (valeurs numériques uniquement).
 */
class SloEvaluatorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /*
     * [W9-AUDIT FIX-6] Observability evaluator must be resilient to transient DB
     * hiccups (Redis hiccup, replica lag) — a single failure should not blank an
     * entire 5-minute SLO bucket. Three attempts with exponential backoff give
     * the job ~30s of grace before final failure, while staying well under the
     * 5-minute scheduling cadence (no overlap risk).
     */
    public int $tries = 3;
    public int $timeout = 120;
    public int $backoff = 10;

    public function handle(SloMetricCollector $collector): void
    {
        $branches = Branch::query()
            ->where('status', 1)
            ->get();

        foreach ($branches as $branch) {
            try {
                $snapshot = $collector->evaluate($branch);
                $this->persistSnapshot($branch, $snapshot);
                $this->dispatchAlerts($branch, $snapshot);
            } catch (\Throwable $e) {
                Log::channel('observability')->error('slo_evaluator.branch_failed', [
                    'branch_id' => $branch->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    private function persistSnapshot(Branch $branch, array $snapshot): void
    {
        ActionLog::create([
            'branch_id' => $branch->id,
            'action'    => 'slo_evaluation',
            'resource'  => 'kiosk',
            'details'   => json_encode([
                'category' => 'slo_evaluation',
                'snapshot' => $snapshot,
                'at'       => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE),
        ]);

        Log::channel('observability')->info('slo_evaluation', [
            'category'  => 'slo_evaluation',
            'branch_id' => $branch->id,
            'snapshot'  => $snapshot,
        ]);
    }

    private function dispatchAlerts(Branch $branch, array $snapshot): void
    {
        foreach ($snapshot as $metric => $stat) {
            if (($stat['status'] ?? null) === 'breach') {
                $payload = [
                    'category'         => 'slo_breach',
                    'branch_id'        => $branch->id,
                    'branch_name'      => $branch->name,
                    'metric'           => $metric,
                    'value'            => $stat['value'],
                    'target'           => $stat['target'],
                    'threshold_breach' => $stat['threshold_breach'],
                ];

                Log::channel('observability')->alert('slo_breach', $payload);

                // Slack alert (gracieux : si webhook non configuré, la
                // factory Monolog Slack skip silencieusement via try/catch
                // de notre config ; on emballe tout de même).
                try {
                    Log::channel('slack')->alert(
                        "[K-9 SLO BREACH] Branch {$branch->name} (id={$branch->id}) — {$metric} = {$stat['value']} (breach < {$stat['threshold_breach']})",
                        $payload
                    );
                } catch (\Throwable $e) {
                    Log::channel('observability')->warning('slo_breach.slack_unreachable', [
                        'branch_id' => $branch->id, 'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
