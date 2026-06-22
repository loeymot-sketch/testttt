<?php
// Sync Monitor analyzer — reads snapshots.jsonl + fiscal-events.log,
// computes deltas, latencies, integrity verdict, writes SYNC-MONITOR.json.

declare(strict_types=1);

$base = __DIR__;
$stream = $base . '/snapshots.jsonl';
$logFile = $base . '/fiscal-events.log';
$out = $base . '/SYNC-MONITOR.json';

if (!is_file($stream)) {
    fwrite(STDERR, "Missing snapshots.jsonl\n");
    exit(2);
}

$lines = array_values(array_filter(array_map('trim', file($stream)), fn ($l) => $l !== ''));
$snaps = array_map(fn ($l) => json_decode($l, true), $lines);

if (empty($snaps)) {
    fwrite(STDERR, "No snapshots decoded\n");
    exit(2);
}

$first = $snaps[0];
$last = end($snaps);

// Prefer durable pre-test baseline if first snapshot is missing fields
$baselineFile = $base . '/baseline-true.json';
$trueBase = is_file($baselineFile) ? json_decode((string) file_get_contents($baselineFile), true) : null;
$baseFiscalErr = $trueBase['orders_fiscal_alloc_errors_total']
    ?? $first['orders']['fiscal_alloc_errors_total']
    ?? 0;
$baseFailedJobs = $trueBase['failed_jobs_count'] ?? $first['failed_jobs']['count'];

// Deltas vs baseline — prefer durable baseline file if available
$baseAudit = $trueBase['audit_logs_count'] ?? $first['audit_logs']['count'];
$baseDomain = $trueBase['domain_events_count'] ?? $first['domain_events']['count'];
$baseOrders = $trueBase['orders_count'] ?? $first['orders']['count'];
$baseCash = $trueBase['cash_movements_count'] ?? $first['cash_movements']['count'];
$baseZ = $trueBase['z_reports_count'] ?? $first['z_reports']['count'];

$deltas = [
    'audit_logs_added' => $last['audit_logs']['count'] - $baseAudit,
    'domain_events_added' => $last['domain_events']['count'] - $baseDomain,
    'orders_added' => $last['orders']['count'] - $baseOrders,
    'cash_movements_added' => $last['cash_movements']['count'] - $baseCash,
    'z_reports_added' => $last['z_reports']['count'] - $baseZ,
    'failed_jobs_added' => $last['failed_jobs']['count'] - $baseFailedJobs,
];

// Outbox dispatch stats — aggregated across all snapshots (rolling 5m windows)
$allAvg = [];
$allP99 = [];
$allMax = [];
$totalDispatched = 0;
foreach ($snaps as $s) {
    $st = $s['domain_events']['dispatch_latency_5m'];
    if (($st['count_last_5m'] ?? 0) > 0) {
        $allAvg[] = $st['avg_s'];
        $allP99[] = $st['p99_s'];
        $allMax[] = $st['max_s'];
        $totalDispatched = max($totalDispatched, $st['count_last_5m']);
    }
}

$outboxStats = [
    'samples' => count($allAvg),
    'avg_of_avgs_s' => count($allAvg) ? round(array_sum($allAvg) / count($allAvg), 3) : null,
    'max_p99_s' => count($allP99) ? max($allP99) : null,
    'max_latency_s' => count($allMax) ? max($allMax) : null,
    'rolling_5m_count_peak' => $totalDispatched,
];

// Chain integrity: last_hash must change ONLY by append, never reset
$hashes = array_map(fn ($s) => $s['audit_logs']['last_hash'], $snaps);
$counts = array_map(fn ($s) => $s['audit_logs']['count'], $snaps);
$chainOk = true;
$chainNotes = [];
for ($i = 1; $i < count($snaps); $i++) {
    if ($counts[$i] < $counts[$i - 1]) {
        $chainOk = false;
        $chainNotes[] = "audit_logs count regressed snap $i: {$counts[$i - 1]} -> {$counts[$i]}";
    }
    if ($counts[$i] === $counts[$i - 1] && $hashes[$i] !== $hashes[$i - 1]) {
        $chainOk = false;
        $chainNotes[] = "audit_logs hash changed without count grow at snap $i";
    }
}

