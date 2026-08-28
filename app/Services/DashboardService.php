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
            // [DASH-SEM-03 heal 2026-06-01] Exclude refund counter-entry mirrors
            // (parent_order_id set) from PLACED-order counts — a mirror is a fiscal
            // counter-entry, not a customer order; counting it inflated total_order
            // and returned_order.
            $order = $this->orderQuery()->whereNull('parent_order_id');

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

            // [TERRAIN-HEAL 2026-07-16 · PERF-DASHBOARD-STATUS-COUNTS] Avant : 10 requêtes COUNT séquentielles
            // (total + 1 par statut), chacune re-scannant la même fenêtre order_datetime → 10 aller-retours SQL
            // à chaque affichage/refresh du dashboard admin. Remplacé par UN SEUL agrégat GROUP BY status
            // (mêmes clauses : parent_order_id IS NULL + fenêtre Paris). Sémantique STRICTEMENT identique :
            // total_order = somme de TOUS les statuts présents (= count() de toutes les lignes non-miroir),
            // chaque *_order = count du statut (0 si absent). Clés castées int (robuste au type PDO du driver).
            $byStatus = [];
            foreach ($apply(clone $order)->selectRaw('status, COUNT(*) as cnt')->groupBy('status')->get() as $row) {
                $byStatus[(int) $row->status] = (int) $row->cnt;
            }

            $orderStatisticsArray["total_order"]            = array_sum($byStatus);
            $orderStatisticsArray["pending_order"]          = $byStatus[OrderStatus::PENDING] ?? 0;
            $orderStatisticsArray["accept_order"]           = $byStatus[OrderStatus::ACCEPT] ?? 0;
            $orderStatisticsArray["preparing_order"]        = $byStatus[OrderStatus::PREPARING] ?? 0;
            $orderStatisticsArray["prepared_order"]         = $byStatus[OrderStatus::PREPARED] ?? 0;
            $orderStatisticsArray["out_for_delivery_order"] = $byStatus[OrderStatus::OUT_FOR_DELIVERY] ?? 0;
            $orderStatisticsArray["delivered_order"]        = $byStatus[OrderStatus::DELIVERED] ?? 0;
            $orderStatisticsArray["canceled_order"]         = $byStatus[OrderStatus::CANCELED] ?? 0;
            $orderStatisticsArray["returned_order"]         = $byStatus[OrderStatus::RETURNED] ?? 0;
            $orderStatisticsArray["rejected_order"]         = $byStatus[OrderStatus::REJECTED] ?? 0;

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
            // [DASH-SEM-03 heal 2026-06-01] Exclude refund counter-entry mirrors from counts.
            $order = $this->orderQuery()->whereNull('parent_order_id');
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

            // [TERRAIN-HEAL 2026-07-16 · PERF-DASHBOARD-STATUS-COUNTS] Idem orderStatistics : 5 COUNT séquentiels
            // → 1 agrégat GROUP BY status. total_order = somme de tous les statuts (= count() original), les
            // scalaires par statut alimentent les pourcentages ci-dessous à l'identique.
            $byStatus = [];
            foreach ($apply(clone $order)->selectRaw('status, COUNT(*) as cnt')->groupBy('status')->get() as $row) {
                $byStatus[(int) $row->status] = (int) $row->cnt;
            }
            $total_order     = array_sum($byStatus);
            $total_delivered = $byStatus[OrderStatus::DELIVERED] ?? 0;
            $total_canceled  = $byStatus[OrderStatus::CANCELED] ?? 0;
            $total_returned  = $byStatus[OrderStatus::RETURNED] ?? 0;
            $total_rejected  = $byStatus[OrderStatus::REJECTED] ?? 0;


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

        // [DASH-NET-01 heal 2026-06-01] Net realized revenue (owner: "agree with the Z").
        // realizedRevenue() drops cancelled-but-paid orders and includes the negative
        // refund counter-entry mirrors so a refunded sale nets to ~0.
        $total_sales = AppLibrary::flatAmountFormat(
            (clone $order)
                ->where('order_datetime', '>=', $startParis)
                ->where('order_datetime', '<', $endParisExclusive)
                ->realizedRevenue()
                ->sum('total')
        );

        $dateRangeArray = [];
        for ($currentDate = strtotime($first_date); $currentDate <= strtotime($last_date); $currentDate += (86400)) {

            $date = date('Y-m-d', $currentDate);
            $dateRangeArray[] = $date;
        }

        // [NUIT-A 2026-07-03 / P3 perf] UNE seule requête GROUP BY au lieu d'un SUM par jour (jusqu'à 365
        // aller-retours/an). `realizedRevenue()` = where-clauses (nested where + orWhere miroir RETURNED) →
        // compose avec DATE()+GROUP BY. session_tz=Paris (même hypothèse face-value que la borne actuelle) →
        // DATE(order_datetime) = date Paris-locale, alignée sur les bornes jour de la boucle d'origine.
        // On récupère via get() (pas pluck, qui écraserait le selectRaw des alias) puis on mappe par date.
        $perDayRows = (clone $order)
            ->where('order_datetime', '>=', $startParis)
            ->where('order_datetime', '<', $endParisExclusive)
            ->realizedRevenue()
            ->groupBy(\Illuminate\Support\Facades\DB::raw('DATE(order_datetime)'))
            ->selectRaw('DATE(order_datetime) as d, SUM(total) as t')
            ->get();
        $perDayTotals = [];
        foreach ($perDayRows as $row) {
            $perDayTotals[(string) $row->d] = $row->t;
        }

        $dateRangeValueArray = [];
        foreach ($dateRangeArray as $date) {
            $dateRangeValueArray[] = floatval(AppLibrary::flatAmountFormat($perDayTotals[$date] ?? 0));
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
                // [PERF 2026-06-27] COUNT(*) SQL au lieu de ->get()->count() qui hydratait
                // ~2184 modèles Order ×18 créneaux par chargement dashboard. Résultat identique,
                // logique TZ Paris (whereTime non-converti, backlog KDS-ADV3C-12) intacte. R4 perf.
                ->count();
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
                    // [topCustomers heal 2026-06-01] Exclude refund counter-entry mirrors
                    // so a refunded customer is not credited an extra "order".
                    $query->whereNull('parent_order_id');
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

    /**
     * [T-5.2 CUMUL-NON-DATE 2026-08-15 · GOAL_CONFORT_MAX] `$period='all'`
     * (défaut) préserve EXACTEMENT le comportement historique — cumul depuis
     * toujours, comme avant, pour tout appelant qui ne passe pas ce paramètre.
     * `$period='today'` scope sur `business_date` (le jour FISCAL, pas minuit
     * UTC — Le Cayenne sert jusqu'à 00h30, cf. config/kds.php, un même service
     * du soir ne doit pas être coupé en deux jours). Utilisé par la tuile
     * "Ventes du jour" du dashboard (OverviewComponent.vue).
     */
    private function scopePeriod($query, string $period)
    {
        if ($period === 'today') {
            $this->scopeJourMetier($query, now()->toDateString());
        }

        return $query;
    }

    /**
     * [AUDIT-COMPTA 2026-08-29] LE repère de « aujourd'hui », défini UNE fois.
     *
     * Deux tuiles du tableau de bord affichaient le chiffre d'affaires du jour avec deux
     * repères de date différents : « Ventes du jour » sur `business_date`, « Chiffre
     * d'Affaires du Jour » sur `order_datetime`. Mesuré sur la base réelle au 28/05/2026 :
     * **1 494,00 €** contre **1 598,90 €** — 104,90 € d'écart, sur le même écran, pour ce
     * qu'un exploitant lit comme le même fait.
     *
     * Deux défauts distincts se cumulaient :
     *
     * 1. `where('business_date', ...)` fait DISPARAÎTRE les commandes dont la date métier
     *    est nulle — 167 sur 3252 en base, jusqu'à 21 sur 162 certains jours. Un chiffre
     *    d'affaires amputé ressemble à une journée creuse : c'est une fausse alerte
     *    d'exploitation, et c'est le chiffre qui déclenche une action.
     * 2. Les deux tuiles ne partageaient aucune définition, donc rien ne les forçait à
     *    rester d'accord.
     *
     * Le repli garde l'intention d'origine, qui est la bonne : le jour FISCAL, parce que le
     * service du soir va jusqu'à 00h30 et ne doit pas être coupé en deux (cf. `config/kds.php`).
     * Quand la date métier existe, elle fait foi ; quand elle manque, on retombe sur
     * l'horodatage plutôt que de perdre la commande.
     *
     * `DATE()` et `COALESCE()` se comportent de la même façon sous MySQL et SQLite — les
     * bancs tournent sur SQLite en mémoire.
     */
    private function scopeJourMetier($query, string $jour)
    {
        return $query->whereRaw('COALESCE(business_date, DATE(order_datetime)) = ?', [$jour]);
    }

    public function totalSales(string $period = 'all')
    {
        try {
            // [DASH-NET-01 heal 2026-06-01] Net realized revenue (excl cancelled-paid, net refunds).
            return $this->scopePeriod($this->orderQuery()->realizedRevenue(), $period)->sum('total');
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function totalOrders(string $period = 'all')
    {
        try {
            // [DASH-01 heal 2026-06-01, owner "do the goal"] The "Total commandes" KPI must
            // reflect REAL order volume — it previously counted status=DELIVERED only (e.g. 3
            // vs 1755 placed/day), misleading under that label. Count all PLACED orders,
            // excluding refund counter-entry mirrors (parent_order_id). The per-status
            // breakdown stays in orderStatistics; this headline is total volume.
            return $this->scopePeriod($this->orderQuery()->whereNull('parent_order_id'), $period)->count();
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

    /**
     * [AUDIT-COMPTA 2026-08-29] « Total articles menu » compte le MENU, pas les lignes.
     *
     * Mesuré à l'écran le 2026-08-29 : le tableau de bord annonçait **123** quand le
     * catalogue, deux clics plus loin, affichait **59 produits** — le même fait, deux
     * nombres. `Item::count()` comptait toute la table : les 59 articles actifs, les 64
     * désactivés, et 17 fiches de test. Un commerçant lit « mon menu a 123 articles »
     * alors qu'il en sert 59.
     *
     * Les compteurs d'argent voisins avaient déjà reçu ce traitement — `totalSales`
     * (DASH-NET-01) et `totalOrders` (DASH-01), tous deux le 2026-06-01. Celui-ci avait été
     * oublié dans la passe.
     *
     * On aligne donc sur la définition du catalogue (`ItemService.php:159` et `:281`,
     * `where('status', Status::ACTIVE)`) : même fait, même source. Le bandeau catalogue
     * ferme d'ailleurs son arithmétique — 58 actifs + 1 indisponible = 59 produits.
     */
    public function totalMenuItems()
    {
        try {
            return Item::query()->where('status', \App\Enums\Status::ACTIVE)->count();
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

            // [AUDIT-COMPTA 2026-08-29] Même repère que la tuile « Ventes du jour ».
            // Les deux affichaient le chiffre d'affaires du jour sur deux axes de date
            // différents : 1 494,00 EUR contre 1 598,90 EUR le 28/05/2026, sur le même
            // écran. Elles partagent désormais `scopeJourMetier` — une seule définition,
            // donc plus de divergence possible.
            $jourMetier = Carbon::today($appTz)->toDateString();

            // Total CA du jour — net réalisé (DASH-NET-01: excl annulées-payées, remboursements nettés)
            $daily_sales = $this->orderQuery()
                ->whereRaw('COALESCE(business_date, DATE(order_datetime)) = ?', [$jourMetier])
                ->realizedRevenue()
                ->sum('total');

            // Nombre de commandes (volume placé — toutes, payées ou non ; hors contre-écritures de remboursement)
            $daily_orders = $this->orderQuery()
                ->whereNull('parent_order_id')
                ->whereRaw('COALESCE(business_date, DATE(order_datetime)) = ?', [$jourMetier])
                ->count();

            // [GOAL-2026-05-30 H03-6] Ticket moyen = CA payé / commandes PAYÉES (même base que
            // daily_sales). Avant : daily_sales/daily_orders mélangeait argent-payé / commandes-toutes
            // → faussé, surtout depuis W-D1 (beaucoup d'orders restent PENDING_COUNTER au moment du
            // rapport car la cuisine prépare avant l'encaissement). daily_orders reste le volume placé.
            $daily_paid_orders = $this->orderQuery()
                ->whereRaw('COALESCE(business_date, DATE(order_datetime)) = ?', [$jourMetier])
                ->where('payment_status', PaymentStatus::PAID)
                // [REFUND-02 2026-07-15] Exclure les statuts terminaux (annulée/refusée/retournée)
                // du DÉNOMINATEUR du ticket moyen — le numérateur daily_sales utilise déjà
                // realizedRevenue() (net). Sans ça, une commande PAYÉE puis ANNULÉE gonflait le
                // nombre de commandes → ticket moyen sous-estimé.
                ->whereNotIn('status', [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED])
                // [ONB-07 2026-08-28] Le NUMÉRATEUR passe par `realizedRevenue()`, qui
                // exclut le canal Uber (non fiscalisé par conception,
                // `Order::isRealizedRevenueRow`). Le DÉNOMINATEUR ne l'excluait pas :
                // on divisait un chiffre d'affaires SANS Uber par un nombre de
                // commandes AVEC Uber.
                //
                // Mesuré sur la base réelle au 14/08 : 154,65 € ÷ 17 = « Ticket moyen
                // 9,10 € », là où le dénominateur cohérent (7) donne 22,09 €. Une
                // sous-évaluation de 59 % sur le chiffre qui sert à décider d'une
                // hausse de prix ou d'un menu.
                ->where(function ($q): void {
                    $q->whereNull('source_surface')
                        ->orWhere('source_surface', '!=', 'uber_eats');
                })
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
            // [ONB-07 T-2.1.1 2026-08-27] La fenetre est bornee DES DEUX COTES.
            //
            // Avant : une seule borne — `updated_at < maintenant - 15 minutes`. Toute
            // commande jamais sortie de l'etat « en preparation », depuis le premier
            // jour, restait donc une alerte. Mesure a l'ecran avant correctif :
            // « 331 Alerte(s) », dont un ticket « en attente depuis 77 j 22 h ».
            //
            // Une alerte qui se declenche 331 fois ne se declenche plus : le cuisinier
            // apprend a ne plus la regarder, et la seule vraie urgence se noie dans le
            // bruit. Un compteur d'alertes n'a de valeur que s'il peut retomber a zero.
            //
            // La borne haute est reglable et vaut 24 heures par defaut : au-dela, une
            // commande n'est plus en retard, elle est abandonnee. Ce n'est plus une
            // alerte de service, c'est du menage a faire.
            //
            // [ONB-10 2026-08-27] Les deux cles ci-dessous sont celles de
            // `config/dashboard.php`, ecrit par le GOAL CONSOLIDATION_V1_PRODUCTION du
            // 2026-08-25 (meme diagnostic, mesure de 344 commandes contre 331 ici) et
            // encore NON COMMITE dans l'arbre principal. Je lisais auparavant
            // `dashboard.sla.fenetre_heures` — une cle que ce fichier ne definit pas :
            // ma borne se disait reglable sans l'etre, elle retombait toujours sur 24.
            // Alignees ici pour que les deux travaux convergent au lieu de forker deux
            // conventions. Le fichier peut ne pas exister : les valeurs par defaut font
            // foi, et c'est le cas tant qu'il n'est pas commite.
            $fenetreHeures = (int) config('dashboard.sla_alerts_window_hours', 24);
            $seuilMinutes  = (int) config('dashboard.sla_alerts_threshold_minutes', 15);

            $borneHaute = Carbon::now()->subMinutes($seuilMinutes); // en retard depuis 15 min
            $borneBasse = Carbon::now()->subHours($fenetreHeures);  // mais pas depuis des jours

            $alerts = $this->orderQuery()
                ->where('status', OrderStatus::PREPARING)
                ->where('updated_at', '<', $borneHaute)
                ->where('updated_at', '>=', $borneBasse)
                ->with('user')
                // Les plus recentes d'abord : c'est celle de tout a l'heure qu'un
                // cuisinier doit voir en premier, pas celle d'hier soir.
                ->orderBy('updated_at', 'desc')
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
            // [DASH-SEM-04 heal 2026-06-01] Exclude refund counter-entry mirrors
            // (parent_order_id set, source NULL) — they are not placed orders and
            // were mis-bucketed into 'Web', skewing the channel percentages.
            $orders = $this->orderQuery()
                ->whereNull('parent_order_id')
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

            // [F-DONUT-CATCHALL 2026-07-15 / P2] « Web » = catch-all online = complément EXACT
            // de (kiosk ∪ pos), en miroir des DEUX prédicats ci-dessus/dessous (surface OU source
            // entier legacy). Sans ce fourre-tout, toute commande ni kiosk ni pos (livraison
            // source_surface='delivery' source null, rangs legacy sans surface) était dans $total
            // mais dans aucun des 3 comptes → la somme des tranches du donut < 100 %. On exclut
            // kiosk/pos par leurs prédicats complets (pas seulement source_surface) pour ne PAS
            // capturer par erreur les commandes POS/APP taguées uniquement via l'entier `source`.
            $webCount = $orders->filter(function ($order) {
                $surface = strtolower((string) ($order->source_surface ?? ''));
                $source = (int) $order->source;
                $isKiosk = $surface === 'kiosk' || $source === \App\Enums\Source::APP;
                $isPos = $surface === 'pos' || $source === \App\Enums\Source::POS;

                return ! $isKiosk && ! $isPos;
            })->count();

            // [CAISSE-LOGIC-HEAL 2026-07-11 reports-F1] Le canal fiable est `source_surface`,
            // PAS l'entier `source` : les ventes caisse posent source_surface='pos' mais
            // source=1 (OrderService ne renseigne jamais l'entier legacy) → l'ancien
            // `where('source', POS=15)` en ratait ~1356 → répartition ne sommait pas à 100 %
            // (POS massivement sous-compté). On route sur source_surface, source en repli.
            $posCount = $orders->filter(function ($order) {
                if (strtolower((string) ($order->source_surface ?? '')) === 'pos') {
                    return true;
                }

                return (int) $order->source === \App\Enums\Source::POS;
            })->count();

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

            // [DASH-NET-01 heal 2026-06-01] Net realized set (owner: "agree with the Z").
            // Live paid sales (PAID, non-terminal) PLUS counter-entry refund mirrors
            // (RETURNED + parent_order_id, already-negated total/total_tax) → a refunded
            // order nets to ~0 and a cancelled-but-paid order drops out. Mirrors the
            // Order::scopeRealizedRevenue scope used by the live dashboard queries.
            // [ONB-07 2026-08-28] Ce prédicat était RECOPIÉ à la main, et sa copie
            // OMETTAIT l'exclusion du canal Uber que porte l'original
            // (`Order::isRealizedRevenueRow`, `Order.php:375` :
            // `source_surface !== 'uber_eats'`). Le commentaire ci-dessus affirmait
            // pourtant « Mirrors the Order::scopeRealizedRevenue scope ».
            //
            // Conséquence, mesurée sur la base réelle : le PDF « Clôture du jour » du
            // 14/08 annonçait **413,38 €** de chiffre d'affaires quand l'écran, le
            // rapport des ventes et le Z signé en comptaient **154,65 €**. Le 12/08 :
            // 137,00 € contre 0,00 €. C'est le document remis au comptable et archivé
            // six ans : son CA ET sa TVA étaient surévalués du montant Uber, déjà
            // facturé séparément par l'agrégateur — donc déclaré deux fois.
            //
            // On appelle la règle au lieu de la recopier. Une copie ne suit pas les
            // corrections de l'original — c'est exactement ce qui s'est passé ici.
            $realized = $orders->filter(fn ($o) => \App\Models\Order::isRealizedRevenueRow($o));
            $refunded = $orders->filter(fn ($o) => (int) $o->payment_status === PaymentStatus::REFUNDED);

            $totalCa = (float) $realized->sum('total');
            $totalTva = (float) $realized->sum('total_tax');
            // avg-ticket basis = count of live paid sales only (exclude the negative mirrors).
            $paidCount = $realized->filter(fn ($o) => $o->parent_order_id === null)->count();
            // Placed-order volume excludes refund counter-entry mirrors.
            $totalOrders = $orders->filter(fn ($o) => $o->parent_order_id === null)->count();
            $refundedCount = $refunded->count();
            $avgTicket = $paidCount > 0 ? $totalCa / $paidCount : 0.0;

            // Buckets use the net realized set so by-payment / by-channel CA sums to total_ca.
            $byPayment = $this->bucketPaymentMethods($realized);
            $byChannel = $this->bucketChannels($realized);
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
        // [F-EOD-TENDER-PRECEDENCE 2026-07-15 / P1] Miroir EXACT de la précédence du Z
        // (ZReportService::applyOrderToTotals:792 `pos_payment_method ?: payment_method`).
        // Le Cayenne tourne Plan B (kiosk.payment_route_all_to_counter=true) : une commande
        // borne reste order_type=TAKEAWAY/KIOSK mais confirmCounterPayment écrit le VRAI tender
        // (carte/TR/mobile) dans pos_payment_method au comptoir. L'ancien garde `order_type===POS`
        // ignorait ce champ → toute carte/TR/mobile Plan B tombait en 'cash' → le PDF EOD (NF525,
        // archivé 6 ans, commenté « agree with the Z ») CONTREDISAIT le Z. On préfère désormais
        // pos_payment_method dès qu'il est renseigné (>0), quel que soit order_type. Non-régression :
        // une vente POS pure (order_type=15) porte déjà pos_payment_method → bucket identique.
        $posMethod = (int) ($order->pos_payment_method ?? 0);
        if ($posMethod > 0) {
            return match ($posMethod) {
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
            // [CAISSE-LOGIC-HEAL 2026-07-11 reports-F2] Le canal fiable est `source_surface`,
            // pas l'entier `source`. Avant, une vente caisse (source_surface='pos' mais
            // source=1) échappait au bucket POS (`source===POS=15`) et tombait dans le
            // catch-all `web` → ~1356 ventes CAISSE étiquetées « Web/App » dans la synthèse
            // EOD (PDF owner/comptable, archivé 6 ans NF525). On route sur source_surface.
            $surface = strtolower((string) ($order->source_surface ?? ''));
            if ($surface === 'kiosk' || (int) $order->source === \App\Enums\Source::APP) {
                $kiosk['count']++;
                $kiosk['total'] += (float) $order->total;
                continue;
            }
            if ($surface === 'pos' || (int) $order->source === \App\Enums\Source::POS) {
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
                  ->where('payment_status', PaymentStatus::PAID)
                  // [REFUND-02 2026-07-15] Ne pas gonfler le Top produits avec des commandes
                  // payées puis annulées/refusées/retournées (ventes non réalisées).
                  ->whereNotIn('status', [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED])
                  // [ONB-07 2026-08-28] L'EXCLUSION UBER MANQUAIT ICI.
                  //
                  // Le CA imprimé au-dessus passe par `Order::isRealizedRevenueRow`,
                  // qui écarte `source_surface = 'uber_eats'` (déjà facturé par
                  // l'agrégateur, non fiscalisé par design). Le Top 5, lui, recopiait
                  // le prédicat à la main et omettait cette clause.
                  //
                  // Mesure du 14/08 : 17 commandes retenues par le Top 5 contre 7
                  // pour le CA. Le document remis au comptable présentait donc deux
                  // populations différentes sous deux titres voisins.
                  //
                  // C'est le jumeau du défaut corrigé ligne 737 le même jour — et le
                  // commentaire posé là-bas prévenait exactement de ça : « une copie
                  // ne suit pas les corrections de l'original ». Elle ne l'a pas suivi.
                  ->where(function ($u) {
                      $u->whereNull('source_surface')
                        ->orWhere('source_surface', '!=', 'uber_eats');
                  });
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
