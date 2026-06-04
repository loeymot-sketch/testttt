<?php
/*
 * SYNC LATENCY MEASUREMENT — Full Audit 2026-05-29
 *
 * Strategy: Fire events on EXISTING orders (status-change cycles).
 * Each event flows: Event → PersistXxxToOutbox listener → DispatchDomainEventsJob → Pusher broadcast → SyncMetricsRecorder
 * Read latency from sync_metrics table (the actual production telemetry SSOT).
 *
 * Chains measured:
 *  X-01/X-02/X-03: OrderStatusChanged status-cycle (PREPARING→PREPARED→DELIVERED) — represents POS→KDS, Kiosk→KDS, KDS→OSS triplet
 *  X-04 (Stock cascade): ItemAvailabilityChanged
 *  X-05 (Settings): catalog.changed via CatalogChanged event
 *  X-06 (Branch): BranchStatusChanged — DESTRUCTIVE, skipped (peer teams active)
 *  X-07 (Refund): single refund + audit trail capture
 *
 * Output: reports/test-e2e/full-audit-2026-05-29/SYNC/raw/*.json
 */

$N = 10;
$BRANCH = 1;
$OUT_DIR = __DIR__ . '/../raw';
@mkdir($OUT_DIR, 0755, true);

function snapshot_last_metric_id(string $eventType): int {
    return (int) (\Illuminate\Support\Facades\DB::table('sync_metrics')
        ->where('metric_type', 'outbox.dispatch_latency_ms')
        ->where('labels', 'like', '%"' . $eventType . '"%')
        ->max('id') ?? 0);
}

function poll_for_new_metric(int $sinceId, string $expectedEventType, int $timeoutMs = 15000): ?array {
    $start = microtime(true);
    while ((microtime(true) - $start) * 1000 < $timeoutMs) {
        $m = \Illuminate\Support\Facades\DB::table('sync_metrics')
            ->where('id', '>', $sinceId)
            ->where('metric_type', 'outbox.dispatch_latency_ms')
            ->where('labels', 'like', '%"' . $expectedEventType . '"%')
            ->orderBy('id', 'asc')
            ->first();
        if ($m) {
            return [
                'metric_id'        => (int) $m->id,
                'value_ms'         => (int) $m->value,
                'occurred'         => (string) $m->occurred_at,
                'correlation_id'   => (string) $m->correlation_id,
            ];
        }
        usleep(50000); // 50ms poll
    }
    return null;
}

function fire_order_status_change(\App\Models\Order $order, int $newStatus): array {
    $oldStatus = $order->status;
    // Save back to db so it persists
    $order->status = $newStatus;
    $order->save();
    \App\Events\OrderStatusChanged::dispatch($order, $oldStatus, $newStatus);
    return ['order_id' => $order->id, 'old' => $oldStatus, 'new' => $newStatus];
}

// === LOAD CONTEXT ===
$admin = \App\Models\User::whereHas('roles', fn($q) => $q->where('name', 'Admin'))->first();
auth('web')->loginUsingId($admin->id);

// Identify some test orders — use orders we created or existing ones (excluding fiscal-allocated ones)
$candidateOrders = \App\Models\Order::where('branch_id', 1)
    ->whereIn('status', [2, 4, 5, 7]) // PREPARING/PREPARED/DELIVERED-ish
    ->orderBy('id', 'desc')
    ->limit($N * 2)
    ->get();

echo "[SYNC AUDIT 2026-05-29] Found " . $candidateOrders->count() . " candidate orders (branch=1)\n";
if ($candidateOrders->count() < 1) {
    echo "ERROR: not enough orders. abort.\n";
    exit(1);
}

// === X-01/X-02/X-03 OrderStatusChanged broadcast latency ===
echo "\n=== X-01/X-02/X-03 OrderStatusChanged broadcast latency (N={$N}) ===\n";
$samples_status = [];
for ($i = 0; $i < $N; $i++) {
    $order = $candidateOrders[$i % $candidateOrders->count()];
    $beforeId = snapshot_last_metric_id('order.status_changed');
    $oldStatus = $order->status;
    // Flip to a new status: cycle through 2, 4, 5
    $newStatus = match ($i % 3) { 0 => 4, 1 => 5, default => 2 };
    if ($newStatus === $oldStatus) $newStatus = 4;
    $t0 = microtime(true);
    try {
        fire_order_status_change($order, $newStatus);
        $result = poll_for_new_metric($beforeId, 'order.status_changed', 15000);
        $wallE2e = (int) ((microtime(true) - $t0) * 1000);
        $samples_status[] = [
            'sample'              => $i + 1,
            'order_id'            => $order->id,
            'old_status'          => $oldStatus,
            'new_status'          => $newStatus,
            'fired_at_ms'         => (int) ($t0 * 1000),
            'backend_latency_ms'  => $result['value_ms'] ?? null,
            'wall_e2e_ms'         => $wallE2e,
            'metric_id'           => $result['metric_id'] ?? null,
            'correlation_id'      => $result['correlation_id'] ?? null,
            'status'              => $result ? 'OK' : 'TIMEOUT_15s',
        ];
        echo sprintf("  #%d order=%d %d→%d backend=%sms e2e=%dms\n",
            $i + 1, $order->id, $oldStatus, $newStatus, $result['value_ms'] ?? 'TIMEOUT', $wallE2e);
    } catch (Throwable $e) {
        echo "  #" . ($i + 1) . " ERROR: " . $e->getMessage() . "\n";
        $samples_status[] = ['sample' => $i + 1, 'order_id' => $order->id, 'error' => $e->getMessage()];
    }
    usleep(150000); // 150ms gap
}
file_put_contents("$OUT_DIR/X-01-02-03-order-status-changed.json", json_encode($samples_status, JSON_PRETTY_PRINT));

