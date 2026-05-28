<?php
// Sync Monitor snapshot — outputs single-line JSON for time-series capture.
// Run via: php artisan tinker --execute="require 'reports/test-e2e/real-live-flow-2026-05-28/agents/snapshot.php';"

use Illuminate\Support\Facades\DB;

$nowUtc = gmdate('c');

// Core counts
$auditCount = DB::table('audit_logs')->count();
$auditLast3 = DB::table('audit_logs')
    ->orderByDesc('id')
    ->limit(3)
    ->get(['id', 'action', 'resource', 'created_at'])
    ->map(fn ($r) => ['id' => $r->id, 'action' => $r->action, 'resource' => $r->resource, 'at' => (string) $r->created_at])
    ->toArray();
$auditLastHash = DB::table('audit_logs')->orderByDesc('id')->value('current_hash');

$domainCount = DB::table('domain_events')->count();
$domainPending = DB::table('domain_events')->whereNull('dispatched_at')->count();
$domainLast3 = DB::table('domain_events')
    ->orderByDesc('id')
    ->limit(3)
    ->get(['id', 'broadcast_as', 'channel', 'occurred_at', 'dispatched_at', 'attempts', 'last_error'])
    ->map(fn ($r) => [
        'id' => $r->id,
        'broadcast_as' => $r->broadcast_as,
        'channel' => $r->channel,
        'occurred_at' => (string) $r->occurred_at,
        'dispatched_at' => $r->dispatched_at ? (string) $r->dispatched_at : null,
        'attempts' => $r->attempts,
        'last_error' => $r->last_error,
    ])
    ->toArray();

$ordersCount = DB::table('orders')->count();
$ordersLast = DB::table('orders')
    ->orderByDesc('id')
    ->limit(1)
    ->get(['id', 'fiscal_sequence_no', 'queue_number', 'status', 'source_surface', 'total', 'payment_method', 'created_at'])
    ->first();
$ordersFiscalAllocErrors = DB::table('orders')->whereNotNull('fiscal_alloc_error_at')->count();
$ordersFiscalAllocLast = DB::table('orders')
    ->whereNotNull('fiscal_alloc_error_at')
    ->orderByDesc('fiscal_alloc_error_at')
    ->limit(1)
    ->get(['id', 'fiscal_alloc_error_at'])
    ->first();

$cashCount = DB::table('cash_movements')->count();
$cashLast = DB::table('cash_movements')
    ->orderByDesc('id')
    ->limit(1)
    ->get(['id', 'type', 'direction', 'amount', 'order_id', 'created_at'])
    ->first();

$zCount = DB::table('z_reports')->count();
$zLast = DB::table('z_reports')
    ->orderByDesc('id')
    ->limit(1)
    ->get(['id', 'sequence_no', 'opened_at', 'closed_at', 'order_count', 'status', 'signature'])
    ->first();

$failedJobs = DB::table('failed_jobs')->count();
$failedLast = DB::table('failed_jobs')
    ->orderByDesc('id')
    ->limit(1)
    ->get(['id', 'queue', 'failed_at'])
    ->first();

// Outbox dispatch latency last 5 minutes
$cutoff = gmdate('Y-m-d H:i:s', time() - 300);
$dispatched = DB::table('domain_events')
    ->whereNotNull('dispatched_at')
    ->where('dispatched_at', '>=', $cutoff)
    ->get(['id', 'occurred_at', 'dispatched_at'])
    ->map(function ($r) {
        $occ = strtotime((string) $r->occurred_at);
        $disp = strtotime((string) $r->dispatched_at);
        return ($disp - $occ); // seconds
    })
    ->filter(fn ($d) => $d !== false && $d >= 0)
    ->values()
    ->toArray();

sort($dispatched);
$dispatchStats = [
    'count_last_5m' => count($dispatched),
    'avg_s' => count($dispatched) > 0 ? round(array_sum($dispatched) / count($dispatched), 3) : null,
    'p50_s' => count($dispatched) > 0 ? $dispatched[(int) floor(count($dispatched) * 0.5)] : null,
    'p99_s' => count($dispatched) > 0 ? $dispatched[(int) min(count($dispatched) - 1, floor(count($dispatched) * 0.99))] : null,
    'max_s' => count($dispatched) > 0 ? max($dispatched) : null,
];

$snap = [
    'ts' => $nowUtc,
    'audit_logs' => [
        'count' => $auditCount,
        'last3' => $auditLast3,
        'last_hash' => $auditLastHash,
    ],
    'domain_events' => [
        'count' => $domainCount,
        'pending' => $domainPending,
        'last3' => $domainLast3,
        'dispatch_latency_5m' => $dispatchStats,
    ],
    'orders' => [
        'count' => $ordersCount,
        'last' => $ordersLast,
        'fiscal_alloc_errors_total' => $ordersFiscalAllocErrors,
        'fiscal_alloc_last_error' => $ordersFiscalAllocLast,
    ],
    'cash_movements' => [
        'count' => $cashCount,
        'last' => $cashLast,
    ],
    'z_reports' => [
        'count' => $zCount,
        'last' => $zLast,
    ],
    'failed_jobs' => [
        'count' => $failedJobs,
        'last' => $failedLast,
    ],
];

echo json_encode($snap, JSON_UNESCAPED_SLASHES) . PHP_EOL;
