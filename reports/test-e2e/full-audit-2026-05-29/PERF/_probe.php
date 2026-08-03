<?php
/**
 * PERF audit probe — runs each hot path inside Laravel's HTTP container
 * with QueryLog enabled so we capture EXACT query count + timing.
 *
 * Usage:  php artisan tinker --execute="require __DIR__.'/reports/test-e2e/full-audit-2026-05-29/PERF/_probe.php';"
 */

use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

$RESULTS = [];

function fmtMs(float $sec): float { return round($sec * 1000, 2); }

function detectNPlus1(array $queries): array
{
    // Group queries by "fingerprint" — same SQL shape (placeholders normalized).
    $shapes = [];
    foreach ($queries as $q) {
        $sig = preg_replace('/\?|\d+/', '?', $q['query']);
        $sig = preg_replace('/\s+/', ' ', $sig);
        $sig = substr($sig, 0, 200);
        $shapes[$sig] = ($shapes[$sig] ?? 0) + 1;
    }
    arsort($shapes);
    $top = [];
    foreach ($shapes as $sig => $count) {
        if ($count >= 5) {
            $top[] = ['count' => $count, 'shape' => $sig];
        }
    }
    return $top;
}

function probe(string $label, callable $fn): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $t0 = microtime(true);
    $status = null;
    $error = null;
    try {
        $status = $fn();
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
    $elapsed = microtime(true) - $t0;
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $n1 = detectNPlus1($queries);
    return [
        'label' => $label,
        'status' => $status,
        'error' => $error,
        'elapsed_ms' => fmtMs($elapsed),
        'query_count' => count($queries),
        'n_plus_1_suspects' => $n1,
        'queries_total_ms' => fmtMs(array_sum(array_column($queries, 'time')) / 1000),
    ];
}

// --- Mint Sanctum tokens ---
$admin = User::find(1);
$kiosk = KioskMachine::first();
$kioskUser = User::find($kiosk->user_id);

// admin token (no abilities = wildcard)
$adminToken = $admin->createToken('perf-audit-admin', ['*'])->plainTextToken;
// kiosk token (minted on the linked User, matches KioskMachineLoginController:98)
$kioskToken = $kioskUser->createToken('perf-audit-kiosk', ['kiosk:order'])->plainTextToken;

echo "Tokens minted (admin id={$admin->id}, kiosk_user id={$kioskUser->id}).\n";

$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

function callJson(string $method, string $uri, ?string $token, array $payload = []): array {
    global $kernel;
    $request = \Illuminate\Http\Request::create(
        '/api' . $uri,
        $method,
        $payload,
        [],
        [],
        [
            'HTTP_ACCEPT'        => 'application/json',
            'HTTP_AUTHORIZATION' => $token ? 'Bearer ' . $token : '',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ],
        $method === 'POST' ? json_encode($payload) : null
    );
    if ($method === 'POST') {
        $request->headers->set('Content-Type', 'application/json');
    }
    $response = $kernel->handle($request);
    return [$response->getStatusCode(), $response->getContent()];
}

// === Endpoint probes ===

$RESULTS['frontend_menu'] = probe('GET /api/frontend/menu (kiosk)', function () use ($kioskToken) {
    [$s, $body] = callJson('GET', '/frontend/menu', $kioskToken);
    return $s;
});

$RESULTS['kds_order_sync'] = probe('GET /api/admin/kds-order/sync', function () use ($adminToken) {
    [$s,] = callJson('GET', '/admin/kds-order/sync', $adminToken);
    return $s;
});

$RESULTS['kds_order_index'] = probe('GET /api/admin/kds-order (index)', function () use ($adminToken) {
    [$s,] = callJson('GET', '/admin/kds-order', $adminToken);
    return $s;
});

$RESULTS['oss_order_index'] = probe('GET /api/admin/oss-order', function () use ($adminToken) {
    [$s,] = callJson('GET', '/admin/oss-order', $adminToken);
    return $s;
});

$RESULTS['dashboard_total_sales'] = probe('GET /api/admin/dashboard/total-sales', function () use ($adminToken) {
    [$s,] = callJson('GET', '/admin/dashboard/total-sales', $adminToken);
    return $s;
});

$RESULTS['dashboard_realtime'] = probe('GET /api/admin/dashboard/realtime-report', function () use ($adminToken) {
    [$s,] = callJson('GET', '/admin/dashboard/realtime-report', $adminToken);
    return $s;
});

$RESULTS['item_index'] = probe('GET /api/admin/item', function () use ($adminToken) {
    [$s,] = callJson('GET', '/admin/item', $adminToken);
    return $s;
});

$RESULTS['pos_order_index'] = probe('GET /api/admin/pos-order', function () use ($adminToken) {
    [$s,] = callJson('GET', '/admin/pos-order', $adminToken);
    return $s;
});

$RESULTS['cash_overview'] = probe('GET /api/admin/cash-overview', function () use ($adminToken) {
    [$s,] = callJson('GET', '/admin/cash-overview', $adminToken);
    return $s;
});

$RESULTS['observability_outbox'] = probe('GET /api/admin/observability/outbox', function () use ($adminToken) {
    [$s,] = callJson('GET', '/admin/observability/outbox', $adminToken);
    return $s;
});

// Cleanup tokens
PersonalAccessToken::where('name', 'perf-audit-admin')->delete();
PersonalAccessToken::where('name', 'perf-audit-kiosk')->delete();

// Pretty print
echo "\n=== ENDPOINT PROBE RESULTS ===\n";
foreach ($RESULTS as $key => $r) {
    echo sprintf(
        "%-35s status=%-3s queries=%-3d %sms total_ms=%sms n1=%d %s\n",
        $key,
        $r['status'] ?? 'ERR',
        $r['query_count'],
        $r['elapsed_ms'],
        $r['queries_total_ms'],
        count($r['n_plus_1_suspects']),
        $r['error'] ? "ERROR: " . substr($r['error'], 0, 120) : ''
    );
    foreach ($r['n_plus_1_suspects'] as $s) {
        echo sprintf("   N+1? x%d : %s\n", $s['count'], substr($s['shape'], 0, 150));
    }
}

// Write JSON output
$out = __DIR__ . '/_raw.json';
file_put_contents($out, json_encode($RESULTS, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "\nJSON written: $out\n";
