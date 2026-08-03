<?php

namespace App\Listeners;

use App\Events\StockLevelChanged;
use App\Models\StockLevel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Listens to StockLevelChanged and emits a "stock low" log warning when
 * any updated stock_level row crosses on_hand <= threshold_low.
 *
 * Throttled by (branch_id, stockable_type, stockable_id) via cache key with
 * a configurable TTL (default 1h) to avoid alerting on every minor mutation.
 *
 * [HEAL-2 V102-03 2026-05-26] Activated by default via
 * FK_CATALOG_STOCK_LOW_ALERT_ENABLED=true. V1 Le Cayenne uses BINARY stock
 * (in stock / out of stock) — owner clarification "stocks juste configurés
 * par stock ou bien rupture" — so all stock_levels rows have threshold_low=0
 * and this listener short-circuits at the threshold check (no log emitted).
 * Kept active so V1.0.2 admin UI for `threshold_low > 0` lights up observability
 * immediately without env churn.
 *
 * IMPORTANT: This listener MUST NOT broadcast `ItemAvailabilityChanged` —
 * the binary on_hand → 0 rupture broadcast (POS + KDS toast + cart prune)
 * is already wired through
 * {@see \App\Services\Stock\StockService::syncItemAvailabilityForStockLevel}
 * → {@see \App\Events\ItemAvailabilityChanged}::forBranch(reason: 'stock_rupture')
 * → private-branch.{id} channel → POS `_announceAvailabilityChange()` +
 * KDS `_onItemAvailabilityChanged()`. Re-broadcasting here would produce
 * double-toasts on every rupture. Pinned by
 * tests/Feature/Stock/StockRuptureAlertListenerSentinelTest.php.
 *
 * Plan : plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md §2.7
 */
class NotifyStockLowOnStockLevelChanged
{
    public function handle(StockLevelChanged $event): void
    {
        if (! (bool) config('catalog_v15.stock_low_alert.enabled', false)) {
            return;
        }

        $throttleSeconds = (int) config('catalog_v15.stock_low_alert.throttle_seconds', 3600);

        $rows = StockLevel::query()
            ->whereIn('id', $event->stockLevelIds)
            ->where('branch_id', $event->branchId)
            ->whereNotNull('threshold_low')
            ->get();

        foreach ($rows as $row) {
            $onHand = (int) ($row->on_hand ?? 0);
            $threshold = (int) ($row->threshold_low ?? 0);

            if ($threshold <= 0 || $onHand > $threshold) {
                continue;
            }

            $cacheKey = sprintf(
                'stock_low_alert:%d:%s:%d',
                (int) $row->branch_id,
                (string) ($row->stockable_type ?? 'unknown'),
                (int) ($row->stockable_id ?? 0)
            );

            if (Cache::has($cacheKey)) {
                continue;
            }

            Cache::put($cacheKey, now()->toIso8601String(), $throttleSeconds);

            Log::warning('[stock.low-alert] threshold reached', [
                'branch_id'      => (int) $row->branch_id,
                'stock_level_id' => (int) $row->id,
                'stockable_type' => (string) ($row->stockable_type ?? 'unknown'),
                'stockable_id'   => (int) ($row->stockable_id ?? 0),
                'on_hand'        => $onHand,
                'threshold_low'  => $threshold,
            ]);

            // Future: dispatch a Notification (Mail / Database) here. V1 = log only
            // to avoid sending mail without explicit human-gate sign-off on recipients.
        }
    }
}
