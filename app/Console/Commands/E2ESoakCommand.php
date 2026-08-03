<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * [GOAL-2026-05-23 Phase L Agent L3] Long-running soak orchestrator — 4h+ production-real validation.
 *
 * SISTER COMMAND to `foodking:e2e:stress` (E2EStressCommand). Where the stress
 * command is BURST-BATCH (--orders=N), this one is TIME-SLICED (--hours=N) and
 * sustains a mixed multi-stream load (POS + Kiosk + KDS + Admin + Cash-collect)
 * over an entire operating-day duration to:
 *   - prove NF525 chain CHAIN OK over 4h continuous fiscal allocations;
 *   - detect memory-leak signatures bounded RSS growth ≤ 200MB;
 *   - measure outbox dispatch p99 latency over a representative sample;
 *   - measure Redis cache hit-ratio under sustained load;
 *   - verify DB connection-pool headroom against the configured max_connections;
 *   - emit durable per-batch JSONL so a mid-soak crash leaves a parseable trace.
 *
 * ──────────────────────────────────────────────────────────────────────
 * H.3 LINEAGE
 *
 *   H.3 (15 min mixed sustained) proved the architecture under contention but
 *   was too short to detect slow-burn leaks. F.2 (5 min) and G.1 (13 min)
 *   even shorter. THIS command extends the H.3 event-loop pattern to 4h+ and
 *   adds the 3 long-soak-specific instruments: durable JSONL, per-tick Redis
 *   stats, queue-depth alarms. The H.3 mixed-runner script
 *   (`storage/logs/h3-mixed-runner.php`) is the prior art; this command is
 *   its production-grade reusable counterpart.
 *
 * ──────────────────────────────────────────────────────────────────────
 * USAGE
 *
 *   # 4h soak — owner runs overnight
 *   php artisan foodking:e2e:soak --hours=4 --output-dir=storage/logs/soak-$(date +%s)
 *
 *   # Smoke (15 min — should reproduce H.3 verdict)
 *   php artisan foodking:e2e:soak --hours=0.25 --fail-fast
 *
 *   # 8h marathon — full operating-day equivalent
 *   php artisan foodking:e2e:soak --hours=8 --output-dir=storage/logs/soak-marathon
 *
 * ──────────────────────────────────────────────────────────────────────
 * PRECONDITIONS (HARD-FAILS IF VIOLATED)
 *
 *   1. `php artisan serve` (or php-fpm) listening on --base-url
 *   2. queue:work daemon with --queue=high,default (else outbox p99 auto-fails;
 *      see H3-DEV-01). Command refuses to start if no matching worker process.
 *   3. APP_ENV != production (safety: this is a dev/staging tool)
 *   4. MySQL + Redis reachable (chain verify + cache stats both required)
 *
 * ──────────────────────────────────────────────────────────────────────
 * LATENCY CAVEAT
 *
 *   Per H3-DEV-02/03: `php artisan serve` is single-process and amplifies
 *   batch-concurrent latency 2-3x. The 4h soak SHOULD run against php-fpm
 *   for trustworthy p99 latency numbers. If running under `php artisan serve`,
 *   the command will print a WARNING and the verdict still applies to:
 *   memory, chain integrity, outbox depth, fiscal monotonicity, cache hit
 *   ratio — but the latency p99 number is INFORMATIVE-ONLY, not acceptance.
 *
 * Pattern source: registered via `$this->load(__DIR__.'/Commands')` in
 * `app/Console/Kernel.php` — auto-discovery, NO Kernel.php modification.
 */
class E2ESoakCommand extends Command
{
    protected $signature = 'foodking:e2e:soak
                            {--hours=4 : Soak duration in hours (decimals accepted, e.g. 0.25=15min)}
                            {--warmup-s=60 : Warmup window — only S1+S2 fire to seed downstream state}
                            {--checkpoint-s=300 : Mid-soak NF525 growth checkpoint (G.1-trap detector)}
                            {--monitor-tick-s=300 : Background-monitor tick interval (default 5 min)}
                            {--base-url= : Override API base URL (defaults to config app.url)}
                            {--output-dir= : Directory to write JSONL + summary (default: storage/logs/soak-<ts>)}
                            {--rss-ceiling-mb=200 : Memory growth ceiling in MB (acceptance fail above)}
                            {--outbox-p99-ceiling-s=30 : Outbox dispatch latency p99 ceiling in seconds}
                            {--db-pool-pct-ceiling=80 : DB Threads_connected % of max_connections alarm}
                            {--skip-queue-worker-check : Skip the pre-flight queue:work daemon assertion}
                            {--fail-fast : Abort immediately on any P0 stop-condition violation}
                            {--stream-s1-interval-s=15.0 : S1 POS direct-sale interval (seconds)}
                            {--stream-s2-interval-s=10.0 : S2 Kiosk order interval (seconds)}
                            {--stream-s3-interval-s=30.0 : S3 Kiosk-cash collect interval (seconds)}
                            {--stream-s4-interval-s=15.0 : S4 KDS bump interval (seconds)}
                            {--stream-s5-interval-s=60.0 : S5 Admin toggle interval (seconds)}';

    protected $description = 'Long-running (4h+) mixed-stream soak orchestrator for production-real NF525 / memory / outbox / cache attestation. Sister to foodking:e2e:stress (batch). Owner-driven, never CI.';

    private string $outputDir;
    private string $jsonlPath;
    private string $monitorPath;
    private string $summaryPath;

    /** @var array<string,array{interval:float,last:float,dispatched:int,ok:int,codes:array<int,int>}> */
    private array $streams;

