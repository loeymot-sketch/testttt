<?php

namespace App\Services\Observability;

use App\Models\ActionLog;
use App\Models\Branch;
use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * K-9 Phase E — Agrégateur SLO côté backend (ADR-10).
 *
 * Invariant : le frontend n'agrège JAMAIS les SLO. Il émet des SLI bruts
 * via `observability.*` analytics events (persistés dans `ActionLog` ou
 * `analytics_events` selon le pipeline). Ce service lit l'agrégat.
 *
 * Cibles (HANDOFF K-9 §1.3, ADR-7) :
 *  1. Uptime kiosk              ≥ 99.5 % (30 j)   — breach < 98.5 %
 *  2. TTI p95                   < 3000 ms (7 j)   — breach ≥ 5000 ms
 *  3. Order completion rate     ≥ 95 %   (7 j)    — breach < 90 %
 *  4. Echo reconnect p95        < 5000 ms (24 h)  — breach ≥ 10000 ms
 *  5. Payment success rate      ≥ 98 %   (7 j)    — breach < 95 %
 *
 * Le collector ne persiste rien par lui-même ; il retourne un snapshot
 * array. `SloEvaluatorJob` orchestre la persistance (`ActionLog` category
 * `slo_evaluation`) + l'alerting Slack.
 */
class SloMetricCollector
{
    public const SLO_TARGETS = [
        'uptime'                  => ['target' => 0.995, 'warn' => 0.990, 'breach' => 0.985],
        'tti_p95_ms'              => ['target' => 3000,  'warn' => 3000,  'breach' => 5000],
        'order_completion_rate'   => ['target' => 0.95,  'warn' => 0.92,  'breach' => 0.90],
        'ws_reconnect_p95_ms'     => ['target' => 5000,  'warn' => 5000,  'breach' => 10000],
        'payment_success_rate'    => ['target' => 0.98,  'warn' => 0.96,  'breach' => 0.95],
    ];

    /** @return array<string, array{value: float|int|null, status: string, target: float|int, threshold_breach: float|int}> */
    public function evaluate(Branch $branch): array
    {
        return [
            'uptime'                => $this->classifyRatio('uptime',
                $this->collectUptime($branch, 30)),
            'tti_p95_ms'            => $this->classifyLatency('tti_p95_ms',
                $this->collectTtiP95($branch, 7)),
            'order_completion_rate' => $this->classifyRatio('order_completion_rate',
                $this->collectOrderCompletionRate($branch, 7)),
            'ws_reconnect_p95_ms'   => $this->classifyLatency('ws_reconnect_p95_ms',
                $this->collectEchoReconnectP95($branch, 1)),
            'payment_success_rate'  => $this->classifyRatio('payment_success_rate',
                $this->collectPaymentSuccessRate($branch, 7)),
        ];
    }

    public function collectUptime(Branch $branch, int $windowDays): float
    {
        $since = Carbon::now()->subDays($windowDays);
        $windowCount = max(1, $windowDays * 24 * 6); // 10-min windows

        /*
         * [W8.5 HOTFIX] strftime("%s", ...) est SQLite-only ; en MySQL il
         * lance QueryException 1305 (foodking_test.strftime does not exist),
         * ce qui faisait crasher SloEvaluatorJob en CI MySQL. On dispatche
         * sur le driver pour produire une expression portable :
         *   - SQLite : strftime('%s', created_at) → epoch seconds
         *   - MySQL  : UNIX_TIMESTAMP(created_at) → epoch seconds
         * Les deux divisés par 600 donnent l'identifiant de fenêtre 10min.
         */
        $driver = ActionLog::query()->getConnection()->getDriverName();
        $bucketExpr = $driver === 'sqlite'
            ? "strftime('%s', created_at) / 600"
            : 'UNIX_TIMESTAMP(created_at) DIV 600';

        $observed = ActionLog::query()
            ->where('branch_id', $branch->id)
            ->where('created_at', '>=', $since)
            ->selectRaw("COUNT(DISTINCT {$bucketExpr}) as windows")
            ->value('windows') ?? 0;

        $ratio = min(1.0, max(0.0, ((int) $observed) / $windowCount));
        return round($ratio, 4);
    }