// Stuck domain_events (pending > 0 for consecutive snapshots)
$stuckPending = [];
foreach ($snaps as $idx => $s) {
    if (($s['domain_events']['pending'] ?? 0) > 0) {
        $stuckPending[] = ['seq' => $s['seq'] ?? "snap$idx", 'pending' => $s['domain_events']['pending']];
    }
}

// Broadcast event distribution
$broadcastSeen = [];
foreach ($snaps as $s) {
    foreach (($s['domain_events']['last3'] ?? []) as $ev) {
        $name = $ev['broadcast_as'] ?? 'unknown';
        $broadcastSeen[$name] = ($broadcastSeen[$name] ?? 0) + 1;
    }
}

// Time-series condensed view
$timeSeries = array_map(fn ($s) => [
    'seq' => $s['seq'] ?? null,
    'ts' => $s['ts'],
    'audit_count' => $s['audit_logs']['count'],
    'audit_last_hash_8' => substr($s['audit_logs']['last_hash'] ?? '', 0, 8),
    'domain_count' => $s['domain_events']['count'],
    'domain_pending' => $s['domain_events']['pending'],
    'orders_count' => $s['orders']['count'],
    'orders_last_fiscal_seq' => $s['orders']['last']['fiscal_sequence_no'] ?? null,
    'orders_last_queue' => $s['orders']['last']['queue_number'] ?? null,
    'cash_count' => $s['cash_movements']['count'],
    'z_count' => $s['z_reports']['count'],
    'failed_jobs_count' => $s['failed_jobs']['count'],
    'dispatch_avg_s_5m' => $s['domain_events']['dispatch_latency_5m']['avg_s'] ?? null,
    'dispatch_p99_s_5m' => $s['domain_events']['dispatch_latency_5m']['p99_s'] ?? null,
], $snaps);

// Fiscal events log tail
$fiscalEvents = [];
if (is_file($logFile)) {
    $logLines = file($logFile, FILE_IGNORE_NEW_LINES);
    $fiscalEvents = array_slice($logLines, -50);
}

// Verdict
$alerts = [];
if (!$chainOk) {
    $alerts[] = ['severity' => 'P0', 'msg' => 'audit_logs chain integrity violation', 'details' => $chainNotes];
}
if ($deltas['failed_jobs_added'] > 0) {
    // NF525 RO mandate: failed_jobs MUST stay 0 during real fiscal flow
    $alerts[] = ['severity' => 'P0', 'msg' => "failed_jobs grew by {$deltas['failed_jobs_added']} during test window (NF525 RO: MUST stay 0)"];
}
if (!empty($stuckPending) && count($stuckPending) >= 2) {
    $alerts[] = ['severity' => 'P1', 'msg' => 'domain_events stuck pending for 2+ consecutive snapshots', 'details' => $stuckPending];
}
if (($outboxStats['max_p99_s'] ?? 0) > 5) {
    $alerts[] = ['severity' => 'P2', 'msg' => "outbox dispatch p99 latency exceeded 5s (max={$outboxStats['max_p99_s']}s)"];
}
// Inverse assertion: if orders were created but audit chain didn't grow → chain bypassed
if ($deltas['orders_added'] > 0 && $deltas['audit_logs_added'] === 0) {
    $alerts[] = ['severity' => 'P0', 'msg' => "Orders added ({$deltas['orders_added']}) without ANY audit_logs growth — NF525 chain BYPASSED"];
}
// Fiscal allocation errors during window
$finalFiscalErr = $last['orders']['fiscal_alloc_errors_total'] ?? 0;
$fiscalErrDelta = $finalFiscalErr - $baseFiscalErr;
if ($fiscalErrDelta > 0) {
    $alerts[] = ['severity' => 'P1', 'msg' => "fiscal_alloc_error_at flagged on $fiscalErrDelta order(s) during window — retry cron must clear"];
}
// No activity → AMBER (monitor observed nothing, can't certify mesh healthy)
$totalActivity = $deltas['audit_logs_added'] + $deltas['orders_added'] + $deltas['domain_events_added'];
if ($totalActivity === 0) {
    $alerts[] = ['severity' => 'P2', 'msg' => 'No flow activity observed during 10-min window — sync mesh untested under load'];
}