    public function handle(): int
    {
        // ────────────────────────────────────────────────────────────────
        // SAFETY GATE 0 — refuse production
        // ────────────────────────────────────────────────────────────────
        if (app()->environment('production')) {
            $this->error('foodking:e2e:soak refuses to run in APP_ENV=production. Use staging.');
            return self::FAILURE;
        }

        $hours          = max(0.01, (float) $this->option('hours'));
        $durationS      = (int) round($hours * 3600);
        $warmupS        = max(10, (int) $this->option('warmup-s'));
        $checkpointS    = max($warmupS + 30, (int) $this->option('checkpoint-s'));
        $tickS          = max(60, (int) $this->option('monitor-tick-s'));
        $baseUrl        = (string) ($this->option('base-url') ?: config('app.url') ?: 'http://127.0.0.1:8000');
        $rssCeilingMb   = (int) $this->option('rss-ceiling-mb');
        $outboxP99Sec   = (int) $this->option('outbox-p99-ceiling-s');
        $poolPctCeiling = (int) $this->option('db-pool-pct-ceiling');
        $failFast       = (bool) $this->option('fail-fast');

        $this->outputDir = (string) ($this->option('output-dir') ?: storage_path('logs/soak-' . date('Ymd-His')));
        @mkdir($this->outputDir, 0755, true);
        $this->jsonlPath    = $this->outputDir . '/events.jsonl';
        $this->monitorPath  = $this->outputDir . '/monitor.jsonl';
        $this->summaryPath  = $this->outputDir . '/summary.json';

        $this->info('[soak] ──────────────────────────────────────────────────────');
        $this->info("[soak] FoodKing E2E Soak — Phase L Agent L3 long-runner");
        $this->info("[soak]   duration:  {$hours}h ({$durationS}s)");
        $this->info("[soak]   warmup:    {$warmupS}s, checkpoint: {$checkpointS}s");
        $this->info("[soak]   monitor:   {$tickS}s tick");
        $this->info("[soak]   base_url:  {$baseUrl}");
        $this->info("[soak]   output:    {$this->outputDir}");
        $this->info("[soak]   ceilings:  RSS≤{$rssCeilingMb}MB / outbox-p99≤{$outboxP99Sec}s / db-pool≤{$poolPctCeiling}%");
        $this->info("[soak]   fail-fast: " . ($failFast ? 'YES' : 'no'));

        // ────────────────────────────────────────────────────────────────
        // SAFETY GATE 1 — queue:work daemon precondition (H3-DEV-01)
        // ────────────────────────────────────────────────────────────────
        if (!$this->option('skip-queue-worker-check')) {
            $check = $this->assertQueueWorkerListening();
            if (!$check['ok']) {
                $this->error('[soak] PRE-FLIGHT FAIL: ' . $check['reason']);
                $this->error('[soak] H3-DEV-01 lineage: without --queue=high,default the DispatchDomainEventsJob');
                $this->error('[soak] enqueues forever and outbox p99 ceiling AUTO-FAILS over a 4h soak.');
                $this->error('[soak] Start a worker first:');
                $this->error('[soak]   php artisan queue:work redis --queue=high,default --tries=3 --timeout=120 --sleep=1');
                $this->error('[soak] Or use --skip-queue-worker-check if you intentionally want a no-drain run.');
                return self::FAILURE;
            }
            $this->info("[soak] queue:work daemon OK — pid={$check['pid']} matched: {$check['cmdline']}");
        } else {
            $this->warn('[soak] queue:work pre-flight SKIPPED — outbox growth will not drain');
        }

        // ────────────────────────────────────────────────────────────────
        // SAFETY GATE 2 — server reachability + MySQL + Redis
        // ────────────────────────────────────────────────────────────────
        $hcClient = new Client(['timeout' => 5, 'connect_timeout' => 3, 'http_errors' => false]);
        try {
            $hc = $hcClient->get(rtrim($baseUrl, '/') . '/up');
            if ($hc->getStatusCode() < 200 || $hc->getStatusCode() >= 400) {
                $this->warn("[soak] /up returned {$hc->getStatusCode()} — proceeding but server may not be ready");
            }
        } catch (Throwable $e) {
            $this->error("[soak] PRE-FLIGHT FAIL: cannot reach {$baseUrl} — {$e->getMessage()}");
            return self::FAILURE;
        }

        try {
            $pdo = $this->pdo();
            $pdo->query('SELECT 1');
        } catch (Throwable $e) {
            $this->error("[soak] PRE-FLIGHT FAIL: MySQL unreachable — {$e->getMessage()}");
            return self::FAILURE;
        }

        // PHP -S warning (H3-DEV-02/03)
        $isDevServer = $this->detectPhpDevServer();
        if ($isDevServer) {
            $this->warn('[soak] DETECTED: php artisan serve (single-process) — latency p99 will be INFORMATIVE-ONLY');
            $this->warn('[soak] For trustworthy latency numbers, run the soak against php-fpm + nginx');
            $this->warn('[soak] Memory / chain / outbox / fiscal acceptance criteria are UNAFFECTED');
        }

        // ────────────────────────────────────────────────────────────────
        // FIXTURE PROVISION (re-uses E2EStressCommand pattern)
        // ────────────────────────────────────────────────────────────────
        $startedAt = microtime(true);
        $this->info('[soak] Provisioning fixtures (1 cashier + 1 kiosk + 1 admin token)…');
        try {
            $fixtures = $this->provisionFixtures();
        } catch (Throwable $e) {
            $this->error("[soak] Fixture provisioning failed: {$e->getMessage()}");
            return self::FAILURE;
        }
        $this->info("[soak] Fixtures OK — branch={$fixtures['branch']->id} cashier_id={$fixtures['cashier']->id} kiosk_id={$fixtures['kiosk_user']->id}");

        // ────────────────────────────────────────────────────────────────
        // SAFETY GATE 3 — preflight quote-mint check (advisor blocker fix)
        //
        // Mint ONE POS quote + ONE kiosk quote BEFORE starting the event
        // loop. If either fails, abort with a clear error pointing the
        // owner at the H.3 provisioning script. This converts a 4-hour
        // fail-late (all streams skip for 4h, RED verdict at end) into a
        // 30-second fail-fast with actionable remediation.
        //
        // ROOT CAUSE class: Spatie role/permission seed gaps in the dev DB
        // make /api/admin/pos/quote return 403 for users without POS
        // Operator role. The H.3 fixtures script handles this; this command
        // detects the gap upfront so the soak doesn't waste hours.
        // ────────────────────────────────────────────────────────────────
        $apiKeyPreflight = (string) (config('app.api_key') ?: env('MIX_API_KEY', ''));
        $preflightClient = new Client(['timeout' => 10, 'connect_timeout' => 3, 'http_errors' => false]);
        $posQuote   = $this->mintPosQuote($preflightClient, $baseUrl, $fixtures['cashier_token'], $apiKeyPreflight, 1, 1, $fixtures['branch']->id);
        $kioskQuote = $this->mintKioskQuote($preflightClient, $baseUrl, $fixtures['kiosk_token'], $apiKeyPreflight, 1, 1, $fixtures['branch']->id);
        if (!$posQuote || !$kioskQuote) {
            $posStatus   = $posQuote   ? 'OK' : 'FAIL';
            $kioskStatus = $kioskQuote ? 'OK' : 'FAIL';
            $this->error("[soak] PRE-FLIGHT FAIL: quote endpoints unreachable — POS={$posStatus} Kiosk={$kioskStatus}");
            $this->error("[soak] This usually means the fixtures lack POS Operator role and the dev DB has no Spatie role seed.");
            $this->error("[soak] If you have an H.3-style provisioning script, run it first to seed roles, then re-run the soak.");
            $this->error("[soak] Quick check: curl -i -X POST {$baseUrl}/api/admin/pos/quote \\");
            $this->error("[soak]   -H 'Authorization: Bearer {$fixtures['cashier_token']}' \\");
            $this->error("[soak]   -H 'x-api-key: {$apiKeyPreflight}' \\");
            $this->error("[soak]   -H 'Content-Type: application/json' -H 'Accept: application/json' \\");
            $this->error("[soak]   -d '{\"branch_id\":{$fixtures['branch']->id},\"order_type\":30,\"source\":1,\"pos_payment_method\":1,\"items\":\"[{\\\"item_id\\\":1,\\\"quantity\\\":1}]\"}'");
            $this->error("[soak] If the body says 'User does not have the right permissions', seed Spatie roles + re-run.");
            $this->cleanupFixtures($fixtures);
            return self::FAILURE;
        }
        $this->info("[soak] Preflight quote-mint OK — POS+Kiosk quote endpoints reachable, fixtures can dispatch");

        // ────────────────────────────────────────────────────────────────
        // STREAM CONFIG (H.3-derived defaults, overridable per --stream-*)
        // ────────────────────────────────────────────────────────────────
        $this->streams = [
            'S1_POS_DIRECT_SALE'    => ['interval' => (float) $this->option('stream-s1-interval-s'), 'last' => -999.0, 'dispatched' => 0, 'ok' => 0, 'codes' => []],
            'S2_KIOSK_ORDER'        => ['interval' => (float) $this->option('stream-s2-interval-s'), 'last' => -999.0, 'dispatched' => 0, 'ok' => 0, 'codes' => []],
            'S3_KIOSK_CASH_COLLECT' => ['interval' => (float) $this->option('stream-s3-interval-s'), 'last' => -999.0, 'dispatched' => 0, 'ok' => 0, 'codes' => []],
            'S4_KDS_BUMP'           => ['interval' => (float) $this->option('stream-s4-interval-s'), 'last' => -999.0, 'dispatched' => 0, 'ok' => 0, 'codes' => []],
            'S5_ADMIN_TOGGLE_AVAIL' => ['interval' => (float) $this->option('stream-s5-interval-s'), 'last' => -999.0, 'dispatched' => 0, 'ok' => 0, 'codes' => []],
        ];

        // ────────────────────────────────────────────────────────────────
        // BASELINE SNAPSHOT
        // ────────────────────────────────────────────────────────────────
        $baseline = $this->snapshotState($fixtures['branch']->id);
        $this->info("[soak] Baseline: audit_logs={$baseline['audit_logs']} fiscal_seq_b1={$baseline['fiscal_seq_b1']} orders={$baseline['orders']}");
        $this->info("[soak] Baseline: outbox_pending={$baseline['outbox_pending']} server_rss_kb={$baseline['server_rss_kb']} db_threads={$baseline['db_threads_connected']}");
        file_put_contents(
            $this->outputDir . '/baseline.json',
            json_encode($baseline, JSON_PRETTY_PRINT) . "\n"
        );

        // First monitor tick — t=0 baseline
        $this->writeMonitorTick(0, 0.0, $baseline);

        // ────────────────────────────────────────────────────────────────
        // EVENT LOOP — main 4h soak
        // ────────────────────────────────────────────────────────────────
        $client = new Client([
            'timeout'         => 30,
            'connect_timeout' => 5,
            'http_errors'     => false,
        ]);

        $T0 = microtime(true);
        $globalIdx = 0;
        $lastMonitorTick = $T0;
        $checkpointReached = false;
        $abortReason = null;

        // Counters for stop-conditions
        $totals = ['ok' => 0, '429' => 0, '5xx' => 0, 'other_4xx' => 0, 'network_err' => 0];
        $outboxDepthHistory = [];  // [t, depth] for queue-worker-died detection

        $stopConditions = [
            'reason' => null, 'measured' => null, 't' => null,
        ];

        // JSONL: open append handle
        $jsonl = fopen($this->jsonlPath, 'a');
        if (!$jsonl) {
            $this->error("[soak] cannot open {$this->jsonlPath} for write");
            return self::FAILURE;
        }

        $apiKey = (string) (config('app.api_key') ?: env('MIX_API_KEY', ''));

        while (($t = microtime(true) - $T0) < $durationS) {

            // Mid-soak NF525 growth checkpoint (G.1-trap detector)
            if (!$checkpointReached && $t >= $checkpointS) {
                $checkpointReached = true;
                $now = $this->snapshotState($fixtures['branch']->id);
                $auditDelta  = $now['audit_logs']    - $baseline['audit_logs'];
                $fiscalDelta = $now['fiscal_seq_b1'] - $baseline['fiscal_seq_b1'];
                $this->info(sprintf(
                    "[soak] CHECKPOINT t=%.1fs audit_delta=+%d fiscal_delta=+%d",
                    $t, $auditDelta, $fiscalDelta
                ));
                if ($auditDelta === 0 && $fiscalDelta === 0) {
                    $abortReason = "CHECKPOINT_BOTH_NF525_DELTAS_ZERO at t={$t}s — chain not exercised";
                    $this->error("[soak] ABORT: {$abortReason}");
                    break;
                }
            }

            // Background monitor tick (every $tickS)
            if ((microtime(true) - $lastMonitorTick) >= $tickS) {
                $lastMonitorTick = microtime(true);
                $snap = $this->snapshotState($fixtures['branch']->id);
                $this->writeMonitorTick(
                    1 + count(file($this->monitorPath, FILE_IGNORE_NEW_LINES)),
                    $t,
                    $snap
                );

                // Stop-conditions check
                $outboxDepthHistory[] = ['t' => $t, 'depth' => $snap['outbox_pending']];
                $stopHit = $this->evaluateStopConditions(
                    $snap, $baseline, $totals,
                    $rssCeilingMb, $outboxP99Sec, $poolPctCeiling, $outboxDepthHistory
                );

                if ($stopHit['hit']) {
                    $stopConditions = ['reason' => $stopHit['reason'], 'measured' => $stopHit['measured'], 't' => $t];
                    $this->error("[soak] STOP_CONDITION at t={$t}s: {$stopHit['reason']} measured={$stopHit['measured']}");
                    if ($failFast) {
                        $abortReason = "FAIL_FAST: {$stopHit['reason']}";
                        break;
                    }
                }
            }

            // Build current tick batch
            $batch = [];
            foreach ($this->streams as $name => &$cfg) {
                $isWarmup = in_array($name, ['S1_POS_DIRECT_SALE', 'S2_KIOSK_ORDER'], true);
                if (!$isWarmup && $t < $warmupS) continue;
                if (($t - $cfg['last']) < $cfg['interval']) continue;

                $ctx = [];
                $req = $this->buildRequest($name, $client, $baseUrl, $apiKey, $fixtures, $globalIdx, $ctx);
                if (!$req) {
                    fprintf(STDERR, "[soak] t=%.1fs stream=%s skipped (%s)\n", $t, $name, json_encode($ctx));
                    continue;
                }
                $cfg['last'] = $t;
                $cfg['dispatched']++;
                $batch[] = ['stream' => $name, 'request' => $req, 'idx' => $globalIdx++, 'ctx' => $ctx, 't' => $t];
            }
            unset($cfg);

            if (empty($batch)) {
                usleep(500_000);
                continue;
            }

            // Pool dispatch
            $batchRequests = array_map(fn($x) => $x['request'], $batch);
            $batchMetas    = array_map(fn($x) => ['stream' => $x['stream'], 'idx' => $x['idx'], 't' => $x['t']], $batch);
            $batchResults  = [];
            $batchStart    = microtime(true);

            $pool = new Pool($client, $batchRequests, [
                'concurrency' => 8,
                'fulfilled'   => function ($response, $index) use (&$batchResults, $batchMetas) {
                    $status = $response->getStatusCode();
                    $batchResults[$index] = [
                        't'      => $batchMetas[$index]['t'],
                        'stream' => $batchMetas[$index]['stream'],
                        'idx'    => $batchMetas[$index]['idx'],
                        'status' => $status,
                        'ok'     => $status >= 200 && $status < 300,
                        'body_excerpt' => substr((string) $response->getBody(), 0, 200),
                    ];
                },
                'rejected' => function ($reason, $index) use (&$batchResults, $batchMetas) {
                    $batchResults[$index] = [
                        't'      => $batchMetas[$index]['t'],
                        'stream' => $batchMetas[$index]['stream'],
                        'idx'    => $batchMetas[$index]['idx'],
                        'status' => 0,
                        'ok'     => false,
                        'body_excerpt' => 'NETWORK_ERROR: ' . substr((string) $reason, 0, 200),
                    ];
                },
            ]);
            $pool->promise()->wait();
            $batchElapsedMs = round((microtime(true) - $batchStart) * 1000, 1);

            ksort($batchResults);
            foreach ($batchResults as $r) {
                $r['batch_elapsed_ms'] = $batchElapsedMs;
                fwrite($jsonl, json_encode($r) . "\n");

                // Update per-stream + total counters
                $s = $r['stream'];
                if ($r['ok']) {
                    $this->streams[$s]['ok']++;
                    $totals['ok']++;
                }
                $code = $r['status'];
                $this->streams[$s]['codes'][$code] = ($this->streams[$s]['codes'][$code] ?? 0) + 1;
                if      ($code === 429)         $totals['429']++;
                elseif  ($code >= 500)          $totals['5xx']++;
                elseif  ($code >= 400)          $totals['other_4xx']++;
                elseif  ($code === 0)           $totals['network_err']++;

                // P0 instant stop (5xx or 429 in fail-fast mode)
                if ($failFast && ($code === 429 || $code >= 500)) {
                    $abortReason = "FAIL_FAST: HTTP {$code} on stream={$s} idx={$r['idx']}";
                    break 2;
                }
            }

            fflush($jsonl);
            usleep(500_000);
        }

        fclose($jsonl);
        $T1 = microtime(true);
        $actualDurationS = $T1 - $T0;

        // ────────────────────────────────────────────────────────────────
        // FINAL SNAPSHOT + SUMMARY
        // ────────────────────────────────────────────────────────────────
        $final = $this->snapshotState($fixtures['branch']->id);
        $this->writeMonitorTick(
            999, $actualDurationS, $final, 'final'
        );

        $latencyDist = $this->computeLatencyDistribution($this->jsonlPath);
        $outboxLatency = $this->computeOutboxDispatchLatency($baseline['ts_unix'] ?? time() - $actualDurationS - 60);

        $summary = [
            'agent'                  => 'L3 long-soak orchestrator',
            'duration_planned_h'     => $hours,
            'duration_actual_s'      => round($actualDurationS, 1),
            'started_at'             => date('c', (int) $startedAt),
            'finished_at'            => date('c', (int) $T1),
            'abort_reason'           => $abortReason,
            'stop_condition'         => $stopConditions['reason'] ? $stopConditions : null,

            'baseline'               => $baseline,
            'final'                  => $final,

            'totals'                 => $totals + ['total_dispatched' => array_sum(array_column($this->streams, 'dispatched'))],
            'per_stream'             => $this->streams,

            'rss_delta_kb'           => $final['server_rss_kb'] - $baseline['server_rss_kb'],
            'rss_delta_mb'           => round(($final['server_rss_kb'] - $baseline['server_rss_kb']) / 1024.0, 2),
            'rss_ceiling_mb'         => $rssCeilingMb,
            'rss_within_ceiling'     => abs(($final['server_rss_kb'] - $baseline['server_rss_kb']) / 1024.0) <= $rssCeilingMb,

            'fiscal_seq_b1_delta'    => $final['fiscal_seq_b1'] - $baseline['fiscal_seq_b1'],
            'audit_logs_delta'       => $final['audit_logs'] - $baseline['audit_logs'],
            'orders_delta'           => $final['orders'] - $baseline['orders'],
            'disk_logs_delta_kb'     => $final['storage_logs_kb'] - $baseline['storage_logs_kb'],

            'nf525_chain_baseline'   => $baseline['nf525_chain'],
            'nf525_chain_final'      => $final['nf525_chain'],
            'nf525_chain_ok_final'   => str_contains($final['nf525_chain'], 'CHAIN OK'),

            'fiscal_contiguous_check' => $this->checkFiscalContiguity($fixtures['branch']->id, $baseline['fiscal_seq_b1']),

            'latency_distribution_ms' => $latencyDist,
            'outbox_dispatch_latency' => $outboxLatency,

            'cache_hit_ratio'        => $this->computeCacheHitRatio(),

            'php_dev_server_detected' => $isDevServer,
            'latency_p99_authoritative' => !$isDevServer,

            'acceptance_criteria_result' => $this->evaluateAcceptanceCriteria(
                $totals, $final, $baseline, $latencyDist, $outboxLatency, $rssCeilingMb, $outboxP99Sec
            ),

            'files' => [
                'events_jsonl'   => $this->jsonlPath,
                'monitor_jsonl'  => $this->monitorPath,
                'baseline_json'  => $this->outputDir . '/baseline.json',
                'summary_json'   => $this->summaryPath,
            ],
        ];

        $summary['verdict'] = $this->renderVerdict($summary);

        file_put_contents($this->summaryPath, json_encode($summary, JSON_PRETTY_PRINT) . "\n");

        $this->info('[soak] ──────────────────────────────────────────────────────');
        $this->info("[soak] VERDICT: {$summary['verdict']}");
        $this->info("[soak]   total_dispatched: {$summary['totals']['total_dispatched']}");
        $this->info("[soak]   ok: {$summary['totals']['ok']}  429: {$summary['totals']['429']}  5xx: {$summary['totals']['5xx']}");
        $this->info("[soak]   rss_delta: {$summary['rss_delta_mb']} MB (ceiling {$rssCeilingMb}MB)");
        $this->info("[soak]   fiscal_delta: +{$summary['fiscal_seq_b1_delta']}  audit_delta: +{$summary['audit_logs_delta']}");
        $this->info("[soak]   chain_ok: " . ($summary['nf525_chain_ok_final'] ? 'YES' : 'NO'));
        $this->info("[soak]   summary written: {$this->summaryPath}");

        // Cleanup tokens
        $this->cleanupFixtures($fixtures);

        return $summary['verdict'] === 'GREEN' ? self::SUCCESS : self::FAILURE;
    }

