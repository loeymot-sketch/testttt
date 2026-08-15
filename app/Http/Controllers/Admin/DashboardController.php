<?php

namespace App\Http\Controllers\Admin;

use App\Http\Resources\SimpleItemResource;
use App\Http\Resources\TopCustomerResource;
use Exception;
use App\Libraries\AppLibrary;
use App\Services\ItemService;
use App\Services\DashboardService;
use App\Http\Resources\ItemResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use App\Http\Resources\OrderSummaryResource;
use App\Http\Resources\SalesSummaryResource;
use App\Http\Resources\CustomerStatesResource;
use App\Http\Resources\OrderStatisticsResource;
use App\Models\ThemeSetting;
use App\Services\CompanyService;
use Smartisan\Settings\Facades\Settings;
use Barryvdh\DomPDF\Facade\Pdf;

class DashboardController extends AdminController
{
    private DashboardService $dashboardService;
    private ItemService $itemService;
    private CompanyService $companyService;

    public function __construct(
        DashboardService $dashboardService,
        ItemService $itemService,
        CompanyService $companyService
    ) {
        parent::__construct();
        $this->dashboardService = $dashboardService;
        $this->itemService = $itemService;
        $this->companyService = $companyService;
        $this->middleware(['permission:dashboard'])->only(
            'orderStatistics',
            'orderSummary',
            'featuredItems',
            'mostPopularItems',
            'topCustomers',
            'totalSales',
            'salesSummary',
            'customerStates',
            'totalOrders',
            'totalCustomers',
            'totalMenuItems',
            'realtimeReport',
            'slaAlerts',
            'channelStatistics',
            'auditTrail'
        );
        // [V102-08 HEAL-3 2026-05-26] EOD PDF recap requires fiscal-grade
        // permission because the output aggregates daily revenue (CA + TVA +
        // payment-method breakdown) — the same data scope as Z-report close.
        // Separate middleware line (NOT merged into permission:dashboard) so
        // a user with only :dashboard cannot pull a fiscal synthesis.
        $this->middleware(['permission:pos-manage-fiscal'])->only('eodPdf');
    }

    public function totalSales(Request $request): \Illuminate\Http\Response|array|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            // [T-5.2 CUMUL-NON-DATE 2026-08-15] period=all par défaut = comportement
            // historique inchangé pour tout appelant qui n'envoie pas ce paramètre.
            $period = $request->query('period') === 'today' ? 'today' : 'all';
            return ['data' => ['total_sales' => AppLibrary::currencyAmountFormat($this->dashboardService->totalSales($period))]];
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function totalOrders(Request $request): \Illuminate\Http\Response|array|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $period = $request->query('period') === 'today' ? 'today' : 'all';
            return ['data' => ['total_orders' => $this->dashboardService->totalOrders($period)]];
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function totalCustomers(): \Illuminate\Http\Response|array|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return ['data' => ['total_customers' => $this->dashboardService->totalCustomers()]];
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function totalMenuItems(): \Illuminate\Http\Response|array|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return ['data' => ['total_menu_items' => $this->dashboardService->totalMenuItems()]];
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function orderStatistics(
        Request $request
    ): \Illuminate\Http\Response|OrderStatisticsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new OrderStatisticsResource($this->dashboardService->orderStatistics($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function salesSummary(
        Request $request
    ): \Illuminate\Http\Response|SalesSummaryResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new SalesSummaryResource($this->dashboardService->salesSummary($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function orderSummary(
        Request $request
    ): \Illuminate\Http\Response|OrderSummaryResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new OrderSummaryResource($this->dashboardService->orderSummary($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function customerStates(
        Request $request
    ): \Illuminate\Http\Response|CustomerStatesResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new CustomerStatesResource($this->dashboardService->customerStates($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function topCustomers(): \Illuminate\Http\Response|\Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return TopCustomerResource::collection($this->dashboardService->topCustomers());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function featuredItems(): \Illuminate\Http\Response|\Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return SimpleItemResource::collection($this->itemService->featuredItems());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function mostPopularItems(): \Illuminate\Http\Response|\Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return SimpleItemResource::collection($this->itemService->mostPopularItems());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function realtimeReport()
    {
        try {
            return response()->json(['data' => $this->dashboardService->realtimeReport()]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function slaAlerts()
    {
        try {
            return response()->json(['data' => $this->dashboardService->slaAlerts()]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function channelStatistics()
    {
        try {
            return response()->json(['data' => $this->dashboardService->channelStatistics()]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function auditTrail()
    {
        try {
            return response()->json(['data' => $this->dashboardService->auditTrail()]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * [V102-08 HEAL-3 2026-05-26] One-click owner-friendly EOD PDF synthesis.
     *
     * POST /api/admin/dashboard/eod-pdf?date=YYYY-MM-DD (default: today Paris).
     *
     * DM6 NF525 RO: pure read-only aggregation. Does NOT allocate a fiscal
     * sequence, does NOT insert into audit_logs, does NOT touch the HMAC chain.
     * This is comptable-facing summary, not a fiscal close — Z-report close
     * stays a distinct endpoint (Fiscal\ZReportController::close).
     */
    public function eodPdf(Request $request): mixed
    {
        try {
            $date = $request->query('date') ?: null;
            // Validate Y-m-d shape upfront to fail fast (Carbon parse otherwise
            // accepts a wide range of strings and silently coerces to today).
            if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return response(['status' => false, 'message' => 'Invalid date format. Expected YYYY-MM-DD.'], 422);
            }

            $synthesis = $this->dashboardService->eodSynthesis($date);

            $company = $this->companyService->list();
            $theme_logo = ThemeSetting::where(['key' => 'theme_logo'])->first()?->logo;
            $copyright = Settings::group('site')->get('site_copyright') ?? '';

            $pdf = Pdf::loadView('pdf.eod_synthesis', compact('company', 'theme_logo', 'synthesis', 'copyright'))
                ->setPaper('a4');

            $filename = 'cloture_jour_' . $synthesis['date'] . '.pdf';

            return response()->stream(
                fn() => print($pdf->output()),
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                ]
            );
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