$verdict = 'GREEN';
foreach ($alerts as $a) {
    if ($a['severity'] === 'P0') {
        $verdict = 'RED';
        break;
    }
    if ($a['severity'] === 'P1' || $a['severity'] === 'P2') {
        // P2 (e.g. no activity) downgrades from GREEN to AMBER but stays AMBER vs RED
        if ($verdict !== 'RED') {
            $verdict = 'AMBER';
        }
    }
}

$report = [
    'agent' => 'SYNC-MONITOR',
    'mission' => 'Watch sync mesh while REAL FLOW TRACKER places orders',
    'discipline' => 'DM6 NF525 RO',
    'branch' => 'heal/cms-pr1-quickwins-2026-05-18',
    'window' => [
        'start_ts' => $first['ts'],
        'end_ts' => $last['ts'],
        'snapshots_captured' => count($snaps),
        'baseline_seq' => $first['seq'] ?? 'T+000s',
        'final_seq' => $last['seq'] ?? 'last',
    ],
    'baseline' => [
        'audit_logs' => $first['audit_logs']['count'],
        'audit_logs_last_hash' => $first['audit_logs']['last_hash'],
        'domain_events' => $first['domain_events']['count'],
        'orders' => $first['orders']['count'],
        'cash_movements' => $first['cash_movements']['count'],
        'z_reports' => $first['z_reports']['count'],
        'failed_jobs' => $first['failed_jobs']['count'],
    ],
    'final' => [
        'audit_logs' => $last['audit_logs']['count'],
        'audit_logs_last_hash' => $last['audit_logs']['last_hash'],
        'domain_events' => $last['domain_events']['count'],
        'orders' => $last['orders']['count'],
        'cash_movements' => $last['cash_movements']['count'],
        'z_reports' => $last['z_reports']['count'],
        'failed_jobs' => $last['failed_jobs']['count'],
    ],
    'deltas_during_window' => $deltas,
    'outbox_dispatch_stats' => $outboxStats,
    'broadcast_distribution_observed_in_last3' => $broadcastSeen,
    'sync_chain_integrity' => [
        'audit_chain_ok' => $chainOk,
        'chain_notes' => $chainNotes,
        'baseline_hash' => $first['audit_logs']['last_hash'],
        'final_hash' => $last['audit_logs']['last_hash'],
        'hash_advanced' => $first['audit_logs']['last_hash'] !== $last['audit_logs']['last_hash'],
    ],
    'stuck_domain_events' => $stuckPending,
    'failed_jobs_during_window' => $deltas['failed_jobs_added'],
    'fiscal_log_tail_50' => $fiscalEvents,
    'time_series' => $timeSeries,
    'critical_alerts' => $alerts,
    'verdict' => $verdict,
    'verdict_rationale' => [
        'chain_integrity' => $chainOk ? 'audit_logs APPENDED-ONLY across window' : 'CHAIN VIOLATION DETECTED',
        'orders_audit_coupling' => ($deltas['orders_added'] === 0 || $deltas['audit_logs_added'] > 0) ? 'orders growth coupled with audit growth' : 'orders grew without audit growth (BYPASS)',
        'outbox_health' => count($stuckPending) === 0 ? 'no pending domain_events stuck' : 'pending events observed',
        'queue_health' => $deltas['failed_jobs_added'] === 0 ? 'no new failed_jobs during window' : "{$deltas['failed_jobs_added']} new failed_jobs",
        'dispatch_latency' => ($outboxStats['max_p99_s'] ?? null) !== null ? "p99={$outboxStats['max_p99_s']}s (target <5s)" : 'no dispatches in window',
        'fiscal_alloc_errors_delta' => "$fiscalErrDelta new fiscal_alloc_error flags during window",
    ],
    'generated_at' => gmdate('c'),
];

file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Wrote $out\n";
echo "Verdict: $verdict\n";
echo "Snapshots: " . count($snaps) . "\n";
echo "Deltas: " . json_encode($deltas) . "\n";
echo "Alerts: " . count($alerts) . "\n";
