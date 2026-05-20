<?php

namespace App\Http\Controllers\Admin\Fiscal;

use App\Http\Controllers\Controller;
use App\Models\ZReport;
use App\Services\Fiscal\ZReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [POS-9.4.9 / POS-GA-F-01] Admin endpoints for fiscal Z reports.
 *
 * Wired under /api/admin/fiscal/z-report/* (see routes/api.php).
 * All routes require the Spatie permission `pos-manage-fiscal`.
 */
class ZReportController extends Controller
{
    public function __construct(private ZReportService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeFiscal();

        // [Wave T R1 F1 P0 2026-05-20] Read-only index must not 422 when admin
        // (branch_id=0) lands on /admin/dashboard — LastZReportWidget mounts
        // there and was returning a silent 422 on every POS operator login.
        // Mutating endpoints (open/close) still require a pinned branch; here
        // we relax the constraint only for read: admin sees the latest 100 Z
        // across all branches; staff (branch_id>0) keep branch-scoped view.
        $branchId = $this->resolveBranchIdForRead($request);

        $rows = ZReport::query()
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('sequence_no')
            ->limit(100)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function open(Request $request): JsonResponse
    {
        $this->authorizeFiscal();

        $branchId = $this->resolveBranchId($request);
        $z = $this->service->open($branchId, $request->user());

        return response()->json(['data' => $z], Response::HTTP_CREATED);
    }

    public function close(Request $request): JsonResponse
    {
        $this->authorizeFiscal();

        $branchId = $this->resolveBranchId($request);
        $z = $this->service->close($branchId, $request->user());

        return response()->json(['data' => $z]);
    }

    public function show(Request $request, ZReport $zReport): JsonResponse
    {
        $this->authorizeFiscal();

        $branchId = $this->resolveBranchId($request);
        abort_if((int) $zReport->branch_id !== $branchId, Response::HTTP_FORBIDDEN);

        return response()->json(['data' => $zReport]);
    }

    /**
     * Signed JSON bundle for the receipt / PDF layer. The actual PDF
     * rendering is delegated to a later view layer; at this layer the
     * important part is that the payload carries the signature so any
     * PDF renderer produces a verifiable document.
     */
    public function pdf(Request $request, ZReport $zReport): JsonResponse
    {
        $this->authorizeFiscal();

        $branchId = $this->resolveBranchId($request);
        abort_if((int) $zReport->branch_id !== $branchId, Response::HTTP_FORBIDDEN);

        $verified = $this->service->verifySignature($zReport);

        return response()->json([
            'data' => [
                'z_report'  => $zReport,
                'verified'  => $verified,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    private function authorizeFiscal(): void
    {
        $user = request()->user();
        abort_unless($user && $user->can('pos-manage-fiscal'), Response::HTTP_FORBIDDEN,
            'pos-manage-fiscal permission required.');
    }

    private function resolveBranchId(Request $request): int
    {
        $user = $request->user();
        $fromUser = (int) ($user->branch_id ?? 0);
        if ($fromUser > 0) {
            return $fromUser;
        }
        // Admin without a pinned branch must specify it explicitly — never
        // trust a payload-side branch_id for a fiscal-sensitive operation.
        abort(Response::HTTP_UNPROCESSABLE_ENTITY,
            'Fiscal operation requires the authenticated user to be pinned to a branch.');
    }

    /**
     * [Wave T R1 F1 P0 2026-05-20] Read-only variant: admin (branch_id=0)
     * returns null so the index query is unfiltered (cross-branch view);
     * staff (branch_id>0) returns their pinned branch. Mutating endpoints
     * (open/close/show/pdf) keep using `resolveBranchId()` which still 422s
     * for unpinned admin — fiscal write/single-doc access stays branch-scoped.
     * Read-only Z history exposure is acceptable for admins by §9 RBAC.
     */
    private function resolveBranchIdForRead(Request $request): ?int
    {
        $user = $request->user();
        $fromUser = (int) ($user->branch_id ?? 0);
        return $fromUser > 0 ? $fromUser : null;
    }
}