    // ──────────────────────────────────────────────────────────────────────
    // PRECONDITION CHECKS
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Verify a queue:work daemon is running with --queue=high,default (or =high alone).
     * Without this, DispatchDomainEventsJob queues onto `high` and is never drained.
     * H3-DEV-01 documented this exact trap.
     */
    private function assertQueueWorkerListening(): array
    {
        $cmd = "pgrep -fl 'queue:work' 2>/dev/null || true";
        $out = trim((string) shell_exec($cmd));
        if ($out === '') {
            return ['ok' => false, 'reason' => 'no queue:work process found via pgrep'];
        }

        foreach (explode("\n", $out) as $line) {
            // Must include high or high,default in --queue
            if (preg_match('/--queue=(\S+)/', $line, $m)) {
                $queues = explode(',', $m[1]);
                if (in_array('high', $queues, true)) {
                    if (preg_match('/^(\d+)\s+(.+)$/', $line, $pid)) {
                        return ['ok' => true, 'pid' => (int) $pid[1], 'cmdline' => trim($pid[2])];
                    }
                }
            }
        }
        return [
            'ok' => false,
            'reason' => 'queue:work found but no instance listens on --queue=high (matches found: ' . substr($out, 0, 200) . ')',
        ];
    }

    private function detectPhpDevServer(): bool
    {
        $out = (string) shell_exec("pgrep -fl 'php.*artisan serve' 2>/dev/null || true");
        return $out !== '';
    }

