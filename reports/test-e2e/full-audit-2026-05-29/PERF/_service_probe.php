<?php
/**
 * PERF service-level probe — counts queries by calling services/controller
 * actions DIRECTLY (no HTTP kernel = no Collision crash).
 *
 * For each "endpoint" we route to the actual code path the controller
 * runs, with QueryLog enabled. Captures: query count, total time, top
 * N+1 fingerprints.
 *
 * Usage: php artisan tinker --execute="require __DIR__.'/reports/test-e2e/full-audit-2026-05-29/PERF/_service_probe.php';"
 */

use App\Models\KioskMachine;
use App\Models\User;
use App\Services\Kiosk\KioskMenuService;
use App\Services\KdsSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

function detectNPlus1(array $queries): array
{
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
    $error = null;
    try {
        $fn();
    } catch (\Throwable $e) {
        $error = get_class($e) . ': ' . substr($e->getMessage(), 0, 200);
    }
    $elapsed = (microtime(true) - $t0) * 1000;
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    return [
        'label' => $label,
        'error' => $error,
        'elapsed_ms' => round($elapsed, 2),
        'query_count' => count($queries),
        'queries_db_time_ms' => round(array_sum(array_column($queries, 'time')), 2),
        'n_plus_1' => detectNPlus1($queries),
    ];
}

$admin = User::find(1);
$kiosk = KioskMachine::first();
$kioskUser = User::find($kiosk->user_id);

$RESULTS = [];

// === KIOSK MENU SERVICE ===
$branch = \App\Models\Branch::find($kiosk->branch_id);
$RESULTS['frontend_menu'] = probe('KioskMenuService->build($branch)', function () use ($branch) {
    /** @var KioskMenuService $svc */
    $svc = app(KioskMenuService::class);
    $svc->build($branch);
});
// 2nd call — warm cache (if any)
$RESULTS['frontend_menu_warm'] = probe('KioskMenuService->build($branch) WARM', function () use ($branch) {
    $svc = app(KioskMenuService::class);
    $svc->build($branch);
});

// === KDS SYNC SERVICE ===
$RESULTS['kds_sync'] = probe('KdsSyncService->sync(branch=1, since=-15min)', function () {
    $svc = app(KdsSyncService::class);
    $svc->sync(1, new DateTimeImmutable('-15 minutes'), true);
});

// === KDS INDEX (controller calls Order model directly) ===
$RESULTS['kds_index'] = probe('Order::query for KDS index', function () {
    // Mirror KitchenDisplaySystemController::index — usually:
    //   Order::with([...])->whereIn('status', [...])->whereDate(...)
    $orders = \App\Models\Order::with(['orderItems', 'customer', 'branch'])
        ->whereIn('status', ['ACCEPTED', 'PREPARING', 'PREPARED', 'OUT_FOR_DELIVERY'])
        ->where('branch_id', 1)
        ->whereDate('created_at', today())
        ->limit(100)
        ->get();
    $orders->each(fn($o) => $o->orderItems->each(fn($i) => $i->item));
});

// === OSS INDEX ===
$RESULTS['oss_index'] = probe('OSS index — Order::with(items)', function () {
    $orders = \App\Models\Order::with(['orderItems', 'branch'])
        ->whereIn('status', ['PREPARING', 'PREPARED'])
        ->where('branch_id', 1)
        ->whereDate('created_at', today())
        ->limit(50)
        ->get();
});

// === DASHBOARD total-sales ===
$RESULTS['dashboard_total_sales'] = probe('Dashboard total-sales aggregate', function () {
    \App\Models\Order::where('status', '!=', 'CANCELED')
        ->whereDate('created_at', today())
        ->sum('total');
});

// === DASHBOARD realtime-report ===
$RESULTS['dashboard_realtime'] = probe('Dashboard realtime', function () {
    // Realtime mirror: order count + sum + avg, last 60min
    $orders = \App\Models\Order::where('created_at', '>=', now()->subMinutes(60))->get();
    foreach ($orders as $o) {
        $o->orderItems()->count(); // intentional N+1 sniff if controller does
    }
});

// === ITEM INDEX (catalog) ===
$RESULTS['item_index'] = probe('Item index with relations', function () {
    \App\Models\Item::with(['itemCategory', 'tax', 'addons', 'variations', 'extras', 'attributes'])
        ->where('status', 1)
        ->limit(100)
        ->get();
});

// === POS-ORDER INDEX ===
$RESULTS['pos_order_index'] = probe('POS orders index — last 30 days', function () {
    \App\Models\Order::with(['orderItems', 'customer', 'branch', 'orderPayments'])
        ->where('order_type', 'POS')
        ->where('created_at', '>=', now()->subDays(30))
        ->limit(50)
        ->get();
});

// === CASH OVERVIEW (Transaction joined via order_id → orders.branch_id) ===
$RESULTS['cash_overview'] = probe('Cash overview — Transaction join orders', function () {
    \App\Models\Transaction::with(['order:id,branch_id,order_type,payment_method'])
        ->whereDate('created_at', today())
        ->whereHas('order', fn($q) => $q->where('branch_id', 1))
        ->limit(200)
        ->get();
});

// === OBSERVABILITY OUTBOX ===
$RESULTS['observability_outbox'] = probe('Outbox overview aggregate', function () {
    if (\Illuminate\Support\Facades\Schema::hasTable('outbox_events')) {
        DB::table('outbox_events')
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->get();
    }
    if (\Illuminate\Support\Facades\Schema::hasTable('webhook_events')) {
        DB::table('webhook_events')
            ->selectRaw('provider, status, COUNT(*) as c')
            ->groupBy('provider', 'status')
            ->get();
    }
});

// === Output ===
echo "\n=== SERVICE-LEVEL PROBE (query counts + db time) ===\n";
foreach ($RESULTS as $key => $r) {
    $err = $r['error'] ? " ERROR: {$r['error']}" : '';
    echo sprintf(
        "%-30s queries=%-3d elapsed=%-7sms db_time=%-7sms n1=%d%s\n",
        $key,
        $r['query_count'],
        $r['elapsed_ms'],
        $r['queries_db_time_ms'],
        count($r['n_plus_1']),
        $err
    );
    foreach ($r['n_plus_1'] as $s) {
        echo sprintf("   N+1? x%d : %s\n", $s['count'], substr($s['shape'], 0, 140));
    }
}

$out = __DIR__ . '/_service_raw.json';
file_put_contents($out, json_encode($RESULTS, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "\nJSON: $out\n";
