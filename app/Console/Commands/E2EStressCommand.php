<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * [AUDIT F-017 Suite 7] Stress / Load test command — rush midi simulation.
 *
 * Sister command to `MonitorOutboxStaleness`. Where the staleness monitor
 * RAISES alerts on production, this command DRIVES synthetic load against
 * a running dev/staging server to prove the system survives concurrent
 * POS + Kiosk submissions without:
 *   - duplicate fiscal_sequence_no per branch (NF525 invariant);
 *   - duplicate queue_number per (branch, business_date);
 *   - cross-branch leak (BranchScope invariant);
 *   - lost outbox events (DispatchDomainEventsJob pipeline).
 *
 * ──────────────────────────────────────────────────────────────────────
 * EXECUTION MODE
 *
 *   This command is OWNER-DRIVEN — not part of CI. The PHPUnit volet
 *   (`tests/load/RushMidiSimulationTest.php`) covers the structural
 *   invariants in CI under sqlite-memory. THIS command exercises the
 *   true HTTP concurrency path against a running dev server so the
 *   Cache::lock + DB UNIQUE behaviour surfaces under realistic load.
 *
 *   Why not in CI: requires a running dev server + MySQL + Redis cache.
 *   Spinning that up per-run would explode CI cost. Owner runs nightly
 *   or pre-merge.
 *
 * ──────────────────────────────────────────────────────────────────────
 * USAGE
 *
 *   # 50 POS orders, single branch, 10 concurrent
 *   php artisan foodking:e2e:stress --orders=50 --branches=1 --concurrency=10 --type=pos
 *
 *   # 100 mixed (POS + Kiosk) on 1 branch, 20 concurrent, output report
 *   php artisan foodking:e2e:stress --orders=100 --branches=1 --concurrency=20 \
 *     --type=mixed --output=storage/logs/stress-mixed-100.md
 *
 *   # 200 orders across 10 branches, 25 concurrent
 *   php artisan foodking:e2e:stress --orders=200 --branches=10 --concurrency=25 --type=pos
 *
 * ──────────────────────────────────────────────────────────────────────
 * SAFETY
 *
 *   - Refuses to run when APP_ENV=production (no production stress).
 *   - Uses Sanctum personal access tokens — created and revoked within
 *     the run. No long-lived credentials persisted.
 *   - All assertions are POST-RUN reads of the DB — no service mutation
 *     beyond the orders the command itself submits.
 *
 * Pattern source: registered via `$this->load(__DIR__.'/Commands')` in
 * `app/Console/Kernel.php::commands()` — auto-discovery, NO Kernel.php
 * modification required.
 */
class E2EStressCommand extends Command
{
    protected $signature = 'foodking:e2e:stress
                            {--orders=50 : Total number of orders to submit (split across branches)}
                            {--branches=1 : Number of branches to spread orders across}
                            {--concurrency=10 : Max concurrent in-flight HTTP requests}
                            {--type=pos : pos | kiosk | mixed}
                            {--base-url= : Override API base URL (defaults to config app.url)}
                            {--output= : Optional path to write Markdown report (default: stdout only)}';

    protected $description = 'Drive synthetic POS/Kiosk load against a dev server to prove fiscal/queue monotonicity + outbox pipeline under stress (F-017 Suite 7 owner-driven volet).';

