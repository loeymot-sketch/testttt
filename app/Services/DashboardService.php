<?php

namespace App\Services;

use Exception;
use Carbon\Carbon;
use App\Models\Item;
use App\Models\User;
use App\Models\Order;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Http\Request;
use App\Libraries\AppLibrary;
use Illuminate\Support\Facades\Log;
use App\Libraries\QueryExceptionLibrary;

class DashboardService
{
    private function orderQuery()
    {
        $query = Order::query();
        $branchId = $this->dashboardBranchId();

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    private function customerQuery()
    {
        $query = User::role('Customer');
        $branchId = $this->dashboardBranchId();

        if ($branchId !== null) {
            $query->whereHas('orders', fn ($orders) => $orders->where('branch_id', $branchId));
        }

        return $query;
    }

    private function dashboardBranchId(): ?int
    {
        $user = auth()->user();

        if (! $user || $user->hasRole('Admin') || $user->hasRole('Tenant Admin')) {
            return null;
        }

        $branchId = (int) ($user->branch_id ?? 0);

        return $branchId > 0 ? $branchId : null;
    }

    /**
     * @throws Exception
     */
    public function orderStatistics(Request $request)
    {
        try {
            $order = $this->orderQuery();

            // [GOAL-G2-HEAL-04 2026-05-23] TZ-generation alignment to
            // Wave T R5 Paris bounds (commit 27d95e066). The Wave 3c
            // heal (commit 4905138fa, 2026-05-18) converted Paris-day
            // boundaries to UTC ASSUMING MySQL session_tz=UTC —
            // empirically FALSE on this deployment (session_tz=SYSTEM=
            // Paris because config/database.php connections.mysql.timezone
            // is NULL and PDO inherits OS local). UTC bind literals were
            // re-interpreted as Paris-local under session_tz=Paris,
            // shifting the day window backward by 2h → admin dashboard
            // silently dropped the last ~2h of every Paris day.
            //
            // Correct heal: bind Paris-local Carbon bounds directly so
            // MySQL session_tz=Paris interprets them at face value.
            // Sentinel: SisterServicesTzAwareV2Test (inverted to assert
            // Paris-local literal).
            //
            // INVARIANT DEPENDENCY: this heal assumes session_tz=OS-local
            // (Paris). Future config/database.php
            // connections.mysql.timezone => '+00:00' MUST re-evaluate.
            [$startParis, $endParisExclusive] = $this->resolveDayBoundaryParis(
                $request->first_date,
                $request->last_date
            );

            $orderStatisticsArray = [];

            $apply = static function ($q) use ($startParis, $endParisExclusive) {
                return $q->where('order_datetime', '>=', $startParis)
                         ->where('order_datetime', '<', $endParisExclusive);
            };

            $orderStatisticsArray["total_order"] = $apply(clone $order)->count();
            $orderStatisticsArray["pending_order"] = $apply((clone $order)->pending())->count();
            $orderStatisticsArray["accept_order"] = $apply((clone $order)->accept())->count();
            $orderStatisticsArray["preparing_order"] = $apply((clone $order)->preparing())->count();
            $orderStatisticsArray["prepared_order"] = $apply((clone $order)->prepared())->count();
            $orderStatisticsArray["out_for_delivery_order"] = $apply((clone $order)->outForDelivery())->count();
            $orderStatisticsArray["delivered_order"] = $apply((clone $order)->delivered())->count();
            $orderStatisticsArray["canceled_order"] = $apply((clone $order)->canceled())->count();
            $orderStatisticsArray["returned_order"] = $apply((clone $order)->returned())->count();
            $orderStatisticsArray["rejected_order"] = $apply((clone $order)->rejected())->count();

            return $orderStatisticsArray;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * [GOAL-G2-HEAL-04 2026-05-23] Resolve user-supplied (or fallback to
     * "today") Paris-local Y-m-d pair to a Paris-local Carbon range
     * [startParis, endParisExclusive). The upper bound is exclusive
     * (start of day-after) to avoid double-counting the boundary instant.
     *
     * Wave T R5 lesson (commit 27d95e066): MySQL session_tz=SYSTEM=Paris
     * on this deployment; binding Paris-local Carbon objects directly
     * matches face-value interpretation. The earlier UTC conversion
     * (renamed-from `resolveDayBoundaryUtc`) shifted the window backward
     * by 2h and silently dropped 22h-minuit Paris from analytics.
     *
     * @return array{0:\Carbon\Carbon,1:\Carbon\Carbon}
     */
    private function resolveDayBoundaryParis($firstDate, $lastDate): array
    {
        $appTz = config('app.timezone');

        if (! empty($firstDate) && ! empty($lastDate)) {
            $startParis = Carbon::parse($firstDate, $appTz)->startOfDay();
            $endParis = Carbon::parse($lastDate, $appTz)->addDay()->startOfDay();
        } else {
            $startParis = Carbon::today($appTz);
            $endParis = Carbon::tomorrow($appTz);
        }

        return [$startParis, $endParis];
    }


    public function orderSummary(Request $request)
    {
        try {
            $order = $this->orderQuery();
            // [GOAL-G2-HEAL-04 2026-05-23] TZ-generation alignment to Wave T R5
            // Paris bounds — see orderStatistics() comment for full rationale.
            // The user-supplied path uses raw Y-m-d strings; the default-month
            // path falls back to the current Paris-local month (first day
            // Y-m-01 .. last day Y-m-t).
            $appTz = config('app.timezone');
            if ($request->first_date && $request->last_date) {
                $firstDateParisDay = Carbon::parse($request->first_date, $appTz)->startOfDay();
                $lastDateParisDay = Carbon::parse($request->last_date, $appTz)->startOfDay();
            } else {
                $firstDateParisDay = Carbon::today($appTz)->startOfMonth();
                $lastDateParisDay = Carbon::today($appTz)->endOfMonth()->startOfDay();
            }
            $startParis = $firstDateParisDay->copy();
            $endParisExclusive = $lastDateParisDay->copy()->addDay();

            $first_date = $firstDateParisDay->toDateString();
            $last_date = $lastDateParisDay->toDateString();

            $orderSummaryArray = [];

            $apply = static function ($q) use ($startParis, $endParisExclusive) {
                return $q->where('order_datetime', '>=', $startParis)
                         ->where('order_datetime', '<', $endParisExclusive);
            };

            $total_order = $apply(clone $order)->count();
            $total_delivered = $apply((clone $order)->delivered())->count();
            $total_canceled = $apply((clone $order)->canceled())->count();
            $total_returned = $apply((clone $order)->returned())->count();
            $total_rejected = $apply((clone $order)->rejected())->count();


            if ($total_order > 0) {
                $orderSummaryArray["delivered"] = (int) round(($total_delivered * 100) / $total_order);
                $orderSummaryArray["returned"] = (int) round(($total_returned * 100) / $total_order);
                $orderSummaryArray["canceled"] = (int) round(($total_canceled * 100) / $total_order);
                $orderSummaryArray["rejected"] = (int) round(($total_rejected * 100) / $total_order);
            } else {
                $orderSummaryArray["delivered"] = 0;
                $orderSummaryArray["returned"] = 0;
                $orderSummaryArray["canceled"] = 0;
                $orderSummaryArray["rejected"] = 0;
            }

            return $orderSummaryArray;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function salesSummary(Request $request)
    {
        $order = $this->orderQuery();
        // [GOAL-G2-HEAL-04 2026-05-23] TZ-generation alignment to Wave T R5
        // Paris bounds — see orderStatistics() comment for full rationale.
        $appTz = config('app.timezone');
        if ($request->first_date && $request->last_date) {
            $firstDateParisDay = Carbon::parse($request->first_date, $appTz)->startOfDay();
            $lastDateParisDay = Carbon::parse($request->last_date, $appTz)->startOfDay();
        } else {
            $firstDateParisDay = Carbon::today($appTz)->startOfMonth();
            $lastDateParisDay = Carbon::today($appTz)->endOfMonth()->startOfDay();
        }
        $startParis = $firstDateParisDay->copy();
        $endParisExclusive = $lastDateParisDay->copy()->addDay();
        $first_date = $firstDateParisDay->toDateString();
        $last_date = $lastDateParisDay->toDateString();

        $date = date_diff(date_create($first_date), date_create($last_date), false);
        $date_diff = (int) $date->format("%a");

        $total_sales = AppLibrary::flatAmountFormat(
            (clone $order)
                ->where('order_datetime', '>=', $startParis)
                ->where('order_datetime', '<', $endParisExclusive)
                ->where('payment_status', PaymentStatus::PAID)
                ->sum('total')
        );

        $dateRangeArray = [];
        for ($currentDate = strtotime($first_date); $currentDate <= strtotime($last_date); $currentDate += (86400)) {

            $date = date('Y-m-d', $currentDate);
            $dateRangeArray[] = $date;
        }

        $dateRangeValueArray = [];
        for ($i = 0; $i <= count($dateRangeArray) - 1; $i++) {
            // Per-day Paris range — bind Paris-local Carbon directly
            // (Wave T R5 pattern, session_tz=Paris face-value).
            $dayStartParis = Carbon::parse($dateRangeArray[$i], $appTz)->startOfDay();
            $nextDayStartParis = $dayStartParis->copy()->addDay();
            $per_day = AppLibrary::flatAmountFormat(
                (clone $order)
                    ->where('order_datetime', '>=', $dayStartParis)
                    ->where('order_datetime', '<', $nextDayStartParis)
                    ->where('payment_status', PaymentStatus::PAID)
                    ->sum('total')
            );
            $dateRangeValueArray[] = floatval($per_day);
        }


        $salesSummaryArray = [];
        if ($date_diff > 0) {
            $salesSummaryArray['total_sales'] = AppLibrary::currencyAmountFormat($total_sales);
            $salesSummaryArray['avg_per_day'] = AppLibrary::currencyAmountFormat($total_sales / $date_diff);
            $salesSummaryArray['per_day_sales'] = $dateRangeValueArray;
        } else {
            $salesSummaryArray['total_sales'] = AppLibrary::currencyAmountFormat($total_sales);
            $salesSummaryArray['avg_per_day'] = AppLibrary::currencyAmountFormat($total_sales);
            $salesSummaryArray['per_day_sales'] = $dateRangeValueArray;
        }

        return $salesSummaryArray;
    }

    public function customerStates(Request $request)
    {
        $order = $this->orderQuery();
        // [GOAL-G2-HEAL-04 2026-05-23] TZ-generation alignment to Wave T R5
        // Paris bounds — see orderStatistics() comment for full rationale.
        $appTz = config('app.timezone');
        if ($request->first_date && $request->last_date) {
            $firstDateParisDay = Carbon::parse($request->first_date, $appTz)->startOfDay();
            $lastDateParisDay = Carbon::parse($request->last_date, $appTz)->startOfDay();
        } else {
            $firstDateParisDay = Carbon::today($appTz)->startOfMonth();
            $lastDateParisDay = Carbon::today($appTz)->endOfMonth()->startOfDay();
        }
        $startParis = $firstDateParisDay->copy();
        $endParisExclusive = $lastDateParisDay->copy()->addDay();

        $timeArray = ["06:00", "07:00", "08:00", "09:00", "10:00", "11:00", "12:00", "13:00", "14:00", "15:00", "16:00", "17:00", "18:00", "19:00", "20:00", "21:00", "22:00", "23:00"];

        $customerSateArray = [];
        $totalCustomerArray = [];
        $first_time = "";
        $last_time = "";
        for ($i = 0; $i <= count($timeArray) - 1; $i++) {
            $first_time = date('H:i', strtotime($timeArray[$i]));
            $last_time = date('H:i', strtotime($timeArray[$i] . ' +59 minutes'));

            // whereTime intentionally NOT converted — admin tunes hours-of-day
            // analytics in Paris-local clock; whereTime on a TIMESTAMP column
            // would surface time-of-day in MySQL session UTC. This is a known
            // V1.0.2 backlog item (KDS-ADV3C-12) — see CONVERGENCE_FINAL.md.
            $total_customer = (clone $order)
                ->where('order_datetime', '>=', $startParis)
                ->where('order_datetime', '<', $endParisExclusive)
                ->whereTime('order_datetime', '>=', Carbon::parse($first_time))
                ->whereTime('order_datetime', '<=', Carbon::parse($last_time))
                ->get()->count();
            $totalCustomerArray[] = $total_customer;
        }

        $customerSateArray['total_customers'] = $totalCustomerArray;
        $customerSateArray['times'] = $timeArray;

        return $customerSateArray;
    }

    public function topCustomers()
    {
        try {
            $branchId = $this->dashboardBranchId();

            return $this->customerQuery()
                ->withCount(['orders' => function ($query) use ($branchId): void {
                    if ($branchId !== null) {
                        $query->where('branch_id', $branchId);
                    }
                }])
                ->orderBy('orders_count', 'desc')
                ->limit(8)
                ->get();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function totalSales()
    {
        try {
            return $this->orderQuery()->where('payment_status', PaymentStatus::PAID)->sum('total');
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function totalOrders()
    {
        try {
            return $this->orderQuery()->where('status', OrderStatus::DELIVERED)->count();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function totalCustomers()
    {
        try {
            return $this->customerQuery()->count();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function totalMenuItems()
    {
        try {
            return Item::count();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function realtimeReport()
    {
        try {
            // [GOAL-G2-HEAL-04 2026-05-23] TZ-generation alignment to Wave T R5
            // Paris bounds — see orderStatistics() comment for full rationale.
            $appTz = config('app.timezone');
            $startParis = Carbon::today($appTz);
            $endParisExclusive = Carbon::tomorrow($appTz);

            // Total CA du jour (Commandes payées)
            $daily_sales = $this->orderQuery()
                ->where('order_datetime', '>=', $startParis)
                ->where('order_datetime', '<', $endParisExclusive)
                ->where('payment_status', PaymentStatus::PAID)
                ->sum('total');

            // Nombre de commandes
            $daily_orders = $this->orderQuery()
                ->where('order_datetime', '>=', $startParis)
                ->where('order_datetime', '<', $endParisExclusive)
                ->count();

            // Ticket Moyen
            $average_ticket = $daily_orders > 0 ? ($daily_sales / $daily_orders) : 0;

            return [
                'daily_sales' => AppLibrary::currencyAmountFormat($daily_sales),
                'daily_orders' => $daily_orders,
                'average_ticket' => AppLibrary::currencyAmountFormat($average_ticket),
            ];
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function slaAlerts()
    {
        try {
            // Commandes en PREPARING depuis plus de 15 minutes
            $timeLimit = Carbon::now()->subMinutes(15);
            $alerts = $this->orderQuery()
                ->where('status', OrderStatus::PREPARING)
                ->where('updated_at', '<', $timeLimit)
                ->with('user')
                ->orderBy('updated_at', 'asc')
                ->get();

            return $alerts->map(function ($order) {
                return [
                    'order_serial_no' => $order->order_serial_no,
                    'queue_number' => $order->queue_number,
                    'time_preparing' => $order->updated_at->diffInMinutes(Carbon::now()),
                    'total' => AppLibrary::currencyAmountFormat($order->total),
                    'customer' => $order->user ? $order->user->name : 'N/A'
                ];
            });
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function channelStatistics()
    {
        try {
            // [GOAL-G2-HEAL-04 2026-05-23] TZ-generation alignment to Wave T R5
            // Paris bounds — see orderStatistics() comment for full rationale.
            $appTz = config('app.timezone');
            $startParis = Carbon::today($appTz);
            $endParisExclusive = Carbon::tomorrow($appTz);
            $orders = $this->orderQuery()
                ->where('order_datetime', '>=', $startParis)
                ->where('order_datetime', '<', $endParisExclusive)
                ->get();
            $total = $orders->count();

            if ($total === 0) {
                return [
                    ['name' => 'Web', 'value' => 0],
                    ['name' => 'Kiosk/App', 'value' => 0],
                    ['name' => 'POS', 'value' => 0]
                ];
            }

            // [WG-3 WF-3 P1 2026-05-19] WHY kiosk orders mis-bucket as Web:
            //
            //   The kiosk frontend posts `source = Source::WEB (=5)` because
            //   the kiosk runs the same web shell. Prior code key'd kiosk on
            //   `source == Source::APP (=10)` — an assumption that has NEVER
            //   matched the actual production payload. Result: ~152 kiosk
            //   orders silently mis-bucket as "Web" in admin analytics.
            //   (Sync/KDS unaffected — they already discriminate via
            //   `source_surface` + `order_type`, not the legacy int.)
            //
            // FIX (surgical, back-compat safe):
            //
            //   Promote `source_surface = 'kiosk'` as the canonical positive
            //   kiosk marker. This is the explicit string tag that
            //   FrontendOrderService writes for every kiosk order created
            //   since 2026-03-26 (see also KDSOrderDetailsResource which
            //   states "KDS lane bucketing must use source_surface"). All
            //   other buckets keep keying on the legacy `source` int so the
            //   long-tail (DELIVERY orders auto-tagged source_surface=
            //   'delivery', pre-migration historical rows, web/POS rows with
            //   source_surface='web'|'pos') keep counting exactly as before.
            //
            //   - Kiosk: source_surface='kiosk'  OR  source=Source::APP (legacy)
            //   - Web:   source=Source::WEB  AND  source_surface != 'kiosk'
            //   - POS:   source=Source::POS
            //
            // No frontend change. No migration. Pure backend bucketing fix.
            $kioskCount = $orders->filter(function ($order) {
                if ((string) ($order->source_surface ?? '') === 'kiosk') {
                    return true;
                }

                return (int) $order->source === \App\Enums\Source::APP;
            })->count();

            $webCount = $orders->filter(function ($order) {
                // Exclude kiosk-tagged rows (they post source=WEB but are not
                // browser-web channel orders).
                if ((string) ($order->source_surface ?? '') === 'kiosk') {
                    return false;
                }

                return (int) $order->source === \App\Enums\Source::WEB;
            })->count();

            $posCount = $orders->where('source', \App\Enums\Source::POS)->count();

            return [
                ['name' => 'Web', 'value' => round(($webCount / $total) * 100, 2)],
                ['name' => 'Kiosk/App', 'value' => round(($kioskCount / $total) * 100, 2)],
                ['name' => 'POS', 'value' => round(($posCount / $total) * 100, 2)]
            ];
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function auditTrail()
    {
        try {
            // [GAP-FIX-02 2026-05-25] Switch source from non-hash-chained
            // App\Models\ActionLog to NF525 hash-chained App\Models\AuditLog.
            // The widget previously misled inspectors by labelling generic
            // ActionLog rows (no prev_hash/current_hash, mutable) as the
            // "audit trail". AuditLog is the INSERT-only fiscal evidence
            // table; exposing its `current_hash` short prefix lets the
            // dashboard double as a chain-integrity smoke proof.
            //
            // Branch scoping note: AuditLog is in the BranchScopeCoverageSentinel
            // EXEMPTED_MODELS list (V1.0.2 backlog C-P0-D — fiscal model,
            // manual scope today). We MUST still filter manually here, same
            // discipline as the previous ActionLog implementation:
            //   - Admin (branch_id = 0) sees every branch.
            //   - Branch staff sees ONLY their own branch.
            //   - Legacy branch_id = NULL rows are admin-only (system events).
            $actor = auth()->user();
            $actorBranchId = (int) ($actor?->branch_id ?? 0);

            $query = \App\Models\AuditLog::query();
            if ($actorBranchId > 0) {
                $query->where('branch_id', $actorBranchId);
            }

            $rows = $query->orderBy('id', 'desc')
                ->limit(50)
                ->get();

            // Resolve user names in one round-trip. AuditLog has no `user()`
            // relation declared (we intentionally keep the NF525 model file
            // minimal and behavioral hooks-only — see app/Models/AuditLog.php
            // booted() guard). Service-side lookup keeps the fiscal model
            // surface untouched.
            $userIds = $rows->pluck('user_id')->filter()->unique()->all();
            $users = $userIds
                ? User::whereIn('id', $userIds)->get(['id', 'name'])->keyBy('id')
                : collect();

            return $rows->map(function ($log) use ($users) {
                $payload = (array) ($log->payload ?? []);
                $resourceRef = $log->resource_id !== null
                    ? ($log->resource . '#' . $log->resource_id)
                    : $log->resource;

                return [
                    'id' => $log->id,
                    'user_name' => $log->user_id && isset($users[$log->user_id])
                        ? $users[$log->user_id]->name
                        : 'Système',
                    'branch_id' => $log->branch_id,
                    'action' => $log->action,
                    'resource' => $resourceRef,
                    'hash_prefix' => substr((string) $log->current_hash, 0, 8),
                    'payload_keys' => array_slice(array_keys($payload), 0, 5),
                    'time' => $log->created_at?->diffForHumans(),
                ];
            });
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
