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

            if ($request->first_date && $request->last_date) {
                $first_date = Date('Y-m-d', strtotime($request->first_date));
                $last_date = Date('Y-m-d', strtotime($request->last_date));
            } else {
                $first_date = Carbon::today()->toDateString();
                $last_date = Carbon::today()->toDateString();
            }

            $orderStatisticsArray = [];

            $orderStatisticsArray["total_order"] = (clone $order)->whereDate('order_datetime', '>=', $first_date)->whereDate('order_datetime', '<=', $last_date)->count();
            $orderStatisticsArray["pending_order"] = (clone $order)->pending()->whereDate('order_datetime', '>=', $first_date)->whereDate('order_datetime', '<=', $last_date)->count();
            $orderStatisticsArray["accept_order"] = (clone $order)->accept()->whereDate('order_datetime', '>=', $first_date)->whereDate('order_datetime', '<=', $last_date)->count();
            $orderStatisticsArray["preparing_order"] = (clone $order)->preparing()->whereDate('order_datetime', '>=', $first_date)->whereDate('order_datetime', '<=', $last_date)->count();
            $orderStatisticsArray["prepared_order"] = (clone $order)->prepared()->whereDate('order_datetime', '>=', $first_date)->whereDate('order_datetime', '<=', $last_date)->count();
            $orderStatisticsArray["out_for_delivery_order"] = (clone $order)->outForDelivery()->whereDate('order_datetime', '>=', $first_date)->whereDate('order_datetime', '<=', $last_date)->count();
            $orderStatisticsArray["delivered_order"] = (clone $order)->delivered()->whereDate('order_datetime', '>=', $first_date)->whereDate('order_datetime', '<=', $last_date)->count();
            $orderStatisticsArray["canceled_order"] = (clone $order)->canceled()->whereDate('order_datetime', '>=', $first_date)->whereDate('order_datetime', '<=', $last_date)->count();
            $orderStatisticsArray["returned_order"] = (clone $order)->returned()->whereDate('order_datetime', '>=', $first_date)->whereDate('order_datetime', '<=', $last_date)->count();
            $orderStatisticsArray["rejected_order"] = (clone $order)->rejected()->whereDate('order_datetime', '>=', $first_date)->whereDate('order_datetime', '<=', $last_date)->count();

            return $orderStatisticsArray;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }


    public function orderSummary(Request $request)
    {
        try {
            $order = $this->orderQuery();
            if ($request->first_date && $request->last_date) {
                $first_date = Date('Y-m-d', strtotime($request->first_date));
                $last_date = Date('Y-m-d', strtotime($request->last_date));
            } else {
                $first_date = Date('Y-m-01', strtotime(Carbon::today()->toDateString()));
                $last_date = Date('Y-m-t', strtotime(Carbon::today()->toDateString()));
            }

            $orderSummaryArray = [];

            $total_order = (clone $order)->whereDate('order_datetime', '>=', $first_date)->whereDate('order_datetime', '<=', $last_date)->count();
            $total_delivered = (clone $order)->delivered()->whereDate('order_datetime', '>=', $first_date)->whereDate('order_datetime', '<=', $last_date)->count();
            $total_canceled = (clone $order)->canceled()->whereDate('order_datetime', '>=', $first_date)->whereDate('order_datetime', '<=', $last_date)->count();
            $total_returned = (clone $order)->returned()->whereDate('order_datetime', '>=', $first_date)->whereDate('order_datetime', '<=', $last_date)->count();
            $total_rejected = (clone $order)->rejected()->whereDate('order_datetime', '>=', $first_date)->whereDate('order_datetime', '<=', $last_date)->count();


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
        if ($request->first_date && $request->last_date) {
            $first_date = Date('Y-m-d', strtotime($request->first_date));
            $last_date = Date('Y-m-d', strtotime($request->last_date));
        } else {
            $first_date = Date('Y-m-01', strtotime(Carbon::today()->toDateString()));
            $last_date = Date('Y-m-t', strtotime(Carbon::today()->toDateString()));
        }

        $date = date_diff(date_create($first_date), date_create($last_date), false);
        $date_diff = (int) $date->format("%a");

        $total_sales = AppLibrary::flatAmountFormat((clone $order)->whereDate('order_datetime', '>=', $first_date)->whereDate('order_datetime', '<=', $last_date)->where('payment_status', PaymentStatus::PAID)->sum('total'));

        $dateRangeArray = [];
        for ($currentDate = strtotime($first_date); $currentDate <= strtotime($last_date); $currentDate += (86400)) {

            $date = date('Y-m-d', $currentDate);
            $dateRangeArray[] = $date;
        }

        $dateRangeValueArray = [];
        for ($i = 0; $i <= count($dateRangeArray) - 1; $i++) {
            $per_day = AppLibrary::flatAmountFormat((clone $order)->whereDate('order_datetime', $dateRangeArray[$i])->where('payment_status', PaymentStatus::PAID)->sum('total'));
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
        if ($request->first_date && $request->last_date) {
            $first_date = Date('Y-m-d', strtotime($request->first_date));
            $last_date = Date('Y-m-d', strtotime($request->last_date));
        } else {
            $first_date = Date('Y-m-01', strtotime(Carbon::today()->toDateString()));
            $last_date = Date('Y-m-t', strtotime(Carbon::today()->toDateString()));
        }

        $timeArray = ["06:00", "07:00", "08:00", "09:00", "10:00", "11:00", "12:00", "13:00", "14:00", "15:00", "16:00", "17:00", "18:00", "19:00", "20:00", "21:00", "22:00", "23:00"];

        $customerSateArray = [];
        $totalCustomerArray = [];
        $first_time = "";
        $last_time = "";
        for ($i = 0; $i <= count($timeArray) - 1; $i++) {
            $first_time = date('H:i', strtotime($timeArray[$i]));
            $last_time = date('H:i', strtotime($timeArray[$i] . ' +59 minutes'));

            $total_customer = (clone $order)->whereDate('order_datetime', '>=', $first_date)->whereDate('order_datetime', '<=', $last_date)->whereTime('order_datetime', '>=', Carbon::parse($first_time))->whereTime('order_datetime', '<=', Carbon::parse($last_time))->get()->count();
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
            $today = Carbon::today()->toDateString();

            // Total CA du jour (Commandes payées)
            $daily_sales = $this->orderQuery()
                ->whereDate('order_datetime', $today)
                ->where('payment_status', PaymentStatus::PAID)
                ->sum('total');

            // Nombre de commandes
            $daily_orders = $this->orderQuery()->whereDate('order_datetime', $today)->count();

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
            $today = Carbon::today()->toDateString();
            $orders = $this->orderQuery()->whereDate('order_datetime', $today)->get();
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