    public function handle(): int
    {
        // Safety : refuse production env.
        if (app()->environment('production')) {
            $this->error('foodking:e2e:stress refuses to run in APP_ENV=production. Aborting.');
            return self::FAILURE;
        }

        $totalOrders = max(1, (int) $this->option('orders'));
        $branchCount = max(1, (int) $this->option('branches'));
        $concurrency = max(1, (int) $this->option('concurrency'));
        $type = strtolower((string) $this->option('type'));
        $baseUrl = (string) ($this->option('base-url') ?: config('app.url') ?: 'http://localhost:8000');
        $outputPath = $this->option('output');

        if (!in_array($type, ['pos', 'kiosk', 'mixed'], true)) {
            $this->error("Invalid --type={$type}. Must be one of: pos, kiosk, mixed.");
            return self::FAILURE;
        }

        $this->info("[stress] Starting F-017 Suite 7 stress run");
        $this->info("[stress]   orders={$totalOrders} branches={$branchCount} concurrency={$concurrency} type={$type}");
        $this->info("[stress]   base-url={$baseUrl}");

        // Phase 1 : provision branches + tokens (cashiers + kiosk machines).
        $startedAt = microtime(true);
        try {
            $fixtures = $this->provisionFixtures($branchCount, $type);
        } catch (Throwable $e) {
            $this->error("[stress] Fixture provisioning failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        // Phase 2 : build the request batch.
        $requests = $this->buildRequestBatch($fixtures, $totalOrders, $type, $baseUrl);

        $this->info("[stress] Built " . count($requests) . " requests across " . count($fixtures) . " branch fixtures.");

        // Phase 3 : dispatch via Guzzle Pool with bounded concurrency.
        $client = new Client([
            'timeout'         => 30,
            'connect_timeout' => 5,
            'http_errors'     => false,
        ]);

        $results = [];
        $batchStart = microtime(true);

        $verbose = (bool) $this->option('verbose');
        $pool = new Pool($client, $requests, [
            'concurrency' => $concurrency,
            'fulfilled'   => function ($response, $index) use (&$results, &$requests, $verbose) {
                $status = $response->getStatusCode();
                $body = (string) $response->getBody();
                $ok = $status >= 200 && $status < 300;
                // [stress-q13 2026-05-21] Surface non-2xx response bodies
                // when -v supplied so the report can pinpoint validation /
                // 409 / 429 root causes without re-running with curl.
                if (! $ok && $verbose) {
                    $this->line("[stress] #{$index} status={$status} body=" . substr($body, 0, 200));
                }
                $results[$index] = [
                    'index'      => $index,
                    'status'     => $status,
                    'body'       => $body,
                    'latency_ms' => null, // approximated post-batch
                    'ok'         => $ok,
                    'error'      => null,
                ];
            },
            'rejected'    => function ($reason, $index) use (&$results) {
                $msg = $reason instanceof GuzzleException ? $reason->getMessage() : (string) $reason;
                $results[$index] = [
                    'index'      => $index,
                    'status'     => 0,
                    'body'       => null,
                    'latency_ms' => null,
                    'ok'         => false,
                    'error'      => $msg,
                ];
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();

        $batchDuration = microtime(true) - $batchStart;
        $totalDuration = microtime(true) - $startedAt;

        // Phase 4 : compute metrics.
        $metrics = $this->computeMetrics($results, $batchDuration);

        // Phase 5 : DB invariants check.
        $invariants = $this->checkDbInvariants($fixtures);

        // Phase 6 : render report.
        $report = $this->renderMarkdownReport(
            $totalOrders,
            $branchCount,
            $concurrency,
            $type,
            $baseUrl,
            $totalDuration,
            $batchDuration,
            $metrics,
            $invariants
        );

        $this->line($report);

        if ($outputPath) {
            try {
                @mkdir(dirname($outputPath), 0755, true);
                file_put_contents($outputPath, $report);
                $this->info("[stress] Report written to {$outputPath}");
            } catch (Throwable $e) {
                $this->warn("[stress] Failed to write report to {$outputPath}: {$e->getMessage()}");
            }
        }

        // Cleanup: revoke tokens we minted (best-effort).
        $this->cleanupFixtures($fixtures);

        // Exit code: success only if all requests OK + all DB invariants hold.
        $allOk = $metrics['failed'] === 0
            && $invariants['duplicate_fiscal'] === 0
            && $invariants['duplicate_queue'] === 0
            && $invariants['cross_branch_leak'] === 0;

        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Provision N branches + per-branch cashier (POS) and KioskMachine (Kiosk)
     * + Sanctum personal access tokens. Returns a structured fixture array.
     *
     * @return array<int, array{branch: Branch, cashier: User, cashier_token: string, kiosk_user: ?User, kiosk_token: ?string}>
     */
    private function provisionFixtures(int $branchCount, string $type): array
    {
        $fixtures = [];

        for ($b = 1; $b <= $branchCount; $b++) {
            // Reuse existing branch with a low id if available, otherwise create.
            $branch = Branch::query()->orderBy('id')->skip($b - 1)->first()
                ?? Branch::factory()->create();

            $cashier = User::factory()->create([
                'branch_id' => $branch->id,
                'name'      => "stress-cashier-{$b}",
                'email'     => "stress-cashier-{$b}-" . uniqid() . "@stress.local",
            ]);
            $cashier->assignRole('POS Operator');
            $cashierToken = $cashier->createToken('stress-pos', ['*'])->plainTextToken;

            $kioskUser = null;
            $kioskToken = null;
            if (in_array($type, ['kiosk', 'mixed'], true)) {
                $kioskUser = User::factory()->create([
                    'branch_id' => $branch->id,
                    'name'      => "stress-kiosk-{$b}",
                    'email'     => "stress-kiosk-{$b}-" . uniqid() . "@stress.local",
                ]);
                KioskMachine::query()->create([
                    'name'       => "STRESS-KIOSK-B{$b}",
                    'machine_id' => "KM-STRESS-B{$b}-" . uniqid(),
                    'username'   => "stress-kiosk-{$b}-" . uniqid(),
                    'password'   => 'pwd',
                    'user_id'    => $kioskUser->id,
                    'branch_id'  => $branch->id,
                ]);
                $kioskToken = $kioskUser->createToken('stress-kiosk', ['kiosk:order'])->plainTextToken;
            }

            $fixtures[] = [
                'branch'         => $branch,
                'cashier'        => $cashier,
                'cashier_token'  => $cashierToken,
                'kiosk_user'     => $kioskUser,
                'kiosk_token'    => $kioskToken,
            ];
        }

        return $fixtures;
    }

    /**
     * Build the request batch. Distributes orders evenly across fixtures
     * and types per --type setting.
     *
     * @return array<int, Request>
     */
    private function buildRequestBatch(array $fixtures, int $totalOrders, string $type, string $baseUrl): array
    {
        $requests = [];
        $apiKey = (string) (config('app.api_key') ?: env('MIX_API_KEY', ''));

        // [stress-q13 2026-05-21] Kiosk orders require a quote_token +
        // quote_signature pair, minted from POST /api/frontend/order/quote
        // and consumed by POST /api/frontend/order. Each quote is single-use
        // (OrderQuoteService:373 — 409 if already consumed). We pre-fetch
        // quotes synchronously here so the parallel order POST pool below
        // can dispatch without inter-request dependencies.
        $httpClient = new Client([
            'timeout' => 15,
            'connect_timeout' => 5,
            'http_errors' => false,
        ]);

        for ($i = 0; $i < $totalOrders; $i++) {
            $fixture = $fixtures[$i % count($fixtures)];
            $useKiosk = match ($type) {
                'pos'    => false,
                'kiosk'  => true,
                'mixed'  => $i % 2 === 1,
            };

            if ($useKiosk) {
                $token = $fixture['kiosk_token'];
                if (!$token) {
                    // Fall back to POS path if kiosk fixture absent.
                    $useKiosk = false;
                }
            }

            if ($useKiosk) {
                $url = rtrim($baseUrl, '/') . '/api/frontend/order';
                // [stress-q13 2026-05-21] Per-request UNIQUE quantity so
                // each canonical_payload yields a unique intent_hash
                // (OrderQuoteService:74). Without uniqueness, line 80
                // findOpenQuote collapses identical payloads to ONE shared
                // open quote — concurrent consumers then race for the
                // single token and all but one get 409 "already consumed".
                // The pre-fetch loop is sequential so quote i is created
                // BEFORE quote i+1 even queries; intent_hash collision is
                // the only collapse vector. Use $i+1 directly (1..N) so
                // every quote is a real distinct row in order_quotes.
                $quantity = $i + 1;
                $quote = $this->fetchKioskQuote(
                    $httpClient,
                    $baseUrl,
                    $fixture['kiosk_token'],
                    $apiKey,
                    $fixture['branch']->id,
                    $quantity
                );
                // [stress-q13] Pass index + quote so the payload carries
                // the STRESS-Q13-ART- prefix used by iter15:cleanup-test-orders
                // --token-prefix sweep, plus the consume-once quote pair.
                $body = json_encode(
                    $this->minimalKioskOrderPayload($fixture['branch']->id, $i, $quantity)
                    + [
                        'quote_token'     => $quote['quote_token'] ?? '',
                        'quote_signature' => $quote['signature'] ?? '',
                    ]
                );
                $headers = [
                    'Content-Type'      => 'application/json',
                    'Accept'            => 'application/json',
                    'Authorization'     => 'Bearer ' . $fixture['kiosk_token'],
                    // [stress-q13] Idempotency-Key prefix kept aligned with
                    // payload token prefix so duplicate-key audit also matches.
                    'X-Idempotency-Key' => 'STRESS-Q13-ART-' . $i . '-' . uniqid(),
                    'x-api-key'         => $apiKey,
                ];
            } else {
                $url = rtrim($baseUrl, '/') . '/api/admin/pos';
                // [GOAL-ULTRA-AUDIT 2026-06-10] POS lane heal: PosController@store
                // now enforces the NF525 SSOT quote gate (401 "Order quote token
                // and signature are required together") — mirror the kiosk lane:
                // mint a signed quote first, distinct quantity per request so each
                // quote is a real distinct order_quotes row (no intent_hash race).
                $quantity = $i + 1;
                $quote = $this->fetchPosQuote(
                    $httpClient,
                    $baseUrl,
                    $fixture['cashier_token'],
                    $apiKey,
                    $fixture['branch']->id,
                    $quantity
                );
                $body = json_encode(
                    $this->minimalPosOrderPayload($fixture['branch']->id, $quantity)
                    + [
                        'quote_token'     => $quote['quote_token'] ?? '',
                        'quote_signature' => $quote['signature'] ?? '',
                    ]
                );
                $headers = [
                    'Content-Type'      => 'application/json',
                    'Accept'            => 'application/json',
                    'Authorization'     => 'Bearer ' . $fixture['cashier_token'],
                    'X-Idempotency-Key' => 'STRESS-P-' . $i . '-' . uniqid(),
                    'x-api-key'         => $apiKey,
                ];
            }

            $requests[] = new Request('POST', $url, $headers, $body);
        }

        return $requests;
    }

    /**
     * [stress-q13 2026-05-21] Synchronously mint a kiosk order quote so the
     * follow-up POST /api/frontend/order has the required quote_token +
     * quote_signature (OrderRequest:175-180). Returns ['quote_token',
     * 'signature'] on success, empty array on failure (the report will
     * surface as 422 on the order POST, which is the desired honest signal).
     */
    private function fetchKioskQuote(Client $client, string $baseUrl, string $kioskToken, string $apiKey, int $branchId, int $quantity = 1): array
    {
        try {
            $response = $client->post(rtrim($baseUrl, '/') . '/api/frontend/order/quote', [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $kioskToken,
                    'x-api-key'     => $apiKey,
                ],
                'json' => [
                    'branch_id'      => $branchId,
                    'order_type'     => \App\Enums\OrderType::TAKEAWAY,
                    'source'         => 10,
                    'payment_method' => \App\Enums\PaymentGateway::CARD,
                    'items'          => json_encode([
                        ['item_id' => 1, 'quantity' => $quantity],
                    ]),
                ],
            ]);
            if ($response->getStatusCode() !== 200) {
                return [];
            }
            $decoded = json_decode((string) $response->getBody(), true);
            return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Minimal POS order payload — server recomputes pricing from DB so we
     * only need branch_id + an items array. Schema may vary by env: this
     * is a best-effort baseline; if the validator rejects, the run still
     * surfaces the schema gap (visible as 422 in the report).
     */
    /**
     * [GOAL-ULTRA-AUDIT 2026-06-10] POS twin of fetchKioskQuote — mints a
     * signed order quote via POST /api/admin/pos/quote (cashier token) so the
     * POS lane passes the NF525 SSOT quote gate on PosController@store. The
     * quoted items/qty MUST match the order payload (intent hash).
     */
    private function fetchPosQuote(Client $client, string $baseUrl, string $cashierToken, string $apiKey, int $branchId, int $quantity = 1): array
    {
        try {
            $response = $client->post(rtrim($baseUrl, '/') . '/api/admin/pos/quote', [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $cashierToken,
                    'x-api-key'     => $apiKey,
                ],
                'json' => [
                    'branch_id'          => $branchId,
                    'order_type'         => \App\Enums\OrderType::POS,
                    'source'             => \App\Enums\Source::POS,
                    'payment_method'     => \App\Enums\PaymentGateway::CARD,
                    // POS surface canonical reads pos_payment_method (not
                    // payment_method) — must match the order payload (CASH).
                    'pos_payment_method' => \App\Enums\PosPaymentMethod::CASH,
                    'discount'           => 0,
                    'items'              => json_encode([
                        ['item_id' => 1, 'quantity' => $quantity],
                    ]),
                ],
            ]);
            if ($response->getStatusCode() !== 200) {
                return [];
            }
            $decoded = json_decode((string) $response->getBody(), true);
            return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private function minimalPosOrderPayload(int $branchId, int $quantity = 1): array
    {
        // [F.2 SOAK 2026-05-23] Repair the three named-422 fields surfaced by
        // PosController OrderRequest validation (is_advance_order + source +
        // non-empty items). Pattern mirrors minimalKioskOrderPayload above
        // (the working template). source=POS (15), order_type=POS (15),
        // single-line stress order item id=1 qty=1. Repair is scope-minimal
        // (only the named-required fields) — no behavioural change to the
        // command's invariant-check semantics.
        // PosOrderRequest extends OrderRequest and adds pos_payment_method
        // (PosPaymentMethod enum) + conditional fields per method. CASH=1
        // needs pos_received_amount; CARD/MOBILE/OTHER/TR need pos_payment_note.
        // Using CASH minimises the conditional rule surface (lowest validation
        // friction → cleanest soak signal on the fiscal/audit pipeline).
        return [
            'branch_id'           => $branchId,
            'order_type'          => \App\Enums\OrderType::POS,
            'is_advance_order'    => 0,
            'source'              => \App\Enums\Source::POS,
            'payment_method'      => \App\Enums\PaymentGateway::CARD,
            'pos_payment_method'  => \App\Enums\PosPaymentMethod::CASH,
            'pos_received_amount' => 1000,
            'items'               => json_encode([
                ['item_id' => 1, 'quantity' => $quantity],
            ]),
            'total'    => 0,
            'subtotal' => 0,
            'discount' => 0,
        ];
    }

    private function minimalKioskOrderPayload(int $branchId, int $i = 0, int $quantity = 1): array
    {
        // [stress-q13 2026-05-21] Four surgical fixes (prior agent's blocked-doc):
        //   1. items: empty array → ValidJsonOrder rule rejects "must contain at
        //      least one article". Send a minimal {item_id: 1, quantity: $q}.
        //   2. order_type: KIOSK=25 → V1 dine-in guard in OrderRequest:220-231
        //      rejects when pos_dine_in_enabled=false. Use TAKEAWAY=10 (the
        //      precedent set by the prior blocked agent + GAP-22-1 allows it).
        //   3. token: pass a STRESS-Q13-ART- prefixed serial so iter15:cleanup-
        //      test-orders --token-prefix sweep can match the fixtures.
        //   4. UNIQUE quantity per-request (caller passes $i+1) so each
        //      canonical_payload intent_hash is unique — without uniqueness,
        //      OrderQuoteService:80 findOpenQuote collapses concurrent
        //      requests to one shared quote, and only one consume wins
        //      while every other hits 409 "already consumed".
        return [
            'branch_id'        => $branchId,
            'order_type'       => \App\Enums\OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source'           => 10, // kiosk source
            'payment_method'   => \App\Enums\PaymentGateway::CARD,
            'token'            => 'STRESS-Q13-ART-' . $i . '-' . substr(uniqid(), -8),
            'items'            => json_encode([
                ['item_id' => 1, 'quantity' => $quantity],
            ]),
            'total'            => 0,
            'subtotal'         => 0,
            'discount'         => 0,
        ];
    }

    /**
     * Compute basic latency / success metrics from the result set.
     * Latency is measured at batch granularity (Guzzle Pool) — for true
     * per-request latency, instrument inside the fulfilled callback.
     */
    private function computeMetrics(array $results, float $batchDuration): array
    {
        $total = count($results);
        $ok = 0;
        $failed = 0;
        $statusBreakdown = [];

        foreach ($results as $r) {
            if ($r['ok']) {
                $ok++;
            } else {
                $failed++;
            }
            $key = (string) ($r['status'] ?: 'network');
            $statusBreakdown[$key] = ($statusBreakdown[$key] ?? 0) + 1;
        }

        return [
            'total'             => $total,
            'ok'                => $ok,
            'failed'            => $failed,
            'batch_duration_s'  => round($batchDuration, 3),
            'avg_latency_ms'    => $total > 0 ? round(($batchDuration * 1000) / $total, 2) : 0,
            'throughput_rps'    => $batchDuration > 0 ? round($total / $batchDuration, 2) : 0,
            'status_breakdown'  => $statusBreakdown,
        ];
    }

    /**
     * Post-run DB invariant checks. Counts duplicates that should be impossible
     * if Cache::lock + DB UNIQUE constraints held under the load.
     */
    private function checkDbInvariants(array $fixtures): array
    {
        $branchIds = array_map(fn ($f) => $f['branch']->id, $fixtures);

        $duplicateFiscal = (int) DB::table('orders')
            ->whereIn('branch_id', $branchIds)
            ->whereNotNull('fiscal_sequence_no')
            ->select('branch_id', 'fiscal_sequence_no', DB::raw('COUNT(*) as c'))
            ->groupBy('branch_id', 'fiscal_sequence_no')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $duplicateQueue = (int) DB::table('orders')
            ->whereIn('branch_id', $branchIds)
            ->whereNotNull('queue_number')
            ->whereNotNull('business_date')
            ->select('branch_id', 'business_date', 'queue_number', DB::raw('COUNT(*) as c'))
            ->groupBy('branch_id', 'business_date', 'queue_number')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        // Cross-branch leak: any order whose recorded user.branch_id
        // differs from order.branch_id (would indicate the cashier of branch X
        // was able to write an order on branch Y).
        $crossBranchLeak = (int) DB::table('orders')
            ->whereIn('orders.branch_id', $branchIds)
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->where('users.branch_id', '!=', 0)
            ->whereColumn('users.branch_id', '!=', 'orders.branch_id')
            ->count();

        $outboxStale = (int) DB::table('domain_events')
            ->whereIn('branch_id', $branchIds)
            ->whereNull('dispatched_at')
            ->where('created_at', '<', now()->subSeconds(30))
            ->count();

        return [
            'duplicate_fiscal'   => $duplicateFiscal,
            'duplicate_queue'    => $duplicateQueue,
            'cross_branch_leak'  => $crossBranchLeak,
            'outbox_stale_30s'   => $outboxStale,
        ];
    }

    private function renderMarkdownReport(
        int $totalOrders,
        int $branchCount,
        int $concurrency,
        string $type,
        string $baseUrl,
        float $totalDuration,
        float $batchDuration,
        array $metrics,
        array $invariants
    ): string {
        $verdict = $metrics['failed'] === 0
            && $invariants['duplicate_fiscal'] === 0
            && $invariants['duplicate_queue'] === 0
            && $invariants['cross_branch_leak'] === 0
            ? 'PASS' : 'FAIL';

        $statusLines = '';
        foreach ($metrics['status_breakdown'] as $code => $count) {
            $statusLines .= "  - {$code}: {$count}\n";
        }

        $lines = [
            "# F-017 Suite 7 — Stress Run Report",
            "",
            "**Verdict:** {$verdict}",
            "**Generated:** " . now()->toDateTimeString(),
            "**Base URL:** {$baseUrl}",
            "",
            "## Parameters",
            "",
            "- orders: {$totalOrders}",
            "- branches: {$branchCount}",
            "- concurrency: {$concurrency}",
            "- type: {$type}",
            "",
            "## Timing",
            "",
            "- total_duration_s: " . round($totalDuration, 3),
            "- batch_duration_s: " . round($batchDuration, 3),
            "- avg_latency_ms: " . $metrics['avg_latency_ms'],
            "- throughput_rps: " . $metrics['throughput_rps'],
            "",
            "## HTTP Results",
            "",
            "- total: {$metrics['total']}",
            "- ok: {$metrics['ok']}",
            "- failed: {$metrics['failed']}",
            "- status_breakdown:",
            $statusLines,
            "## DB Invariants",
            "",
            "- duplicate_fiscal_sequence_no: {$invariants['duplicate_fiscal']} (must be 0)",
            "- duplicate_queue_number: {$invariants['duplicate_queue']} (must be 0)",
            "- cross_branch_leak: {$invariants['cross_branch_leak']} (must be 0)",
            "- outbox_stale_30s: {$invariants['outbox_stale_30s']} (target: 0)",
            "",
            "## Notes",
            "",
            "- This run is owner-driven; CI structural invariants live in",
            "  `tests/load/RushMidiSimulationTest.php` (PHPUnit @group stress).",
            "- Real Cache::lock contention requires Redis cache + MySQL DB",
            "  (sqlite-memory in CI cannot truly contend).",
            "- A FAIL verdict here means production is at risk: investigate",
            "  before merging.",
            "",
        ];

        return implode("\n", $lines);
    }

    private function cleanupFixtures(array $fixtures): void
    {
        // Best-effort token revocation. Do NOT delete users/branches —
        // they may be referenced by created orders for audit reasons.
        foreach ($fixtures as $f) {
            try {
                $f['cashier']?->tokens()?->delete();
                $f['kiosk_user']?->tokens()?->delete();
            } catch (Throwable $e) {
                // Best-effort cleanup; log + continue.
                $this->warn("[stress] Token cleanup partial: {$e->getMessage()}");
            }
        }
    }
}