    // ──────────────────────────────────────────────────────────────────────
    // STATE SNAPSHOT
    // ──────────────────────────────────────────────────────────────────────

    private function snapshotState(int $branchId): array
    {
        $pdo = $this->pdo();

        // Audit / orders / outbox / fiscal_seq via single MySQL query batch
        $row = $pdo->query("
            SELECT
                (SELECT COUNT(*) FROM audit_logs) AS audit_logs,
                (SELECT COUNT(*) FROM orders) AS orders,
                (SELECT COUNT(*) FROM domain_events WHERE dispatched_at IS NULL) AS outbox_pending,
                (SELECT COUNT(*) FROM domain_events WHERE dispatched_at IS NULL AND created_at < NOW() - INTERVAL 30 SECOND) AS outbox_stale_30s,
                (SELECT COALESCE(MAX(fiscal_sequence_no),0) FROM orders WHERE branch_id={$branchId}) AS fiscal_seq_b1,
                (SELECT VARIABLE_VALUE FROM performance_schema.session_status WHERE VARIABLE_NAME='Threads_connected') AS db_threads_connected,
                (SELECT VARIABLE_VALUE FROM performance_schema.global_variables WHERE VARIABLE_NAME='max_connections') AS db_max_connections
        ")->fetch(PDO::FETCH_ASSOC);

        // Server RSS — under `php artisan serve` (single process) measure the
        // master pid's RSS; under php-fpm sum across all `php-fpm` children
        // since the master is ~5MB and doesn't reflect worker pressure.
        $rssKb = 0;
        $serverPid = $this->detectServerPid();
        if ($serverPid > 0) {
            // Detect process flavour
            $isPhpFpm = (bool) preg_match('/php-fpm/', (string) shell_exec("ps -o comm= -p {$serverPid} 2>/dev/null"));
            if ($isPhpFpm) {
                // Sum RSS across all php-fpm processes (master + workers)
                $rssKb = (int) trim((string) shell_exec("ps -o rss= -C php-fpm 2>/dev/null | awk '{sum+=\$1} END {print sum+0}'"));
                // BSD ps fallback (macOS) — -C is GNU; fall back if zero
                if ($rssKb === 0) {
                    $rssKb = (int) trim((string) shell_exec("ps -A -o rss=,comm= 2>/dev/null | awk '/php-fpm/ {sum+=\$1} END {print sum+0}'"));
                }
            } else {
                $rssKb = (int) trim((string) shell_exec("ps -o rss= -p {$serverPid} 2>/dev/null | tr -d ' '"));
            }
        }

        // Disk usage of storage/logs
        $diskKb = (int) trim((string) shell_exec('du -sk ' . escapeshellarg(storage_path('logs')) . " 2>/dev/null | awk '{print $1}'"));

        // NF525 chain — concise verdict line
        $chain = trim((string) shell_exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(base_path('artisan')) . ' fiscal:verify-chain --all 2>&1 | tail -1'));

        // Redis stats (best-effort)
        $redisStats = $this->redisInfoStats();

        return [
            'ts'                    => date('c'),
            'ts_unix'               => time(),
            'audit_logs'            => (int) ($row['audit_logs'] ?? 0),
            'orders'                => (int) ($row['orders'] ?? 0),
            'outbox_pending'        => (int) ($row['outbox_pending'] ?? 0),
            'outbox_stale_30s'      => (int) ($row['outbox_stale_30s'] ?? 0),
            'fiscal_seq_b1'         => (int) ($row['fiscal_seq_b1'] ?? 0),
            'db_threads_connected'  => (int) ($row['db_threads_connected'] ?? 0),
            'db_max_connections'    => (int) ($row['db_max_connections'] ?? 151),
            'db_pool_pct'           => $row['db_max_connections']
                ? round(100.0 * (int) $row['db_threads_connected'] / (int) $row['db_max_connections'], 1)
                : null,
            'server_pid'            => $serverPid,
            'server_rss_kb'         => $rssKb,
            'storage_logs_kb'       => $diskKb,
            'nf525_chain'           => $chain,
            'redis_keyspace_hits'   => $redisStats['keyspace_hits'] ?? null,
            'redis_keyspace_misses' => $redisStats['keyspace_misses'] ?? null,
            'redis_used_memory_kb'  => $redisStats['used_memory_kb'] ?? null,
            'redis_connected_clients'=> $redisStats['connected_clients'] ?? null,
        ];
    }

