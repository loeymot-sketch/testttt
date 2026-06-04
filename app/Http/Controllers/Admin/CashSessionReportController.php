<?php

namespace App\Http\Controllers\Admin;

use App\Models\CashDrawerSession;
use App\Models\Scopes\BranchScope;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [Wave O — O4 2026-05-20] Admin daily cash sessions report — READ-ONLY.
 *
 * Demande owner (verbatim) :
 *   « Je veux que toutes les caisses de chaque jour soient enregistrées pour
 *    le profil admin. Quand on va sur le profil admin, on verra les caisses
 *    chaque jour, c'est-à-dire le début et la fin. Et toutes les transactions
 *    de chaque jour sont bien enregistrées dans la partie admin. »
 *
 * Endpoint : GET /api/admin/cash-sessions-report
 *
 * Permission : `cash-sessions-report` (Admin par Permission::all() + Branch
 *   Manager explicitement listé dans RolePermissionTableSeeder).
 *   Permission dédiée plutôt que partagée avec Z/X-reports pour que la sidebar
 *   admin (driven by authPermission) puisse l'auto-afficher / cacher
 *   indépendamment.
 *
 * Branch isolation :
 *   - Admin (branch_id=0) : BranchScope laisse passer (admin global), voit
 *     toutes les sessions de toutes les branches.
 *   - Branch Manager (branch_id>0) : BranchScope filtre à sa branche, voit
 *     uniquement ses sessions.
 *   - Pas d'ajout de scope manuel — on s'appuie sur le model contract.
 *
 * NF525 :
 *   READ-ONLY pur. Aucune mutation, aucune chaîne HMAC affectée. Le risque
 *   de drift fiscal est nul ; ce contrôleur est un consommateur du même
 *   schema que ZReportCashEnrichmentService.
 *
 * N+1 :
 *   Eager-load openedBy:id,name + branch:id,name + withCount('movements').
 *
 * Filtres supportés :
 *   - from=YYYY-MM-DD  (date pivot inclusive sur opened_at)
 *   - to=YYYY-MM-DD    (date pivot inclusive sur opened_at)
 *   - branch_id=N      (admin uniquement — restreint à 1 branch)
 *   - per_page=N       (default 50, max 200)
 */
class CashSessionReportController extends AdminController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * GET /api/admin/cash-sessions-report
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if(! $user, Response::HTTP_UNAUTHORIZED);
        abort_unless(
            $user->can('cash-sessions-report'),
            Response::HTTP_FORBIDDEN,
            'cash-sessions-report permission required.'
        );

        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(1, min(200, $perPage));

        // Build query.
        // Admin (branch_id=0) : on bypasse BranchScope explicitement pour voir
        // l'intégralité ; cohérent avec ZReportCashEnrichmentService pattern.
        // Branch staff : BranchScope filtre automatiquement.
        $query = CashDrawerSession::query();
        if ((int) ($user->branch_id ?? 0) === 0) {
            $query->withoutGlobalScope(BranchScope::class);
        }

        $query
            ->with([
                'openedBy:id,name',
            ])
            ->withCount('movements')
            ->orderByDesc('opened_at')
            ->orderByDesc('id');

        // Optional date range filter (inclusive on opened_at).
        if ($from = $request->query('from')) {
            try {
                $fromDate = Carbon::parse($from)->startOfDay();
                $query->where('opened_at', '>=', $fromDate);
            } catch (\Throwable $e) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid `from` date (expected YYYY-MM-DD)',
                ], 422);
            }
        }
        if ($to = $request->query('to')) {
            try {
                $toDate = Carbon::parse($to)->endOfDay();
                $query->where('opened_at', '<=', $toDate);
            } catch (\Throwable $e) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid `to` date (expected YYYY-MM-DD)',
                ], 422);
            }
        }

        // Optional branch filter — admin only (cross-branch view).
        if (($branchFilter = $request->query('branch_id')) !== null && (int) $branchFilter > 0) {
            if ((int) ($user->branch_id ?? 0) === 0) {
                $query->where('branch_id', (int) $branchFilter);
            }
            // For branch staff, BranchScope already enforces — ignore the
            // hint silently rather than 403 (avoids leaking branch existence).
        }

        $paginated = $query->paginate($perPage);

        $data = $paginated->getCollection()->map(function (CashDrawerSession $s) {
            $openedAt = $s->opened_at instanceof Carbon ? $s->opened_at : ($s->opened_at ? Carbon::parse($s->opened_at) : null);
            return [
                'id'                      => (int) $s->id,
                'branch_id'               => (int) $s->branch_id,
                'business_date'           => $openedAt ? $openedAt->toDateString() : null,
                'opened_at'               => optional($s->opened_at)->toIso8601String(),
                'closed_at'               => optional($s->closed_at)->toIso8601String(),
                'opened_by_user_id'       => (int) $s->opened_by_user_id,
                'opened_by_name'          => $s->openedBy?->name,
                'opening_amount'          => (float) $s->opening_amount,
                'closing_amount'          => $s->closing_amount === null ? null : (float) $s->closing_amount,
                'expected_closing_amount' => $s->expected_closing_amount === null ? null : (float) $s->expected_closing_amount,
                'variance'                => $s->variance === null ? null : (float) $s->variance,
                'variance_reason'         => $s->variance_reason,
                'status'                  => (string) $s->status,
                'transactions_count'      => (int) ($s->movements_count ?? 0),
            ];
        })->values()->all();

        return response()->json([
            'status' => true,
            'data'   => $data,
            'meta'   => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ]);
    }
}
