<?php

namespace App\Http\Controllers\Admin\Pos;

use App\Http\Controllers\Admin\AdminController;
use App\Http\Requests\Admin\Pos\FloorplanTransferRequest;
use App\Services\DiningTableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FloorplanController extends AdminController
{
    public function __construct(private readonly DiningTableService $service)
    {
        parent::__construct();
        $this->middleware(['permission:pos']);
    }

    public function state(Request $request): JsonResponse
    {
        [, $branchId] = $this->resolveOperatorContext($request);

        return response()->json([
            'data' => $this->service->floorplanState($branchId),
        ]);
    }

    public function assign(Request $request, int $tableId): JsonResponse
    {
        [$userId, $branchId] = $this->resolveOperatorContext($request);

        $data = $request->validate([
            'order_id' => ['required', 'integer', 'min:1'],
        ]);

        $table = $this->service->occupy(
            $userId,
            $branchId,
            $tableId,
            (int) $data['order_id']
        );

        return response()->json(['data' => $table]);
    }

    public function release(Request $request, int $tableId): JsonResponse
    {
        [$userId, $branchId] = $this->resolveOperatorContext($request);

        $table = $this->service->release($userId, $branchId, $tableId);

        return response()->json(['data' => $table]);
    }

    public function transfer(FloorplanTransferRequest $request): JsonResponse
    {
        [$userId, $branchId] = $this->resolveOperatorContext($request);

        $table = $this->service->transfer(
            $userId,
            $branchId,
            (int) $request->validated('source_table_id'),
            (int) $request->validated('target_table_id')
        );

        return response()->json(['data' => $table]);
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