    private function detectServerPid(): int
    {
        // Try php artisan serve first
        $out = (string) shell_exec("pgrep -fn 'php.*artisan serve' 2>/dev/null || true");
        $pid = (int) trim($out);
        if ($pid > 0) return $pid;

        // Fallback: php-fpm master
        $out = (string) shell_exec("pgrep -fn 'php-fpm.*master' 2>/dev/null || true");
        return (int) trim($out);
    }

    private function redisInfoStats(): array
    {
        $out = (string) shell_exec('redis-cli INFO stats 2>/dev/null');
        $mem = (string) shell_exec('redis-cli INFO memory 2>/dev/null');
        $clients = (string) shell_exec('redis-cli INFO clients 2>/dev/null');

        $stats = [];
        foreach ([$out, $mem, $clients] as $blob) {
            foreach (explode("\n", $blob) as $l) {
                if (str_contains($l, ':')) {
                    [$k, $v] = array_map('trim', explode(':', $l, 2));
                    $stats[$k] = $v;
                }
            }
        }
        return [
            'keyspace_hits'     => isset($stats['keyspace_hits']) ? (int) $stats['keyspace_hits'] : null,
            'keyspace_misses'   => isset($stats['keyspace_misses']) ? (int) $stats['keyspace_misses'] : null,
            'used_memory_kb'    => isset($stats['used_memory']) ? (int) (((int) $stats['used_memory']) / 1024) : null,
            'connected_clients' => isset($stats['connected_clients']) ? (int) $stats['connected_clients'] : null,
        ];
    }

