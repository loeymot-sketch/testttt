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

        // [abuse-heal 2026-06-20 W1b W1B-FLOORPLAN-02] Branch-isolation parity with
        // ParkedOrderController::resolveOperatorContext (P0-POS-04). The floorplan is a
        // per-branch cashier workflow; an Admin (branch_id=0) would resolve to (authId, 0) and
        // silently no-op every floorplan query/mutation against branch_id=0 (empty view, no
        // assign/transfer/free). Mirror the parked-order guard so the failure is explicit (403)
        // and V2-safe rather than a silent cross-context no-op. Admins operate the register from
        // a branch login (Branch Manager / POS Operator, branch_id > 0).
        $branchId = (int) $requestUser->branch_id;
        abort_unless(
            $branchId > 0,
            403,
            'Floorplan requires a branch-scoped user (branch_id > 0). Admins must operate POS from a branch login.'
        );

        return [(int) $authId, $branchId];
    }
}
