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

        return [(int) $authId, (int) $requestUser->branch_id];
    }
}