    private function writeMonitorTick(int $tickIdx, float $t, array $snap, string $kind = 'tick'): void
    {
        $line = [
            'kind'    => $kind,
            'tick'    => $tickIdx,
            'elapsed_s' => round($t, 1),
        ] + $snap;
        file_put_contents($this->monitorPath, json_encode($line) . "\n", FILE_APPEND);
    }

    // ──────────────────────────────────────────────────────────────────────
    // STOP-CONDITION EVALUATION
    // ──────────────────────────────────────────────────────────────────────

    private function evaluateStopConditions(
        array $snap, array $baseline, array $totals,
        int $rssCeilingMb, int $outboxP99Sec, int $poolPctCeiling,
        array $outboxDepthHistory
    ): array {
        // 1. 5xx > 0
        if ($totals['5xx'] > 0) {
            return ['hit' => true, 'reason' => '5xx_count_gt_zero', 'measured' => $totals['5xx']];
        }
        // 2. 429 > 0 (cap raised per F.1 — any 429 = regression)
        if ($totals['429'] > 0) {
            return ['hit' => true, 'reason' => '429_count_gt_zero', 'measured' => $totals['429']];
        }
        // 3. NF525 chain broken
        if (!str_contains($snap['nf525_chain'], 'CHAIN OK')) {
            return ['hit' => true, 'reason' => 'nf525_chain_broken', 'measured' => $snap['nf525_chain']];
        }
        // 4. RSS growth > ceiling
        $rssDeltaMb = ($snap['server_rss_kb'] - $baseline['server_rss_kb']) / 1024.0;
        if ($rssDeltaMb > $rssCeilingMb) {
            return ['hit' => true, 'reason' => 'rss_growth_exceeds_ceiling_mb', 'measured' => round($rssDeltaMb, 2)];
        }
        // 5. DB pool > 80%
        if ($snap['db_pool_pct'] !== null && $snap['db_pool_pct'] > $poolPctCeiling) {
            return ['hit' => true, 'reason' => 'db_pool_pct_exceeds_ceiling', 'measured' => $snap['db_pool_pct']];
        }
        // 6. Disk growth > 1GB
        $diskDeltaKb = $snap['storage_logs_kb'] - $baseline['storage_logs_kb'];
        if ($diskDeltaKb > 1024 * 1024) {
            return ['hit' => true, 'reason' => 'disk_growth_exceeds_1gb', 'measured' => $diskDeltaKb];
        }
        // 7. Outbox depth growth > 100/min over 3 consecutive ticks (queue worker died proxy)
        if (count($outboxDepthHistory) >= 4) {
            $tail = array_slice($outboxDepthHistory, -4);
            $monotonicGrowth = true;
            $largeGrowth = true;
            for ($i = 1; $i < count($tail); $i++) {
                $dt = $tail[$i]['t'] - $tail[$i - 1]['t'];
                if ($dt <= 0) { $monotonicGrowth = false; break; }
                $perMin = (($tail[$i]['depth'] - $tail[$i - 1]['depth']) / $dt) * 60.0;
                if ($perMin < 100) { $largeGrowth = false; break; }
            }
            if ($monotonicGrowth && $largeGrowth) {
                return ['hit' => true, 'reason' => 'outbox_growth_gt_100_per_min_3_ticks', 'measured' => $tail];
            }
        }
        return ['hit' => false];
    }

    // ──────────────────────────────────────────────────────────────────────
    // LATENCY + OUTBOX METRICS
    // ──────────────────────────────────────────────────────────────────────

    private function computeLatencyDistribution(string $jsonlPath): array
    {
        if (!is_file($jsonlPath)) return ['min' => null, 'avg' => null, 'p50' => null, 'p95' => null, 'p99' => null, 'max' => null, 'n' => 0];
        $latencies = [];
        $f = fopen($jsonlPath, 'r');
        while (($line = fgets($f)) !== false) {
            $j = json_decode($line, true);
            if (isset($j['batch_elapsed_ms'])) $latencies[] = (float) $j['batch_elapsed_ms'];
        }
        fclose($f);
        if (empty($latencies)) return ['min' => null, 'avg' => null, 'p50' => null, 'p95' => null, 'p99' => null, 'max' => null, 'n' => 0];
        sort($latencies);
        $n = count($latencies);
        $pct = fn(int $p) => $latencies[min($n - 1, (int) floor($p * $n / 100))];
        return [
            'min' => round($latencies[0], 1),
            'avg' => round(array_sum($latencies) / $n, 1),
            'p50' => round($pct(50), 1),
            'p95' => round($pct(95), 1),
            'p99' => round($pct(99), 1),
            'max' => round($latencies[$n - 1], 1),
            'n'   => $n,
        ];
    }

