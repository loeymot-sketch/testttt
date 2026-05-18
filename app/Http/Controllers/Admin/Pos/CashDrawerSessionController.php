<?php

namespace App\Http\Controllers\Admin\Pos;

use App\Http\Controllers\Admin\AdminController;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Services\Cash\CashDrawerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * [AUDIT-F-003 / Sub-task 3] Cash drawer SESSION management — Option A.
 *
 * NOTE — drift resolu :
 * Le sub-plan brief mentionnait `CashDrawerController` (avec route
 * `/cash-drawer/open`), mais ce nom + URL existent deja en hardware
 * (CashDrawerController::open via EscPosPrinterService — physique).
 * Le sub-plan §2.4 utilisait deja `CashDrawerSessionController` ; on
 * suit le sub-plan original. Routes prefixees `/cash-drawer/sessions/...`.
 *
 * Branch isolation : middleware permission:pos + filtre via session.branch_id
 * vs auth user branch_id (raw query bypass BranchScope only when justified).
 */
class CashDrawerSessionController extends AdminController
{
    public function __construct(private readonly CashDrawerService $service)
    {
        parent::__construct();
        $this->middleware(['permission:pos']);
    }

    /**
     * POST /api/admin/pos/cash-drawer/sessions/open
     * Body: { opening_amount: float >= 0 }
     */
    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'opening_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $user = $request->user();
        abort_if(! $user, 401);

        $branchId = (int) $user->branch_id;
        if ($branchId <= 0) {
            // Admin global / Tenant Admin sans branch fixe ne peut pas ouvrir une caisse.
            return response()->json([
                'status'  => false,
                'message' => 'Cannot open a cash drawer session without a branch context',
            ], 422);
        }

        try {
            $session = $this->service->openSession($branchId, (int) $user->id, (float) $data['opening_amount']);
        } catch (HttpException $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        return response()->json([
            'status' => true,
            'data'   => $this->serialize($session),
        ], 201);
    }

