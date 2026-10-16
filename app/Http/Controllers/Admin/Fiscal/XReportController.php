<?php

namespace App\Http\Controllers\Admin\Fiscal;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Fiscal\XReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * [POS-9.4.9 / POS-GA-F-02] Admin endpoint for fiscal X intraday snapshot.
 *
 * Read-only: GET /api/admin/fiscal/x-report.
 * Requires Spatie permission `pos-manage-fiscal`.
 */
class XReportController extends Controller
{
    public function __construct(private XReportService $service) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->can('pos-manage-fiscal'), Response::HTTP_FORBIDDEN,
            trans('all.message.droit_fiscal_requis'));

        $branchId = $this->resolveBranchIdForFiscalRead($request, $user);

        // [ULTRA-AUDIT 2026-07-02] Valider from/to avant Carbon::parse — sinon une date
        // malformée levait InvalidFormatException non catchée → HTTP 500 (fuite de trace si
        // APP_DEBUG). Cohérent avec DashboardController::eodPdf qui renvoie 422 sur input invalide.
        try {
            $from = $request->filled('from') ? Carbon::parse((string) $request->query('from')) : null;
            $to   = $request->filled('to')   ? Carbon::parse((string) $request->query('to'))   : null;
        } catch (\Carbon\Exceptions\InvalidFormatException $e) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Format de date invalide (paramètres from/to).');
        }

        return response()->json([
            'data' => $this->service->snapshot($branchId, $from, $to),
        ]);
    }

    /**
     * [QA 2026-09-28 — RAPPORT_DEV_CAISSE_2026-09-24 P0-10 / triage A9]
     *
     * Le garde précédent était `abort_if($user->branch_id <= 0)`, ce qui rendait
     * le rapport X — document fiscal légalement obligatoire — INATTEIGNABLE pour
     * le compte administrateur, qui n'est pas épinglé à une branche (§9 admin
     * bypass, `branch_id = 0`). La liste Z avait déjà reçu sa relaxation lecture
     * seule (Wave T R1 F1 P0 2026-05-20) ; le X, pourtant tout aussi read-only
     * (`XReportService` : « Never writes »), ne l'avait jamais reçue.
     *
     * On NE fabrique PAS d'agrégat inter-branches : un instantané fiscal
     * appartient à UNE caisse, comme le dit le message d'erreur lui-même.
     * On lève seulement l'impasse, dans cet ordre :
     *   1. branche épinglée → elle gagne toujours. Un `?branch_id=` ne peut
     *      JAMAIS l'écraser, sinon un employé lirait le X d'une autre caisse
     *      (IDOR). C'est le garde le plus important de cette méthode.
     *   2. admin non épinglé + `branch_id` explicite ET existant → cette caisse.
     *      Le choix est explicite et tracé dans la requête.
     *   3. admin non épinglé + une seule branche en base → elle, sans ambiguïté
     *      possible (enveloppe V1 LOCAL Le Cayenne : `branch_id=1` unique).
     *   4. plusieurs branches et aucun choix → on refuse encore, plutôt que de
     *      livrer un instantané fiscal portant sur une caisse arbitraire.
     */
    private function resolveBranchIdForFiscalRead(Request $request, $user): int
    {
        $pinned = (int) ($user->branch_id ?? 0);
        if ($pinned > 0) {
            return $pinned;
        }

        if ($request->filled('branch_id')) {
            $requested = (int) $request->query('branch_id');
            // Branch est exempté de BranchScope (§9, self-reference) et porte
            // SoftDeletes : la requête par défaut écarte donc déjà les branches
            // supprimées. Une branche inconnue doit sortir en 422, pas en 500
            // (XReportService::snapshot lève InvalidArgumentException si <= 0).
            abort_unless(
                $requested > 0 && Branch::whereKey($requested)->exists(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                trans('all.message.caisse_sans_etablissement')
            );

            return $requested;
        }

        // `limit(2)` suffit : on veut seulement savoir s'il y en a exactement une.
        $candidates = Branch::orderBy('id')->limit(2)->pluck('id');
        if ($candidates->count() === 1) {
            return (int) $candidates->first();
        }

        abort(Response::HTTP_UNPROCESSABLE_ENTITY,
            trans('all.message.caisse_sans_etablissement'));
    }
}
