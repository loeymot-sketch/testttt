<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\DeliveryBoyCashSessionCloseRequest;
use App\Http\Requests\DeliveryBoyCashSessionOpenRequest;
use App\Http\Requests\DeliveryBoyCashSessionReconcileRequest;
use App\Http\Resources\DeliveryBoyCashSessionResource;
use App\Models\DeliveryBoyCashSession;
use App\Models\User;
use App\Services\Delivery\DeliveryBoyCashSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * [V1.0.2 Sub-6.3 BUILD-1 — 2026-05-18] Admin controller — delivery boy cash session lifecycle.
 *
 * Surface : admin-only cockpit to view/open/close/reconcile livreur shifts.
 * The livreur self-service surface (`/api/frontend/delivery-boy-shift/*`) is
 * a separate controller (Wave 6b-1.3b) — not part of this BUILD-1 scope.
 *
 * Architecture mirrors `Admin\Pos\CashDrawerSessionController` :
 *   - thin controller, delegates everything to DeliveryBoyCashSessionService
 *   - HttpException → JSON 4xx with `{ status: false, message }`
 *   - Service handles audit chain + atomicity + variance gate
 *   - BranchScope filtering via route model binding + global scope (admin
 *     branch_id=0 sees all, staff branch_id=N sees only their branch)
 *
 * Permission gates :
 *   - `permission:delivery-boys_show` → index, show (read-only)
 *   - `permission:delivery-boys`      → open, close, reconcile (mutations)
 *
 * Routes are wired by BUILD-5 in routes/api.php (NOT here). See evidence
 * doc for the canonical route spec.
 */
class DeliveryBoyCashSessionController extends AdminController
{
    public function __construct(private readonly DeliveryBoyCashSessionService $service)
    {
        parent::__construct();
        $this->middleware(['permission:delivery-boys_show'])->only(['index', 'show']);
        $this->middleware(['permission:delivery-boys'])->only(['open', 'close', 'reconcile']);
    }

    /**
     * GET /api/admin/delivery-boy/cash-sessions
     * Optional filters : delivery_boy_id, branch_id, status, paginate, per_page.
     *
     * BranchScope automatically filters by auth user's branch unless they're
     * branch_id=0 (Admin/Tenant Admin). No manual cross-branch check needed.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'delivery_boy_id' => ['nullable', 'integer', 'min:1'],
            'branch_id'       => ['nullable', 'integer', 'min:0'],
            'status'          => ['nullable', 'string', 'in:open,closed,reconciled'],
            'per_page'        => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = DeliveryBoyCashSession::query()
            ->orderByDesc('opened_at')
            ->orderByDesc('id');

        if (! empty($filters['delivery_boy_id'])) {
            $query->where('delivery_boy_id', (int) $filters['delivery_boy_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        // branch_id filter is honored only when the auth user is global (branch_id=0).
        // Other staff are already scoped to their branch via BranchScope.
        $user = $request->user();
        if ($user && (int) $user->branch_id === 0 && ! empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }

        $perPage  = (int) ($filters['per_page'] ?? 20);
        $sessions = $query->paginate($perPage);

        return response()->json([
            'status'     => true,
            'data'       => DeliveryBoyCashSessionResource::collection($sessions->items()),
            'pagination' => [
                'total'        => $sessions->total(),
                'per_page'     => $sessions->perPage(),
                'current_page' => $sessions->currentPage(),
                'last_page'    => $sessions->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/admin/delivery-boy/cash-sessions/{session}
     * Returns the session detail + all linked movements.
     *
     * Route model binding fails 404 if BranchScope hides the row for the
     * calling user → cross-branch leak prevented automatically.
     */
    public function show(DeliveryBoyCashSession $session): JsonResponse
    {
        $session->load(['movements' => function ($q) {
            $q->orderBy('created_at')->orderBy('id');
        }]);

        return response()->json([
            'status' => true,
            'data'   => new DeliveryBoyCashSessionResource($session),
        ]);
    }

    /**
     * POST /api/admin/delivery-boy/cash-sessions/open
     * Body : { delivery_boy_id: int, opening_amount: float >= 0 }
     *
     * branch_id is derived from the target livreur's User row (NOT from the
     * caller's auth->branch_id) — admin may be branch_id=0 while opening on
     * behalf of a livreur in branch N. The opened_by_user_id is the caller.
     *
     * Returns 201 + serialized session on success, 409 if already open.
     */
    public function open(DeliveryBoyCashSessionOpenRequest $request): JsonResponse
    {
        $data = $request->validated();

        $caller = $request->user();
        if (! $caller) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated'], 401);
        }

        // Source branch_id from the livreur's User row. FormRequest already
        // asserted the role + branch presence ; we just look it up here.
        // [Z6-P1-WGS 2026-05-19] singular — preserves SoftDeletingScope so a
        // soft-deleted livreur cannot have a new session opened against them.
        $livreur = User::query()
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->findOrFail((int) $data['delivery_boy_id']);

        $branchId = (int) $livreur->branch_id;

        // Cross-branch defense : admin (branch_id=0) may open for any livreur,
        // but a branch-bound staff (branch_id=N) can only open for livreurs in
        // their branch. BranchScope on session reads handles the read path ;
        // this is the explicit write-path gate.
        if ((int) $caller->branch_id !== 0 && (int) $caller->branch_id !== $branchId) {
            return response()->json([
                'status'  => false,
                'message' => 'Cross-branch session open denied',
            ], 403);
        }

        try {
            $session = $this->service->openSession(
                $branchId,
                (int) $livreur->id,
                (float) $data['opening_amount'],
                (int) $caller->id,
            );
        } catch (HttpException $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        return response()->json([
            'status' => true,
            'data'   => new DeliveryBoyCashSessionResource($session),
        ], 201);
    }

    /**
     * POST /api/admin/delivery-boy/cash-sessions/{session}/close
     * Body : { closing_amount: float >= 0 }
     *
     * Route model binding enforces BranchScope (404 cross-branch).
     */
    public function close(
        DeliveryBoyCashSessionCloseRequest $request,
        DeliveryBoyCashSession $session,
    ): JsonResponse {
        $data = $request->validated();

        try {
            $closed = $this->service->closeSession((int) $session->id, (float) $data['closing_amount']);
        } catch (HttpException $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        return response()->json([
            'status' => true,
            'data'   => new DeliveryBoyCashSessionResource($closed),
        ]);
    }

    /**
     * POST /api/admin/delivery-boy/cash-sessions/{session}/reconcile
     * Body (optional) : { variance_reason: string max 255 }
     *
     * Service may throw 422 if the session is still OPEN (must close first)
     * or if variance exceeds threshold without reason / manager approval
     * (future Wave — currently service is lenient).
     */
    public function reconcile(
        DeliveryBoyCashSessionReconcileRequest $request,
        DeliveryBoyCashSession $session,
    ): JsonResponse {
        $data = $request->validated();

        try {
            $result = $this->service->reconcileSession(
                (int) $session->id,
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

        $sessionResource = (new DeliveryBoyCashSessionResource($result['session']))->toArray($request);

        return response()->json([
            'status' => true,
            'data'   => array_merge($sessionResource, [
                'expected' => $result['expected'],
                'variance' => $result['variance'],
            ]),
        ]);
    }
}