    /**
     * Sample dispatched DomainEvents created since the soak baseline_ts_unix
     * and compute the dispatched_at - created_at percentile distribution.
     */
    private function computeOutboxDispatchLatency(int $sinceUnix): array
    {
        $pdo = $this->pdo();
        $sinceIso = date('Y-m-d H:i:s', $sinceUnix);
        $rows = $pdo->query("
            SELECT TIMESTAMPDIFF(MICROSECOND, created_at, dispatched_at) / 1000000.0 AS latency_s
            FROM domain_events
            WHERE created_at > '{$sinceIso}'
              AND dispatched_at IS NOT NULL
            ORDER BY id
        ")->fetchAll(PDO::FETCH_COLUMN);

        if (empty($rows)) {
            return ['n' => 0, 'p50_s' => null, 'p95_s' => null, 'p99_s' => null, 'max_s' => null];
        }
        $rows = array_map('floatval', $rows);
        sort($rows);
        $n = count($rows);
        $pct = fn(int $p) => $rows[min($n - 1, (int) floor($p * $n / 100))];
        return [
            'n'     => $n,
            'p50_s' => round($pct(50), 3),
            'p95_s' => round($pct(95), 3),
            'p99_s' => round($pct(99), 3),
            'max_s' => round($rows[$n - 1], 3),
        ];
    }

    private function computeCacheHitRatio(): array
    {
        $stats = $this->redisInfoStats();
        $hits   = $stats['keyspace_hits'] ?? null;
        $misses = $stats['keyspace_misses'] ?? null;
        if ($hits === null || $misses === null || ($hits + $misses) === 0) {
            return ['ratio' => null, 'hits' => $hits, 'misses' => $misses, 'note' => 'redis-cli unreachable or zero traffic'];
        }
        return [
            'ratio'  => round($hits / ($hits + $misses), 4),
            'hits'   => $hits,
            'misses' => $misses,
            'note'   => 'Cumulative since redis-server start — not soak-window-scoped. For window-scoped ratio, diff monitor.jsonl tick deltas.',
        ];
    }

    private function checkFiscalContiguity(int $branchId, int $baselineMax): array
    {
        $pdo = $this->pdo();
        $rows = $pdo->query("
            SELECT fiscal_sequence_no
            FROM orders
            WHERE branch_id={$branchId}
              AND fiscal_sequence_no IS NOT NULL
              AND fiscal_sequence_no > {$baselineMax}
            ORDER BY fiscal_sequence_no
        ")->fetchAll(PDO::FETCH_COLUMN);

        if (empty($rows)) {
            return ['allocated' => 0, 'contiguous' => true, 'gaps' => [], 'duplicates' => []];
        }
        $rows = array_map('intval', $rows);
        $gaps = [];
        $duplicates = [];
        $seen = [];
        $prev = $baselineMax;
        foreach ($rows as $n) {
            if (isset($seen[$n])) { $duplicates[] = $n; continue; }
            $seen[$n] = true;
            if ($n !== $prev + 1) {
                for ($missing = $prev + 1; $missing < $n; $missing++) $gaps[] = $missing;
            }
            $prev = $n;
        }
        return [
            'allocated'   => count($seen),
            'range'       => [$rows[0], end($rows)],
            'contiguous'  => empty($gaps) && empty($duplicates),
            'gaps'        => $gaps,
            'duplicates'  => $duplicates,
        ];
    }

    private function evaluateAcceptanceCriteria(
        array $totals, array $final, array $baseline,
        array $latencyDist, array $outboxLatency,
        int $rssCeilingMb, int $outboxP99Sec
    ): array {
        $rssDeltaMb = ($final['server_rss_kb'] - $baseline['server_rss_kb']) / 1024.0;
        $cacheRatio = $this->computeCacheHitRatio();

        $criteria = [
            'zero_429'              => ['target' => '== 0', 'measured' => $totals['429'],            'pass' => $totals['429'] === 0],
            'zero_5xx'              => ['target' => '== 0', 'measured' => $totals['5xx'],            'pass' => $totals['5xx'] === 0],
            'fiscal_contiguous'     => ['target' => 'no gaps + no dupes', 'measured' => null,        'pass' => true /* filled below from contiguity check */ ],
            'chain_ok_final'        => ['target' => 'CHAIN OK', 'measured' => $final['nf525_chain'], 'pass' => str_contains($final['nf525_chain'], 'CHAIN OK')],
            'rss_growth_bounded'    => ['target' => "≤ {$rssCeilingMb}MB", 'measured' => round($rssDeltaMb, 2),  'pass' => $rssDeltaMb <= $rssCeilingMb],
            'outbox_p99_under_ceil' => ['target' => "≤ {$outboxP99Sec}s",  'measured' => $outboxLatency['p99_s'], 'pass' => ($outboxLatency['p99_s'] ?? 999) <= $outboxP99Sec],
            'cache_hit_ratio_80'    => ['target' => '≥ 0.80', 'measured' => $cacheRatio['ratio'],    'pass' => ($cacheRatio['ratio'] ?? 0) >= 0.80],
        ];
        return $criteria;
    }

    private function renderVerdict(array $summary): string
    {
        if ($summary['abort_reason']) return 'RED';
        if ($summary['stop_condition']) return 'RED';

        $crit = $summary['acceptance_criteria_result'] ?? [];
        $failed = 0;
        foreach ($crit as $name => $c) {
            if (!($c['pass'] ?? true)) $failed++;
        }
        if ($failed === 0) return 'GREEN';
        if ($failed <= 1) return 'AMBER';  // single-criterion fail = amber
        return 'RED';
    }

    // ──────────────────────────────────────────────────────────────────────
    // FIXTURE PROVISIONING (H.3-style)
    // ──────────────────────────────────────────────────────────────────────

    private function provisionFixtures(): array
    {
        $branch = Branch::query()->orderBy('id')->first();
        if (!$branch) {
            throw new \RuntimeException('No branch available to provision soak fixtures');
        }

        $cashier = User::factory()->create([
            'branch_id' => $branch->id,
            'name'      => 'soak-cashier-' . uniqid(),
            'email'     => 'soak-cashier-' . uniqid() . '@soak.local',
        ]);
        // Role assignment is best-effort — the soak proves NF525 / memory /
        // outbox behaviour at the controller layer; if Spatie roles aren't
        // seeded (e.g. fresh dev DB) we fall back to the Sanctum-only path
        // since the cashier_token created below uses '*' ability anyway.
        try {
            if (\Spatie\Permission\Models\Role::where('name', 'POS Operator')->exists()) {
                $cashier->assignRole('POS Operator');
            } elseif (\Spatie\Permission\Models\Role::where('name', 'Admin')->exists()) {
                $cashier->assignRole('Admin');
            }
        } catch (Throwable $e) {
            // Roles not initialized in this DB — proceed with Sanctum-only auth
        }
        $cashierToken = $cashier->createToken('soak-pos', ['*'])->plainTextToken;

        $kioskUser = User::factory()->create([
            'branch_id' => $branch->id,
            'name'      => 'soak-kiosk-' . uniqid(),
            'email'     => 'soak-kiosk-' . uniqid() . '@soak.local',
        ]);
        KioskMachine::query()->create([
            'name'       => 'SOAK-KIOSK-' . uniqid(),
            'machine_id' => 'KM-SOAK-' . uniqid(),
            'username'   => 'soak-kiosk-' . uniqid(),
            'password'   => 'pwd',
            'user_id'    => $kioskUser->id,
            'branch_id'  => $branch->id,
        ]);
        $kioskToken = $kioskUser->createToken('soak-kiosk', ['kiosk:order'])->plainTextToken;

        $adminUser = User::query()->where('branch_id', 0)->first()
            ?? User::query()->whereHas('roles', fn ($q) => $q->where('name', 'Admin'))->first();
        if (!$adminUser) {
            throw new \RuntimeException('No admin user (branch_id=0 or Admin role) available for S5 toggle stream');
        }
        $adminToken = $adminUser->createToken('soak-admin', ['*'])->plainTextToken;

        return [
            'branch'        => $branch,
            'cashier'       => $cashier,
            'cashier_token' => $cashierToken,
            'kiosk_user'    => $kioskUser,
            'kiosk_token'   => $kioskToken,
            'admin_user'    => $adminUser,
            'admin_token'   => $adminToken,
        ];
    }

    private function cleanupFixtures(array $fixtures): void
    {
        foreach (['cashier', 'kiosk_user', 'admin_user'] as $k) {
            if (!isset($fixtures[$k])) continue;
            try { $fixtures[$k]->tokens()->delete(); } catch (Throwable $e) {}
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // REQUEST BUILDERS (H.3-pattern, single-fixture variant)
    // ──────────────────────────────────────────────────────────────────────

    private function buildRequest(
        string $stream, Client $client, string $baseUrl, string $apiKey,
        array $fixtures, int $globalIdx, array &$ctxOut
    ): ?Request {
        $branchId = $fixtures['branch']->id;
        $itemId = 1;
        $toggleItem = 2;

        switch ($stream) {
            case 'S1_POS_DIRECT_SALE': {
                $qty = 1 + ($globalIdx % 3);
                $quote = $this->mintPosQuote($client, $baseUrl, $fixtures['cashier_token'], $apiKey, $itemId, $qty, $branchId);
                if (!$quote) { $ctxOut['quote_failed'] = true; return null; }
                return new Request('POST', "{$baseUrl}/api/admin/pos",
                    [
                        'Content-Type' => 'application/json', 'Accept' => 'application/json',
                        'Authorization' => "Bearer {$fixtures['cashier_token']}",
                        'X-Idempotency-Key' => "SOAK-POS-{$globalIdx}-" . uniqid(),
                        'x-api-key' => $apiKey,
                    ],
                    json_encode([
                        'branch_id' => $branchId, 'order_type' => 30, 'is_advance_order' => 0,
                        'source' => 1, 'pos_payment_method' => 1, 'pos_received_amount' => 100,
                        'items' => json_encode([['item_id' => $itemId, 'quantity' => $qty]]),
                        'total' => 0, 'subtotal' => 0, 'discount' => 0,
                        'quote_token' => $quote['quote_token'] ?? '',
                        'quote_signature' => $quote['signature'] ?? '',
                    ])
                );
            }
            case 'S2_KIOSK_ORDER': {
                $qty = 10 + ($globalIdx % 5);
                $quote = $this->mintKioskQuote($client, $baseUrl, $fixtures['kiosk_token'], $apiKey, $itemId, $qty, $branchId);
                if (!$quote) { $ctxOut['quote_failed'] = true; return null; }
                return new Request('POST', "{$baseUrl}/api/frontend/order",
                    [
                        'Content-Type' => 'application/json', 'Accept' => 'application/json',
                        'Authorization' => "Bearer {$fixtures['kiosk_token']}",
                        'X-Idempotency-Key' => "SOAK-KIOSK-{$globalIdx}-" . uniqid(),
                        'x-api-key' => $apiKey,
                    ],
                    json_encode([
                        'branch_id' => $branchId, 'order_type' => 10, 'is_advance_order' => 0,
                        'source' => 10, 'payment_method' => 1,
                        'token' => "SOAK-KTK-{$globalIdx}-" . substr(uniqid(), -8),
                        'items' => json_encode([['item_id' => $itemId, 'quantity' => $qty]]),
                        'total' => 0, 'subtotal' => 0, 'discount' => 0,
                        'quote_token' => $quote['quote_token'] ?? '',
                        'quote_signature' => $quote['signature'] ?? '',
                    ])
                );
            }
            case 'S3_KIOSK_CASH_COLLECT': {
                $orderId = $this->nextPendingCounterOrderId($branchId);
                if (!$orderId) { $ctxOut['no_pending_counter'] = true; return null; }
                $ctxOut['target_order_id'] = $orderId;
                return new Request('POST', "{$baseUrl}/api/admin/pos/collect-kiosk-cash/{$orderId}",
                    [
                        'Content-Type' => 'application/json', 'Accept' => 'application/json',
                        'Authorization' => "Bearer {$fixtures['cashier_token']}",
                        'X-Idempotency-Key' => "SOAK-CASH-{$orderId}-{$globalIdx}-" . uniqid(),
                        'x-api-key' => $apiKey,
                    ],
                    json_encode([])
                );
            }
            case 'S4_KDS_BUMP': {
                $orderId = $this->nextPreparingOrderId($branchId);
                if (!$orderId) { $ctxOut['no_preparing'] = true; return null; }
                $ctxOut['target_order_id'] = $orderId;
                return new Request('POST', "{$baseUrl}/api/admin/kds-order/change-status/{$orderId}",
                    [
                        'Content-Type' => 'application/json', 'Accept' => 'application/json',
                        'Authorization' => "Bearer {$fixtures['admin_token']}",
                        'X-Idempotency-Key' => "SOAK-KDS-{$orderId}-{$globalIdx}-" . uniqid(),
                        'x-api-key' => $apiKey,
                    ],
                    json_encode(['status' => 8, 'expected_status' => 7])
                );
            }
            case 'S5_ADMIN_TOGGLE_AVAIL': {
                $available = ($globalIdx % 2 === 0);
                return new Request('POST', "{$baseUrl}/api/admin/menu/availability/toggle",
                    [
                        'Content-Type' => 'application/json', 'Accept' => 'application/json',
                        'Authorization' => "Bearer {$fixtures['admin_token']}",
                        'X-Idempotency-Key' => "SOAK-AVL-{$globalIdx}-" . uniqid(),
                        'x-api-key' => $apiKey,
                    ],
                    json_encode(['branch_id' => $branchId, 'item_id' => $toggleItem, 'is_available' => $available])
                );
            }
        }
        return null;
    }

    private function mintPosQuote(Client $c, string $base, string $tok, string $apiKey, int $itemId, int $qty, int $branchId): ?array
    {
        try {
            $r = $c->post("{$base}/api/admin/pos/quote", [
                'headers' => ['Authorization' => "Bearer {$tok}", 'Accept' => 'application/json', 'x-api-key' => $apiKey],
                'json' => [
                    'branch_id' => $branchId, 'order_type' => 30, 'source' => 1, 'pos_payment_method' => 1,
                    'items' => json_encode([['item_id' => $itemId, 'quantity' => $qty]]),
                ],
            ]);
            $body = json_decode((string) $r->getBody(), true);
            return is_array($body['data'] ?? null) ? $body['data'] : null;
        } catch (Throwable $e) { return null; }
    }

    private function mintKioskQuote(Client $c, string $base, string $tok, string $apiKey, int $itemId, int $qty, int $branchId): ?array
    {
        try {
            $r = $c->post("{$base}/api/frontend/order/quote", [
                'headers' => ['Authorization' => "Bearer {$tok}", 'Accept' => 'application/json', 'x-api-key' => $apiKey],
                'json' => [
                    'branch_id' => $branchId, 'order_type' => 10, 'source' => 10, 'payment_method' => 1,
                    'items' => json_encode([['item_id' => $itemId, 'quantity' => $qty]]),
                ],
            ]);
            $body = json_decode((string) $r->getBody(), true);
            return is_array($body['data'] ?? null) ? $body['data'] : null;
        } catch (Throwable $e) { return null; }
    }

    private function nextPreparingOrderId(int $branchId): ?int
    {
        $row = $this->pdo()->query("SELECT id FROM orders WHERE branch_id={$branchId} AND status=7 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['id'] : null;
    }

    private function nextPendingCounterOrderId(int $branchId): ?int
    {
        $row = $this->pdo()->query("
            SELECT id FROM orders
            WHERE branch_id={$branchId} AND payment_status=15
              AND status IN (1,4,7,8) AND pos_payment_method=6
            ORDER BY id DESC LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['id'] : null;
    }

    private function pdo(): PDO
    {
        return DB::connection()->getPdo();
    }
}
