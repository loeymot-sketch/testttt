<?php

namespace App\Http\Controllers\Admin;

use App\Http\Resources\SimpleItemResource;
use App\Http\Resources\TopCustomerResource;
use Exception;
use Throwable;
use Illuminate\Validation\ValidationException;
use App\Libraries\AppLibrary;
use App\Services\ItemService;
use App\Services\DashboardService;
use App\Services\ItemCategoryService;
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

    /**
     * [2026-09-02 · Sub 3.3 · Codex P2-D] Une panne du tableau de bord ne doit pas
     * raconter l'intérieur du serveur.
     *
     * Cette méthode renvoyait `$exception->getMessage()` tel quel. Pour une exception de
     * base de données, ce message porte la REQUÊTE SQL complète, le code SQLSTATE, le nom
     * du pilote et souvent un chemin de fichier du serveur — servis à quiconque a la
     * permission `dashboard`, et affichés en clair par l'écran. Le sélecteur de dates
     * cassé (corrigé au commit précédent) les faisait apparaître à chaque essai.
     *
     * Les refus MÉTIER continuent de passer intacts : « la date de fin doit être
     * postérieure » n'est pas une fuite, c'est la seule information utile à l'opérateur.
     * Rendre toutes les erreurs muettes serait pire que le défaut corrigé.
     */
    private function dashboardFailure(Throwable $exception)
    {
        if ($exception instanceof ValidationException) {
            throw $exception;
        }
        if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            throw $exception;
        }

        // Ne rien dire au navigateur ne veut pas dire ne rien savoir : sans cette trace,
        // on aurait seulement déplacé la panne dans le noir.
        \Illuminate\Support\Facades\Log::error('[dashboard] échec non métier', [
            'route' => request()->path(),
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'correlation_id' => request()->header('X-Correlation-Id'),
            'user_id' => auth()->id(),
        ]);

        return response([
            'status' => false,
            'message' => trans('all.message.database_error_message'),
        ], 422);
    }
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
            return $this->dashboardFailure($exception);
        }
    }

    public function totalOrders(Request $request): \Illuminate\Http\Response|array|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $period = $request->query('period') === 'today' ? 'today' : 'all';
            return ['data' => ['total_orders' => $this->dashboardService->totalOrders($period)]];
        } catch (Exception $exception) {
            return $this->dashboardFailure($exception);
        }
    }

    public function totalCustomers(): \Illuminate\Http\Response|array|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return ['data' => ['total_customers' => $this->dashboardService->totalCustomers()]];
        } catch (Exception $exception) {
            return $this->dashboardFailure($exception);
        }
    }

    public function totalMenuItems(): \Illuminate\Http\Response|array|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return ['data' => ['total_menu_items' => $this->dashboardService->totalMenuItems()]];
        } catch (Exception $exception) {
            return $this->dashboardFailure($exception);
        }
    }

    public function orderStatistics(
        Request $request
    ): \Illuminate\Http\Response|OrderStatisticsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new OrderStatisticsResource($this->dashboardService->orderStatistics($request));
        } catch (Exception $exception) {
            return $this->dashboardFailure($exception);
        }
    }

    public function salesSummary(
        Request $request
    ): \Illuminate\Http\Response|SalesSummaryResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new SalesSummaryResource($this->dashboardService->salesSummary($request));
        } catch (Throwable $exception) {
            return $this->dashboardFailure($exception);
        }
    }

    public function orderSummary(
        Request $request
    ): \Illuminate\Http\Response|OrderSummaryResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new OrderSummaryResource($this->dashboardService->orderSummary($request));
        } catch (Exception $exception) {
            return $this->dashboardFailure($exception);
        }
    }

    public function customerStates(
        Request $request
    ): \Illuminate\Http\Response|CustomerStatesResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new CustomerStatesResource($this->dashboardService->customerStates($request));
        } catch (Exception $exception) {
            return $this->dashboardFailure($exception);
        }
    }

    public function topCustomers(): \Illuminate\Http\Response|\Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return TopCustomerResource::collection($this->dashboardService->topCustomers());
        } catch (Exception $exception) {
            return $this->dashboardFailure($exception);
        }
    }

    public function featuredItems(): \Illuminate\Http\Response|\Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return SimpleItemResource::collection($this->withoutCatalogPollution($this->itemService->featuredItems()));
        } catch (Exception $exception) {
            return $this->dashboardFailure($exception);
        }
    }

    public function mostPopularItems(): \Illuminate\Http\Response|\Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            // [2026-09-02 · Sub 3.2 · Codex P1-F] Cette carte n'entre pas par
            // DashboardService : elle échappait au fail-closed du 29 août. Un compte
            // non-Admin à `branch_id = 0` recevait ici le classement de TOUTES les
            // branches, alors qu'il reçoit 403 sur les huit autres cartes.
            $this->dashboardService->assertDashboardBranchScope();

            return SimpleItemResource::collection($this->withoutCatalogPollution($this->itemService->mostPopularItems()));
        } catch (Exception $exception) {
            return $this->dashboardFailure($exception);
        }
    }

    /**
     * SHARED dashboard : on n'édite pas ItemService. On retire les noms E2E/AUDIT
     * du carrousel « mis en avant » — le gérant ne vend pas ça.
     */
    private function withoutCatalogPollution($items)
    {
        return collect($items)->filter(function ($item) {
            if (ItemCategoryService::isAuditPollutionName($item->name ?? '')) {
                return false;
            }
            $categoryName = $item->category->name ?? '';
            if ($categoryName !== '' && ItemCategoryService::isAuditPollutionName($categoryName)) {
                return false;
            }
            if ($categoryName !== '' && ItemCategoryService::isInternalOpsCategoryName($categoryName)) {
                return false;
            }

            return true;
        })->values();
    }

    public function realtimeReport()
    {
        try {
            return response()->json(['data' => $this->dashboardService->realtimeReport()]);
        } catch (Exception $exception) {
            return $this->dashboardFailure($exception);
        }
    }

    public function slaAlerts()
    {
        try {
            // [2026-09-03] La fenêtre est publiée À CÔTÉ des alertes. « Aucune préparation
            // hors délai » se lisait comme un fait absolu alors que le contrôle ne regarde que
            // les dernières heures : 344 commandes étaient figées en préparation, la plus
            // ancienne depuis le 10 juin, pendant que l'écran affichait « Dernier contrôle
            // terminé avec succès ». Le chiffre était juste dans son périmètre ; c'est le
            // périmètre qui n'était écrit nulle part. Le client ne lit que `data.data` : cette
            // clé supplémentaire ne casse rien.
            return response()->json([
                'data' => $this->dashboardService->slaAlerts(),
                'fenetre_heures' => (int) config('dashboard.sla_alerts_window_hours', 24),
            ]);
        } catch (Exception $exception) {
            return $this->dashboardFailure($exception);
        }
    }

    public function channelStatistics()
    {
        try {
            return response()->json(['data' => $this->dashboardService->channelStatistics()]);
        } catch (Exception $exception) {
            return $this->dashboardFailure($exception);
        }
    }

    public function auditTrail()
    {
        try {
            return response()->json(['data' => $this->dashboardService->auditTrail()]);
        } catch (Exception $exception) {
            return $this->dashboardFailure($exception);
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
            // [2026-09-02 · Sub 3.1 · Codex P1-G] La FORME ne suffit pas : `2026-02-31`
            // passait ce filtre, puis `Carbon::parse` le roulait au 3 mars. Le PDF de
            // clôture portait alors les chiffres d'un AUTRE jour, sans le dire — sur une
            // pièce de nature fiscale, l'écart ne se découvre qu'au contrôle. La date doit
            // exister : on la reformate et on compare à la chaîne demandée.
            if ($date !== null) {
                $jour = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
                    ? \Carbon\Carbon::createFromFormat('!Y-m-d', $date, config('app.timezone'))
                    : false;

                if ($jour === false || $jour->format('Y-m-d') !== $date) {
                    return response([
                        'status' => false,
                        'message' => 'La date doit être un jour réel au format AAAA-MM-JJ.',
                    ], 422);
                }
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
            return $this->dashboardFailure($exception);
        }
    }
}