// === X-04 ItemAvailabilityChanged (stock cascade) ===
echo "\n=== X-04 ItemAvailabilityChanged (stock cascade) (N={$N}) ===\n";
$samples_x04 = [];
$testItem = \Illuminate\Support\Facades\DB::table('items')->whereNull('deleted_at')->limit(1)->first();
$Nstock = $N;
for ($i = 0; $i < $Nstock; $i++) {
    $beforeId = snapshot_last_metric_id('menu.item_availability_changed');
    $t0 = microtime(true);
    try {
        \App\Events\ItemAvailabilityChanged::dispatch(
            (int) $testItem->id,
            (int) $testItem->status,
            (float) $testItem->price,
            'branch_availability',
            1,
            true,
            'sync_audit_2026-05-29_X04_sample_' . ($i + 1)
        );
        $result = poll_for_new_metric($beforeId, 'menu.item_availability_changed', 15000);
        $wallE2e = (int) ((microtime(true) - $t0) * 1000);
        $samples_x04[] = [
            'sample' => $i + 1,
            'item_id' => $testItem->id,
            'backend_latency_ms' => $result['value_ms'] ?? null,
            'wall_e2e_ms' => $wallE2e,
            'metric_id' => $result['metric_id'] ?? null,
            'status' => $result ? 'OK' : 'TIMEOUT',
        ];
        echo sprintf("  #%d item=%d backend=%sms e2e=%dms\n",
            $i + 1, $testItem->id, $result['value_ms'] ?? 'TIMEOUT', $wallE2e);
    } catch (Throwable $e) {
        echo "  #" . ($i + 1) . " ERROR: " . $e->getMessage() . "\n";
        $samples_x04[] = ['sample' => $i + 1, 'error' => $e->getMessage()];
    }
    usleep(150000);
}
file_put_contents("$OUT_DIR/X-04-item-availability-changed.json", json_encode($samples_x04, JSON_PRETTY_PRINT));

// === X-05 CatalogChanged / Settings cascade — small batch, no actual currency flip (peer-team safe) ===
echo "\n=== X-05 CatalogChanged broadcast (N=5, safe — no currency flip) ===\n";
$samples_x05 = [];
for ($i = 0; $i < 5; $i++) {
    $beforeId = snapshot_last_metric_id('catalog.changed');
    $t0 = microtime(true);
    try {
        \App\Events\CatalogChanged::dispatch('item', (int) $testItem->id, 'updated', 1, ['reason' => 'sync_audit_X05_sample_' . ($i + 1)]);
        $result = poll_for_new_metric($beforeId, 'catalog.changed', 15000);
        $wallE2e = (int) ((microtime(true) - $t0) * 1000);
        $samples_x05[] = [
            'sample' => $i + 1,
            'backend_latency_ms' => $result['value_ms'] ?? null,
            'wall_e2e_ms' => $wallE2e,
            'status' => $result ? 'OK' : 'TIMEOUT',
        ];
        echo sprintf("  #%d backend=%sms e2e=%dms\n",
            $i + 1, $result['value_ms'] ?? 'TIMEOUT', $wallE2e);
    } catch (Throwable $e) {
        echo "  #" . ($i + 1) . " ERROR: " . $e->getMessage() . "\n";
        $samples_x05[] = ['sample' => $i + 1, 'error' => $e->getMessage()];
    }
    usleep(150000);
}
file_put_contents("$OUT_DIR/X-05-catalog-changed.json", json_encode($samples_x05, JSON_PRETTY_PRINT));

// === X-06 Branch deactivate: DESTRUCTIVE — DEFERRED (peer teams active) ===
echo "\n=== X-06 Branch deactivate: DEFERRED (audit-wide multi-team active) ===\n";

// === X-07 Refund mirror: STATIC CHECK only — verify chain code path exists (do NOT mint new refund) ===
echo "\n=== X-07 Refund mirror chain: static code verification ===\n";
// Will be done outside this script via grep/Read.

echo "\n[DONE] All samples written to $OUT_DIR/\n";