    public function collectTtiP95(Branch $branch, int $windowDays): ?float
    {
        $values = $this->eventPayloadValues(
            $branch, 'perf.lcp', $windowDays * 24, 'value_ms'
        );
        return $this->percentile($values, 95);
    }

    public function collectOrderCompletionRate(Branch $branch, int $windowDays): float
    {
        $since = Carbon::now()->subDays($windowDays);
        $started = ActionLog::query()
            ->where('branch_id', $branch->id)
            ->where('action', 'wizard_step_entered')
            ->where('created_at', '>=', $since)
            ->count();
        if ($started === 0) return 1.0;
        $completed = Order::query()
            ->where('branch_id', $branch->id)
            ->whereIn('order_status', ['pending', 'confirmed', 'delivered', 'accepted'])
            ->where('created_at', '>=', $since)
            ->count();
        $ratio = min(1.0, $completed / max(1, $started));
        return round($ratio, 4);
    }

    public function collectEchoReconnectP95(Branch $branch, int $windowHours): ?float
    {
        $values = $this->eventPayloadValues(
            $branch, 'observability.ws_reconnect', $windowHours, 'latency_ms'
        );
        return $this->percentile($values, 95);
    }

    public function collectPaymentSuccessRate(Branch $branch, int $windowDays): float
    {
        $since = Carbon::now()->subDays($windowDays);
        $attempted = Order::query()
            ->where('branch_id', $branch->id)
            ->where('created_at', '>=', $since)
            ->count();
        if ($attempted === 0) return 1.0;
        $paid = Order::query()
            ->where('branch_id', $branch->id)
            ->where('payment_status', 'paid')
            ->where('created_at', '>=', $since)
            ->count();
        $ratio = min(1.0, $paid / max(1, $attempted));
        return round($ratio, 4);
    }

    /**
     * Extraction des valeurs d'un event SLI via ActionLog.details JSON.
     * Schema accepté : details = JSON { event_name, payload: { [key] } }
     *   ou action == $eventName direct.
     */
    private function eventPayloadValues(Branch $branch, string $eventName, int $windowHours, string $payloadKey): array
    {
        $since = Carbon::now()->subHours($windowHours);
        $rows = ActionLog::query()
            ->where('branch_id', $branch->id)
            ->where('created_at', '>=', $since)
            ->where(function ($q) use ($eventName) {
                $q->where('action', $eventName)
                  ->orWhere('details', 'like', '%"'.$eventName.'"%');
            })
            ->pluck('details')
            ->filter();

        $values = [];
        foreach ($rows as $details) {
            if (!$details) continue;
            $parsed = is_array($details) ? $details : json_decode((string) $details, true);
            if (!is_array($parsed)) continue;
            $v = $parsed[$payloadKey]
                ?? ($parsed['payload'][$payloadKey] ?? null);
            if (is_numeric($v)) {
                $values[] = (float) $v;
            }
        }
        return $values;
    }

    private function percentile(array $values, int $p): ?float
    {
        if (empty($values)) return null;
        sort($values);
        $idx = (int) ceil(($p / 100) * count($values)) - 1;
        $idx = max(0, min(count($values) - 1, $idx));
        return round((float) $values[$idx], 2);
    }

    private function classifyRatio(string $key, $value): array
    {
        $t = self::SLO_TARGETS[$key];
        if ($value === null) return ['value' => null, 'status' => 'no_data',
            'target' => $t['target'], 'threshold_breach' => $t['breach']];
        $status = 'ok';
        if ($value < $t['breach']) $status = 'breach';
        elseif ($value < $t['warn']) $status = 'warn';
        return ['value' => $value, 'status' => $status,
            'target' => $t['target'], 'threshold_breach' => $t['breach']];
    }

    private function classifyLatency(string $key, $value): array
    {
        $t = self::SLO_TARGETS[$key];
        if ($value === null) return ['value' => null, 'status' => 'no_data',
            'target' => $t['target'], 'threshold_breach' => $t['breach']];
        $status = 'ok';
        if ($value >= $t['breach']) $status = 'breach';
        elseif ($value >= $t['warn']) $status = 'warn';
        return ['value' => $value, 'status' => $status,
            'target' => $t['target'], 'threshold_breach' => $t['breach']];
    }
}
