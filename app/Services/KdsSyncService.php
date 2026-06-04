<?php

/**
 * F-03 / NEW-02 / NEW-04
 * Authority: plans/MEGA_PLAN_SYNC_HARDENING_v3_2026-04-23.md (Lot 1.C)
 *
 * Read-only adaptive sync endpoint backend. Returns deltas of active KDS
 * orders since a client `since` stamp + ids that left the active window
 * (DELIVERED / CANCELED / REJECTED / soft-deleted).
 *
 * Multi-tenant safe: cache key is salted with the resolved branch_id;
 * caller is responsible for branch_id resolution before invoking sync().
 */

namespace App\Services;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Http\Resources\KDSOrderDetailsResource;
use App\Models\Order;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;

class KdsSyncService
{
    /**
     * Returns the delta payload for the KDS sync endpoint.
     *
     * @param int               $branchId       Resolved branch id (0 = admin global).
     * @param DateTimeImmutable $since          Lower bound for updated_at / deleted_at filter.
     * @param bool              $includeDeleted When true, also returns ids that left the active window.
     *
     * @return array{server_now:string,branch_id:int|null,version:int,orders:array,deleted_ids:array}
     */
    public function sync(int $branchId, DateTimeImmutable $since, bool $includeDeleted = true): array
    {
        $serverNow = CarbonImmutable::now('UTC')->toIso8601String();
        $minuteBucket = CarbonImmutable::now('UTC')->format('YmdHi');
        $cacheBranchKey = $branchId === 0 ? 'all' : (string) $branchId;
        $cacheKey = sprintf(
            'kds.sync.%s.%s.%s',
            $cacheBranchKey,
            $minuteBucket,
            md5($since->format(DATE_ATOM) . '|' . (int) $includeDeleted)
        );

        return Cache::remember($cacheKey, 5, function () use ($branchId, $since, $includeDeleted, $serverNow) {
            $activeStatuses = [OrderStatus::ACCEPT, OrderStatus::PREPARING, OrderStatus::PREPARED];
            $inactiveStatuses = [OrderStatus::DELIVERED, OrderStatus::CANCELED, OrderStatus::REJECTED];

            $sinceForDb = $since->format('Y-m-d H:i:s');

            // Mirror KitchenDisplaySystemOrderService::list active window
            // (today's standard orders + advance orders scheduled today/overdue).
            // [Sprint 2A DEL-3 2026-05-16] Mirror eager-load of address/user so
            // sync-delta payloads carry delivery info parity with the full /list
            // endpoint (KDSOrderDetailsResource::toArray reads these relations).
            // [Wave 1 KDS Architect P1 2026-05-18] whereDate non-sargable →
            // whereBetween + "<" tomorrow. Mirrors Wave 5F fix shipped in
            // KitchenDisplaySystemOrderService::list:91-102 so idx_orders_datetime
            // is used and admin polling at 50+ open orders does not regress
            // 10x-30x. Sentinel: tests/Feature/Kds/KdsSyncSargableTest.php.
            //
            // [Wave T R5 KDS Adversarial P0 2026-05-20 KDS-T-R5-02] CORRECTION
            // of Wave 3 heal (commit 148dbebce). The previous heal converted
            // Paris-local day bounds to UTC strings before binding, on the
            // assumption that MySQL session TZ defaulted to UTC. EMPIRICALLY
            // FALSE: `SELECT @@session.time_zone` returns 'SYSTEM' which
            // resolves to the OS local TZ (Europe/Paris on this deployment).
            // The mysql.timezone config key is NULL so PDO does not override.
            //
            // Effect of buggy heal: bind strings interpreted as Paris-local
            // by MySQL → effective window shifted backward 2h → orders in
            // the last ~2h of each Paris day silently disappeared from the
            // KDS sync feed (mirror of the same bug in
            // KitchenDisplaySystemOrderService::list, validated empirically
            // pre-heal: 11 DB rows vs 1 row served at 23:51 Paris).
            //
            // CORRECT: use Paris-local Carbon bounds. MySQL interprets bind
            // strings under session_tz=Paris and compares against
            // Paris-local stored TIMESTAMP values directly. Sentinel:
            // tests/Feature/Sentinels/KdsTodayWindowTzSentinelTest.php
            // (covers both services via service-level roundtrip).
            //
            // INVARIANT WARNING: this heal depends on session_tz being OS
            // local (Paris). Any future PR that sets
            // config/database.php connections.mysql.timezone explicitly
            // must re-evaluate this query.
            $appTz = config('app.timezone');
            $todayStart = Carbon::today($appTz);
            $todayEnd = Carbon::today($appTz)->endOfDay();
            $tomorrowStart = Carbon::tomorrow($appTz);

            $ordersQuery = Order::with(['orderItems', 'address', 'user'])
                ->whereIn('status', $activeStatuses)
                ->where('updated_at', '>=', $sinceForDb)
                ->where(function ($q) use ($todayStart, $todayEnd, $tomorrowStart) {
                    $q->where(function ($s) use ($todayStart, $todayEnd) {
                        $s->whereBetween('order_datetime', [$todayStart, $todayEnd])
                          ->where('is_advance_order', Ask::NO);
                    })->orWhere(function ($s) use ($tomorrowStart) {
                        $s->where('is_advance_order', Ask::YES)
                          ->where('order_datetime', '<', $tomorrowStart)
                          ->whereNotIn('status', [OrderStatus::DELIVERED, OrderStatus::CANCELED]);
                    });
                });

            if ($branchId > 0) {
                $ordersQuery->where('branch_id', $branchId);
            }

            $orders = $ordersQuery
                ->orderByDesc('updated_at')
                ->limit(50)
                ->get();

            // Enrich each KDSOrderDetailsResource entry with `version` (used by
            // the frontend version-gate). version = updated_at unix seconds.
            $resourceOrders = KDSOrderDetailsResource::collection($orders)->resolve();
            $enrichedOrders = collect($resourceOrders)
                ->values()
                ->map(function ($resourceOrder, $index) use ($orders) {
                    $model = $orders->values()->get($index);
                    return array_merge((array) $resourceOrder, [
                        'version' => $this->computeOrderVersion($model),
                    ]);
                })
                ->all();

            $deletedIds = [];

            if ($includeDeleted) {
                $softDeletedQuery = Order::onlyTrashed()
                    ->where('deleted_at', '>=', $sinceForDb);

                $leftWindowQuery = Order::query()
                    ->where('updated_at', '>=', $sinceForDb)
                    ->whereIn('status', $inactiveStatuses);

                if ($branchId > 0) {
                    $softDeletedQuery->where('branch_id', $branchId);
                    $leftWindowQuery->where('branch_id', $branchId);
                }

                $deletedIds = $softDeletedQuery->pluck('id')
                    ->merge($leftWindowQuery->pluck('id'))
                    ->unique()
                    ->values()
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }

            return [
                'server_now' => $serverNow,
                'branch_id' => $branchId === 0 ? null : $branchId,
                'version' => (int) (microtime(true) * 1000),
                'orders' => $enrichedOrders,
                'deleted_ids' => $deletedIds,
            ];
        });
    }

    /**
     * Compute a per-order version stamp the frontend version-gate compares.
     *
     * TODO(F-03 / Phase 3 dette technique D-03bis): when the planned
     * `status_changed_at` column lands on Order, switch to
     * `max(updated_at_unix, status_changed_at_unix)` to avoid skipping cards
     * whose status moved without other field updates. Tracked in
     * `plans/MEGA_PLAN_SYNC_HARDENING_v3_2026-04-23.md` (§5).
     */
    public function computeOrderVersion(?Order $order): int
    {
        if ($order === null) {
            return 0;
        }

        return (int) (optional($order->updated_at)->getTimestamp() ?? 0);
    }
}