    /**
     * POST /api/admin/pos/cash-drawer/sessions/{session}/close
     * Body: { closing_amount: float >= 0 }
     */
    public function close(Request $request, int $session): JsonResponse
    {
        $data = $request->validate([
            'closing_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $this->assertSessionVisibleToUser($request, $session);

        try {
            $closed = $this->service->closeSession($session, (float) $data['closing_amount']);
        } catch (HttpException $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        return response()->json([
            'status' => true,
            'data'   => $this->serialize($closed),
        ]);
    }

    /**
     * POST /api/admin/pos/cash-drawer/sessions/{session}/reconcile
     *
     * Body (optional): { variance_reason: string max 255 }
     *
     * [Sprint 1D / F-4] When |variance| > config('cash.variance_threshold_eur'),
     * the request MUST provide a non-empty variance_reason AND the calling
     * user MUST hold cash.reconcile.variance.override permission (Admin or
     * Branch Manager). Otherwise responds 422 with code
     *   CASH_VARIANCE_REASON_REQUIRED  or  CASH_VARIANCE_MANAGER_APPROVAL_REQUIRED.
     */
    public function reconcile(Request $request, int $session): JsonResponse
    {
        $this->assertSessionVisibleToUser($request, $session);

        $data = $request->validate([
            'variance_reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->service->reconcileSession(
                $session,
                $data['variance_reason'] ?? null,
                $request->user(),
            );
        } catch (\App\Exceptions\CashVarianceRequiresApprovalException $e) {
            return response()->json([
                'status'    => false,
                'message'   => $e->getMessage(),
                'code'      => $e->getErrorCode(),
                'variance'  => $e->getVariance(),
                'threshold' => $e->getThreshold(),
            ], $e->getStatusCode());
        } catch (HttpException $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        return response()->json([
            'status' => true,
            'data'   => array_merge($this->serialize($result['session']), [
                'expected' => $result['expected'],
                'variance' => $result['variance'],
            ]),
        ]);
    }

    /**
     * GET /api/admin/pos/cash-drawer/sessions/current
     * Returns the OPEN session for the calling user on their branch (or null).
     */
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if(! $user, 401);

        $branchId = (int) $user->branch_id;
        if ($branchId <= 0) {
            return response()->json(['status' => true, 'data' => null]);
        }

        $session = $this->service->findOpenSessionForUser($branchId, (int) $user->id);

        return response()->json([
            'status' => true,
            'data'   => $session ? $this->serialize($session) : null,
        ]);
    }

    /**
     * GET /api/admin/pos/cash-drawer/sessions/{session}/movements
     */
    public function movements(Request $request, int $session): JsonResponse
    {
        $this->assertSessionVisibleToUser($request, $session);

        $movements = CashMovement::query()
            ->where('cash_drawer_session_id', $session)
            ->orderBy('created_at')
            ->get()
            ->map(fn (CashMovement $m) => [
                'id'        => $m->id,
                'order_id'  => $m->order_id,
                'type'      => $m->type,
                'amount'    => (float) $m->amount,
                'direction' => $m->direction,
                'notes'     => $m->notes,
                'created_at' => optional($m->created_at)->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'status' => true,
            'data'   => $movements,
        ]);
    }

    /**
     * Serialize session uniformly across endpoints.
     *
     * @return array<string,mixed>
     */
    private function serialize(CashDrawerSession $session): array
    {
        return [
            'id'                      => $session->id,
            'branch_id'               => $session->branch_id,
            'opened_by_user_id'       => $session->opened_by_user_id,
            'opened_at'               => optional($session->opened_at)->toIso8601String(),
            'closed_at'               => optional($session->closed_at)->toIso8601String(),
            'opening_amount'          => (float) $session->opening_amount,
            'closing_amount'          => $session->closing_amount === null ? null : (float) $session->closing_amount,
            'expected_closing_amount' => $session->expected_closing_amount === null ? null : (float) $session->expected_closing_amount,
            'variance'                => $session->variance === null ? null : (float) $session->variance,
            // [Sprint 1D / F-4] Expose variance_reason — NF525 evidence,
            // displayed in admin reconciliation screen and Z-report drilldown.
            'variance_reason'         => $session->variance_reason,
            'status'                  => $session->status,
        ];
    }

    /**
     * Garantit que l'utilisateur courant peut acceder a la session.
     * BranchScope filtrerait deja, mais on ajoute un abort 404 explicite
     * si la session n'est pas visible (cohérent avec l'API REST).
     *
     * [Wave 1 P1 — POS-RED-04 2026-05-18] Ownership tightening.
     * Pre-fix: branch-only check let cashier B close cashier A's drawer
     * on the same branch (closing_amount=0 → variance mis-attribution +
     * NF525 audit_log captures wrong actor_user_id).
     * Post-fix: same-branch users must EITHER own the session OR hold
     * `cash.reconcile.variance.override` (Branch Manager / Admin) to
     * act on someone else's drawer.
     */
    private function assertSessionVisibleToUser(Request $request, int $sessionId): void
    {
        $session = CashDrawerSession::query()->find($sessionId);
        abort_if(! $session, 404, 'Cash drawer session not found');

        $user = $request->user();
        abort_if(! $user, 401);

        // Admin global (branch_id=0) voit tout
        if ((int) $user->branch_id === 0) {
            return;
        }

        abort_if((int) $session->branch_id !== (int) $user->branch_id, 403, 'Cross-branch access denied');

        // [POS-RED-04] Same-branch ownership gate.
        $isOwner   = (int) $session->opened_by_user_id === (int) $user->id;
        $isManager = $user->can('cash.reconcile.variance.override')
            || $user->hasRole('Admin')
            || $user->hasRole('Branch Manager');
        abort_if(! $isOwner && ! $isManager, 403, 'Not session owner (manager permission required)');
    }
}
