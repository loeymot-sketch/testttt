<?php

namespace App\Http\Controllers\Admin\Fiscal;

use App\Http\Controllers\Controller;
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
            'pos-manage-fiscal permission required.');

        $branchId = (int) ($user->branch_id ?? 0);
        abort_if($branchId <= 0, Response::HTTP_UNPROCESSABLE_ENTITY,
            'Fiscal operation requires the authenticated user to be pinned to a branch.');

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
}
