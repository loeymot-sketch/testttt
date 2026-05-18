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

            // [Wave 3c KDS-ADV3C-01 P0 2026-05-18] orders.order_datetime is a
            // TIMESTAMP column (MySQL stores UTC). The legacy whereDate(today)
            // pattern compared Paris-local Y-m-d against DATE(order_datetime)
            // computed in UTC session — orders [00:00-02:00 Paris]/day fell
            // outside the day window. Heal mirrors Wave 2b KdsSyncService
            // (148dbebce): convert the Paris-day boundary to a full UTC
            // TIMESTAMP and use sargable `whereBetween` (also picks up
            // idx_orders_datetime). Sentinel: SisterServicesTzAwareV2Test.
            [$startUtc, $endUtcExclusive] = $this->resolveDayBoundaryUtc(
                $request->first_date,
                $request->last_date
            );

            $orderStatisticsArray = [];

            $apply = static function ($q) use ($startUtc, $endUtcExclusive) {
                return $q->where('order_datetime', '>=', $startUtc)
                         ->where('order_datetime', '<', $endUtcExclusive);
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
     * [Wave 3c KDS-ADV3C-01 P0 2026-05-18] Convert a user-supplied (or
     * fallback to "today") Paris-local Y-m-d pair to the UTC TIMESTAMP
     * range [startUtc, endUtcExclusive). The upper bound is exclusive
     * (start of day-after) to avoid double-counting the boundary instant.
     *
     * @return array{0:\Carbon\Carbon,1:\Carbon\Carbon}
     */
    private function resolveDayBoundaryUtc($firstDate, $lastDate): array
    {
        $appTz = config('app.timezone');

        if (! empty($firstDate) && ! empty($lastDate)) {
            $startParis = Carbon::parse($firstDate, $appTz)->startOfDay();
            $endParis = Carbon::parse($lastDate, $appTz)->addDay()->startOfDay();
        } else {
            $startParis = Carbon::today($appTz);
            $endParis = Carbon::tomorrow($appTz);
        }

        return [
            $startParis->copy()->setTimezone('UTC'),
            $endParis->copy()->setTimezone('UTC'),
        ];
    }


    public function orderSummary(Request $request)
    {
        try {
            $order = $this->orderQuery();
            // [Wave 3c KDS-ADV3C-01 P0 2026-05-18] TZ-aware month boundary —
            // see orderStatistics() comment. The user-supplied path uses raw
            // Y-m-d strings; the default-month path falls back to the current
            // Paris-local month (first day Y-m-01 .. last day Y-m-t).
            $appTz = config('app.timezone');
            if ($request->first_date && $request->last_date) {
                $firstDateParisDay = Carbon::parse($request->first_date, $appTz)->startOfDay();
                $lastDateParisDay = Carbon::parse($request->last_date, $appTz)->startOfDay();
            } else {
                $firstDateParisDay = Carbon::today($appTz)->startOfMonth();
                $lastDateParisDay = Carbon::today($appTz)->endOfMonth()->startOfDay();
            }
            $startUtc = $firstDateParisDay->copy()->setTimezone('UTC');
            $endUtcExclusive = $lastDateParisDay->copy()->addDay()->setTimezone('UTC');

            $first_date = $firstDateParisDay->toDateString();
            $last_date = $lastDateParisDay->toDateString();

            $orderSummaryArray = [];

            $apply = static function ($q) use ($startUtc, $endUtcExclusive) {
                return $q->where('order_datetime', '>=', $startUtc)
                         ->where('order_datetime', '<', $endUtcExclusive);
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
        // [Wave 3c KDS-ADV3C-01 P0 2026-05-18] TZ-aware month boundary — see
        // orderStatistics() comment for full rationale.
        $appTz = config('app.timezone');
        if ($request->first_date && $request->last_date) {
            $firstDateParisDay = Carbon::parse($request->first_date, $appTz)->startOfDay();
            $lastDateParisDay = Carbon::parse($request->last_date, $appTz)->startOfDay();
        } else {
            $firstDateParisDay = Carbon::today($appTz)->startOfMonth();
            $lastDateParisDay = Carbon::today($appTz)->endOfMonth()->startOfDay();
        }
        $startUtc = $firstDateParisDay->copy()->setTimezone('UTC');
        $endUtcExclusive = $lastDateParisDay->copy()->addDay()->setTimezone('UTC');
        $first_date = $firstDateParisDay->toDateString();
        $last_date = $lastDateParisDay->toDateString();

        $date = date_diff(date_create($first_date), date_create($last_date), false);
        $date_diff = (int) $date->format("%a");

        $total_sales = AppLibrary::flatAmountFormat(
            (clone $order)
                ->where('order_datetime', '>=', $startUtc)
                ->where('order_datetime', '<', $endUtcExclusive)
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
            // Per-day Paris range, converted to UTC TIMESTAMP boundaries.
            $dayStartUtc = Carbon::parse($dateRangeArray[$i], $appTz)->startOfDay()->setTimezone('UTC');
            $nextDayStartUtc = $dayStartUtc->copy()->addDay();
            $per_day = AppLibrary::flatAmountFormat(
                (clone $order)
                    ->where('order_datetime', '>=', $dayStartUtc)
                    ->where('order_datetime', '<', $nextDayStartUtc)
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
        // [Wave 3c KDS-ADV3C-01 P0 2026-05-18] TZ-aware month boundary — see
        // orderStatistics() comment for full rationale.
        $appTz = config('app.timezone');
        if ($request->first_date && $request->last_date) {
            $firstDateParisDay = Carbon::parse($request->first_date, $appTz)->startOfDay();
            $lastDateParisDay = Carbon::parse($request->last_date, $appTz)->startOfDay();
        } else {
            $firstDateParisDay = Carbon::today($appTz)->startOfMonth();
            $lastDateParisDay = Carbon::today($appTz)->endOfMonth()->startOfDay();
        }
        $startUtc = $firstDateParisDay->copy()->setTimezone('UTC');
        $endUtcExclusive = $lastDateParisDay->copy()->addDay()->setTimezone('UTC');

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
                ->where('order_datetime', '>=', $startUtc)
                ->where('order_datetime', '<', $endUtcExclusive)
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
            // [Wave 3c KDS-ADV3C-01 P0 2026-05-18] TZ-aware boundary — see
            // orderStatistics() comment. Paris-day range [startUtc, endUtc)
            // bound against UTC-stored TIMESTAMP column.
            $appTz = config('app.timezone');
            $startUtc = Carbon::today($appTz)->setTimezone('UTC');
            $endUtcExclusive = Carbon::tomorrow($appTz)->setTimezone('UTC');

            // Total CA du jour (Commandes payées)
            $daily_sales = $this->orderQuery()
                ->where('order_datetime', '>=', $startUtc)
                ->where('order_datetime', '<', $endUtcExclusive)
                ->where('payment_status', PaymentStatus::PAID)
                ->sum('total');

            // Nombre de commandes
            $daily_orders = $this->orderQuery()
                ->where('order_datetime', '>=', $startUtc)
                ->where('order_datetime', '<', $endUtcExclusive)
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
            // [Wave 3c KDS-ADV3C-01 P0 2026-05-18] TZ-aware Paris-day boundary
            // — see orderStatistics() comment.
            $appTz = config('app.timezone');
            $startUtc = Carbon::today($appTz)->setTimezone('UTC');
            $endUtcExclusive = Carbon::tomorrow($appTz)->setTimezone('UTC');
            $orders = $this->orderQuery()
                ->where('order_datetime', '>=', $startUtc)
                ->where('order_datetime', '<', $endUtcExclusive)
                ->get();
            $total = $orders->count();

            if ($total === 0) {
                return [
                    ['name' => 'Web', 'value' => 0],
                    ['name' => 'Kiosk/App', 'value' => 0],
                    ['name' => 'POS', 'value' => 0]
                ];
            }

            $web = $orders->where('source', \App\Enums\Source::WEB)->count();
            $app = $orders->where('source', \App\Enums\Source::APP)->count(); // Utilisé pour le kiosk
            $pos = $orders->where('source', \App\Enums\Source::POS)->count();

            return [
                ['name' => 'Web', 'value' => round(($web / $total) * 100, 2)],
                ['name' => 'Kiosk/App', 'value' => round(($app / $total) * 100, 2)],
                ['name' => 'POS', 'value' => round(($pos / $total) * 100, 2)]
            ];
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function auditTrail()
    {
        try {
            // [POS-9.1.4 + POS-9-H.1.3] Scope audit trail to the authenticated user's branch.
            //   - Admin (branch_id = 0) sees every branch.
            //   - Branch staff sees ONLY their own branch. Previously `orWhereNull('branch_id')`
            //     leaked every cross-tenant "system/admin" row to every branch (F-A3).
            //   - Any legacy row with branch_id = NULL is considered stale/system-only and
            //     must be surfaced exclusively to Admin. Branch managers should never see it.
            $actor = auth()->user();
            $actorBranchId = (int) ($actor?->branch_id ?? 0);

            $query = \App\Models\ActionLog::with('user');
            if ($actorBranchId > 0) {
                $query->where('branch_id', $actorBranchId);
            }

            return $query->orderBy('created_at', 'desc')
                ->limit(50)
                ->get()
                ->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'user_name' => $log->user ? $log->user->name : 'Système',
                        'branch_id' => $log->branch_id,
                        'action' => $log->action,
                        'resource' => $log->resource,
                        'details' => $log->details,
                        'time' => $log->created_at->diffForHumans(),
                    ];
                });
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
