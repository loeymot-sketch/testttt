<?php

namespace App\Http\Controllers\Admin\Pos;

use App\Http\Controllers\Admin\AdminController;
use App\Services\PosParkedOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParkedOrderController extends AdminController
{
    public function __construct(private readonly PosParkedOrderService $service)
    {
        parent::__construct();
        $this->middleware(['permission:pos']);
    }

    public function index(Request $request): JsonResponse
    {
        [$userId, $branchId] = $this->resolveOperatorContext($request);

        return response()->json([
            'data' => $this->service->listForOperator($userId, $branchId),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        [$userId, $branchId] = $this->resolveOperatorContext($request);

        $data = $request->validate([
            'payload' => ['required', 'array'],
            'label' => ['nullable', 'string', 'max:80'],
            'idempotency_token' => ['nullable', 'string', 'max:64'],
        ]);

        $parkedOrder = $this->service->park(
            $userId,
            $branchId,
            $data['payload'],
            $data['label'] ?? null,
            $data['idempotency_token'] ?? null
        );

        return response()->json(['data' => $parkedOrder], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        [$userId, $branchId] = $this->resolveOperatorContext($request);

        $parkedOrder = $this->service->recall($userId, $branchId, $id);

        if (! $parkedOrder) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json(['data' => $parkedOrder]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        [$userId, $branchId] = $this->resolveOperatorContext($request);

        if (! $this->service->discard($userId, $branchId, $id)) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json(null, 204);
    }

    private function resolveOperatorContext(Request $request): array
    {
        $requestUser = $request->user();
        $authId = auth()->id();

        abort_unless($requestUser !== null && $authId !== null, 401);
        abort_unless((int) $requestUser->id === (int) $authId, 403);

        // [P0-POS-04 GOAL round-2 2026-05-18] Branch-isolation guard for
        // parked orders. Pre-fix, an Admin user with branch_id=0 (the
        // org-wide "no branch" role) would resolve to a context of
        // (authId, 0) and `PosParkedOrderService::listForOperator` would
        // be queried with branch_id=0 — exposing every branch's parked
        // orders to the Admin even though the org-wide role is not a
        // branch operator.
        //
        // Parked orders are a per-branch cashier workflow. An Admin who
        // wants to operate the POS register must do so from a real branch
        // login (acting as a Branch Manager / POS Operator with
        // branch_id > 0). This guard makes the failure explicit (403)
        // rather than silently leaking cross-branch data.
        $branchId = (int) $requestUser->branch_id;
        abort_unless(
            $branchId > 0,
            403,
            'Parked orders require a branch-scoped user (branch_id > 0). Admins must operate POS from a branch login.'
        );

        return [(int) $authId, $branchId];
    }
}
