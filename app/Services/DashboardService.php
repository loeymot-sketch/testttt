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
            // [DASH-SEM-02 heal 2026-06-01] Divide by the INCLUSIVE day count
            // (count($dateRangeArray) == date_diff + 1), not date_diff. A 7-day
            // range has date_diff=6 but spans 7 days; dividing by 6 overstated
            // the daily average by ~16%. count($dateRangeArray) is the same set
            // the per-day chart iterates, so the average matches the chart.
            $salesSummaryArray['total_sales'] = AppLibrary::currencyAmountFormat($total_sales);
            $salesSummaryArray['avg_per_day'] = AppLibrary::currencyAmountFormat($total_sales / count($dateRangeArray));
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

            // Nombre de commandes (volume placé — toutes, payées ou non)
            $daily_orders = $this->orderQuery()
                ->where('order_datetime', '>=', $startParis)
                ->where('order_datetime', '<', $endParisExclusive)
                ->count();

            // [GOAL-2026-05-30 H03-6] Ticket moyen = CA payé / commandes PAYÉES (même base que
            // daily_sales). Avant : daily_sales/daily_orders mélangeait argent-payé / commandes-toutes
            // → faussé, surtout depuis W-D1 (beaucoup d'orders restent PENDING_COUNTER au moment du
            // rapport car la cuisine prépare avant l'encaissement). daily_orders reste le volume placé.
            $daily_paid_orders = $this->orderQuery()
                ->where('order_datetime', '>=', $startParis)
                ->where('order_datetime', '<', $endParisExclusive)
                ->where('payment_status', PaymentStatus::PAID)
                ->count();

            // Ticket Moyen
            $average_ticket = $daily_paid_orders > 0 ? ($daily_sales / $daily_paid_orders) : 0;

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

    /**
     * [V102-08 HEAL-3 2026-05-26] One-click EOD synthesis for owner/accountant.
     *
     * Aggregates a single business day (Paris-local, branch-scoped) into the
     * KPI shape consumed by `pdf/eod_synthesis.blade.php`. Pure read-only
     * aggregation — NO fiscal sequence allocation, NO audit-log write, NO
     * chain HMAC touch. NF525 RO discipline (DM6).
     *
     * Reuses:
     *  - `dashboardBranchId()` for admin (cross-branch) vs staff (pinned)
     *  - Paris-local Carbon bounds (Wave T R5 / GOAL-G2-HEAL-04 pattern,
     *    session_tz=Paris face-value — DO NOT bind UTC literals).
     *
     * Payment buckets account for the dual-column reality (advisor flag):
     *   - POS orders: `pos_payment_method` (CASH=1, CARD=2, MOBILE=3,
     *     OTHER=4, TICKET_RESTAURANT=5, COUNTER_DEFERRED=6)
     *   - Web/Kiosk orders: `payment_method` (PaymentGateway::CASH_ON_DELIVERY
     *     =1, ONLINE/Stripe=3, CARD=4, TICKET_RESTAURANT=5).
     * Buckets are language-stable French labels (CB, Espèces, Titre-resto,
     * Mobile, Autre, En attente comptoir) — comptable-friendly, no i18n
     * round-trip in a PDF that may be archived for 6y NF525 retention.
     *
     * @param string|null $date Y-m-d Paris-local; null → today.
     * @return array{
     *   date:string, branch_label:string,
     *   total_ca:float, total_tva:float, total_orders:int,
     *   paid_orders:int, refunded_orders:int, avg_ticket:float,
     *   by_payment:array<int,array{label:string,count:int,total:float}>,
     *   top_items:array<int,array{name:string,qty:int,revenue:float}>,
     *   by_channel:array<int,array{label:string,count:int,total:float}>
     * }
     */
    public function eodSynthesis(?string $date = null): array
    {
        try {
            $appTz = config('app.timezone');
            $dayParis = $date
                ? Carbon::parse($date, $appTz)->startOfDay()
                : Carbon::today($appTz);
            $nextDayParis = $dayParis->copy()->addDay();
            $dateString = $dayParis->toDateString();

            $orderQuery = $this->orderQuery();
            $branchId = $this->dashboardBranchId();
            $branchLabel = $branchId === null
                ? 'Toutes branches'
                : ('Branche #' . $branchId);

            // All orders of the day (any status, any payment_status).
            $orders = (clone $orderQuery)
                ->where('order_datetime', '>=', $dayParis)
                ->where('order_datetime', '<', $nextDayParis)
                ->get();

            // Paid subset (CA + TVA + avg-ticket basis — refunds excluded).
            $paid = $orders->filter(fn ($o) => (int) $o->payment_status === PaymentStatus::PAID);
            $refunded = $orders->filter(fn ($o) => (int) $o->payment_status === PaymentStatus::REFUNDED);

            $totalCa = (float) $paid->sum('total');
            $totalTva = (float) $paid->sum('total_tax');
            $paidCount = $paid->count();
            $totalOrders = $orders->count();
            $refundedCount = $refunded->count();
            $avgTicket = $paidCount > 0 ? $totalCa / $paidCount : 0.0;

            $byPayment = $this->bucketPaymentMethods($paid);
            $byChannel = $this->bucketChannels($paid);
            $topItems = $this->topItemsOfDay($dayParis, $nextDayParis, $branchId);

            return [
                'date' => $dateString,
                'branch_label' => $branchLabel,
                'total_ca' => round($totalCa, 2),
                'total_tva' => round($totalTva, 2),
                'total_orders' => $totalOrders,
                'paid_orders' => $paidCount,
                'refunded_orders' => $refundedCount,
                'avg_ticket' => round($avgTicket, 2),
                'by_payment' => $byPayment,
                'by_channel' => $byChannel,
                'top_items' => $topItems,
            ];
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * Bucket paid orders by payment method into stable FR labels.
     * Discriminates POS (`pos_payment_method`) vs Web/Kiosk (`payment_method`).
     */
    private function bucketPaymentMethods($paid): array
    {
        $buckets = [
            'cash'        => ['label' => 'Espèces',           'count' => 0, 'total' => 0.0],
            'card'        => ['label' => 'Carte bancaire',    'count' => 0, 'total' => 0.0],
            'ticket'      => ['label' => 'Titre-restaurant',  'count' => 0, 'total' => 0.0],
            'mobile'      => ['label' => 'Paiement mobile',   'count' => 0, 'total' => 0.0],
            'online'      => ['label' => 'En ligne (Stripe)', 'count' => 0, 'total' => 0.0],
            'counter'     => ['label' => 'À encaisser comptoir', 'count' => 0, 'total' => 0.0],
            'other'       => ['label' => 'Autre',             'count' => 0, 'total' => 0.0],
        ];

        foreach ($paid as $order) {
            $key = $this->resolvePaymentBucketKey($order);
            $buckets[$key]['count']++;
            $buckets[$key]['total'] += (float) $order->total;
        }

        // Drop zero-count buckets, round totals, reindex.
        $out = [];
        foreach ($buckets as $bucket) {
            if ($bucket['count'] === 0) {
                continue;
            }
            $bucket['total'] = round($bucket['total'], 2);
            $out[] = $bucket;
        }

        return $out;
    }

    private function resolvePaymentBucketKey($order): string
    {
        $orderType = (int) ($order->order_type ?? 0);
        $isPosTender = $orderType === \App\Enums\OrderType::POS;

        if ($isPosTender) {
            $method = (int) ($order->pos_payment_method ?? 0);
            return match ($method) {
                \App\Enums\PosPaymentMethod::CASH               => 'cash',
                \App\Enums\PosPaymentMethod::CARD               => 'card',
                \App\Enums\PosPaymentMethod::MOBILE_BANKING     => 'mobile',
                \App\Enums\PosPaymentMethod::TICKET_RESTAURANT  => 'ticket',
                \App\Enums\PosPaymentMethod::COUNTER_DEFERRED   => 'counter',
                \App\Enums\PosPaymentMethod::OTHER              => 'other',
                default                                         => 'other',
            };
        }

        $method = (int) ($order->payment_method ?? 0);
        return match ($method) {
            \App\Enums\PaymentGateway::CASH_ON_DELIVERY   => 'cash',
            \App\Enums\PaymentGateway::CARD               => 'card',
            \App\Enums\PaymentGateway::TICKET_RESTAURANT  => 'ticket',
            default                                       => 'online', // Stripe/online fallback
        };
    }

    private function bucketChannels($paid): array
    {
        $kiosk = ['label' => 'Borne', 'count' => 0, 'total' => 0.0];
        $pos   = ['label' => 'POS Caisse', 'count' => 0, 'total' => 0.0];
        $web   = ['label' => 'Web/App', 'count' => 0, 'total' => 0.0];

        foreach ($paid as $order) {
            // Reuse channelStatistics() discriminator: source_surface='kiosk'
            // is the canonical kiosk marker (WG-3 WF-3 P1 2026-05-19).
            if ((string) ($order->source_surface ?? '') === 'kiosk'
                || (int) $order->source === \App\Enums\Source::APP) {
                $kiosk['count']++;
                $kiosk['total'] += (float) $order->total;
                continue;
            }
            if ((int) $order->source === \App\Enums\Source::POS) {
                $pos['count']++;
                $pos['total'] += (float) $order->total;
                continue;
            }
            $web['count']++;
            $web['total'] += (float) $order->total;
        }

        $out = [];
        foreach ([$kiosk, $pos, $web] as $bucket) {
            if ($bucket['count'] === 0) {
                continue;
            }
            $bucket['total'] = round($bucket['total'], 2);
            $out[] = $bucket;
        }

        return $out;
    }

    /**
     * Top 5 items of the day by quantity sold (paid orders only).
     * Uses OrderItem aggregation with branch scope already baked in
     * (OrderItem::booted() applies BranchScope as of P0-FIX-2 2026-05-09).
     */
    private function topItemsOfDay(Carbon $startParis, Carbon $endParisExclusive, ?int $branchId): array
    {
        $itemQuery = \App\Models\OrderItem::query()
            ->select('item_id', 'tax_name')
            ->selectRaw('SUM(quantity) AS qty_sum')
            ->selectRaw('SUM(total_price) AS revenue_sum')
            ->whereHas('order', function ($q) use ($startParis, $endParisExclusive, $branchId) {
                $q->where('order_datetime', '>=', $startParis)
                  ->where('order_datetime', '<', $endParisExclusive)
                  ->where('payment_status', PaymentStatus::PAID);
                if ($branchId !== null) {
                    $q->where('branch_id', $branchId);
                }
            })
            ->groupBy('item_id', 'tax_name')
            ->orderByDesc('qty_sum')
            ->limit(5);

        $rows = $itemQuery->get();

        // Resolve item names in one round-trip (avoid N+1).
        $itemIds = $rows->pluck('item_id')->filter()->unique()->all();
        $names = $itemIds
            ? \App\Models\Item::whereIn('id', $itemIds)->pluck('name', 'id')
            : collect();

        return $rows->map(function ($row) use ($names) {
            return [
                'name' => $names[$row->item_id] ?? ('Item #' . $row->item_id),
                'qty' => (int) $row->qty_sum,
                'revenue' => round((float) $row->revenue_sum, 2),
            ];
        })->values()->all();
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
