<?php

namespace App\Http\Controllers\Admin;

use App\Http\Resources\SalesReportOverviewResource;
use App\Http\Resources\SimpleOrderResource;
use Exception;
use App\Services\OrderService;
use App\Exports\SalesReportExport;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Requests\PaginateRequest;
use App\Models\ThemeSetting;
use App\Services\CompanyService;
use App\Services\ThemeService;
use Smartisan\Settings\Facades\Settings;
use Barryvdh\DomPDF\Facade\Pdf;

class SalesReportController extends AdminController
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */

    private OrderService $orderService;

    private CompanyService $companyService;
    private ThemeService $themeService;

    public function __construct(OrderService $order, CompanyService $companyService, ThemeService $themeService)
    {
        parent::__construct();
        $this->orderService = $order;
        $this->companyService = $companyService;
        $this->themeService  = $themeService;
        // [REP-AUTHZ-01 heal 2026-06-01 · corrigé 2026-07-18 audit intelligence P1-5]
        // Gate la méthode qui sert GET /admin/sales-report/overview (l'agrégat CA).
        // ->only() filtre par NOM DE MÉTHODE : le heal du 2026-06-01 avait écrit
        // 'overview' (le segment d'URI) alors que la vraie méthode est
        // `salesReportOverview` → le middleware n'était JAMAIS appliqué et l'agrégat
        // restait lisible par tout staff auth:sanctum sans `sales-report`.
        $this->middleware(['permission:sales-report'])->only('index', 'export', 'pdf', 'salesReportOverview');
    }

    public function index(PaginateRequest $request): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            // [GOAL-OPS-SWAP W2 2026-08-12] `true` = écarte les contre-écritures de
            // remboursement, comme le fait déjà `salesReportOverview()`. Sans ce
            // drapeau, la tuile et le pied de tableau du MÊME écran annonçaient
            // deux chiffres différents (3185 contre 3191).
            return SimpleOrderResource::collection($this->orderService->list($request, true));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function export(PaginateRequest $request): \Illuminate\Http\Response | \Symfony\Component\HttpFoundation\BinaryFileResponse | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return Excel::download(new SalesReportExport($this->orderService, $request), 'Sales-Report.xlsx');
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function pdf(PaginateRequest $request): mixed
    {
        try {
            // [ULTRA-LOOP R1 P1 2026-07-07 — PDF tronqué à 10 lignes] L'UI envoie
            // paginate=1&per_page=10 ; OrderService::list voit paginate==1 → ->paginate(10),
            // donc le blade n'itérait QUE la 1re page ET le "Total" (agrégé dans la boucle
            // @foreach) sous-déclarait massivement le CA (ex. 38 522,62 € réels affichés
            // 6,70 €). On force un fetch complet — miroir exact de SalesReportExport:30.
            $request->merge(['paginate' => 0]);
            $company = $this->companyService->list();
            $theme_logo   = ThemeSetting::where(['key' => 'theme_logo'])->first()?->logo;
            $copyright   = Settings::group('site')->get('site_copyright');
            // [GOAL-OPS-SWAP W2 2026-08-12] Même exclusion que l'écran : un PDF de
            // rapport ne peut pas compter autrement que son propre résumé.
            $orders = $this->orderService->list($request, true);

            // [ULTRA-LOOP R2 P2 2026-07-07 — garde anti-OOM] Régression du fix R1 : paginate=0
            // sans filtre de date force le rendu de ~2850 commandes → dompdf épuise la mémoire
            // (PHP Error fatale non attrapée par catch(Exception) → 500 brut). On coupe AVANT
            // le rendu ; les rapports datés (usage normal) passent, le total reste exact.
            $maxRows = (int) config('report.pdf_max_rows', 2000);
            if ($orders->count() > $maxRows) {
                return response([
                    'status' => false,
                    'message' => 'Trop de lignes pour un export PDF ('.$orders->count().' lignes). '
                        .'Affinez la période avec un filtre de date.',
                ], 422);
            }

            $pdf = Pdf::loadView('pdf.sales_report', compact('company', 'theme_logo', 'orders', 'copyright'))
                ->setPaper('a4');
            return response()->stream(
                fn() => print($pdf->output()),
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="sales_report.pdf"',
                ]
            );
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function salesReportOverview(PaginateRequest $request): \Illuminate\Foundation\Application|\Illuminate\Http\Response|SalesReportOverviewResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new SalesReportOverviewResource($this->orderService->salesReportOverview($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}