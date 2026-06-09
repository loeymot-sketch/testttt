<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Models\Order;
use App\Services\OrderService;
use App\Exports\OrderHistoryExport;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\SimpleOrderResource;
use App\Http\Resources\OrderDetailsResource;
use App\Models\Scopes\BranchScope;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * [GOAL-CAISSE-UNIFIED W-HIST 2026-05-30]
 * Unified read-only order history for /admin/historique. Owner mandate:
 * "see EVERYTHING from one page" — kiosk (Borne), POS (Caisse), walk-in,
 * delivery, online — instead of the split pos-orders / online-orders views.
 *
 * Pure read layer over the EXISTING OrderService::list (no source filter is
 * forced server-side; the page passes optional filters). Returns
 * SimpleOrderResource which now carries fiscal_sequence_no + parent_order_id
 * for NF525 traceability + refund linkage. Detail reuses OrderService::show
 * with the same cross-branch 403 unification as PosOrderController::show.
 *
 * Authorization: any operator who can already view orders on either split
 * surface (pos-orders / online-orders / pos). BranchScope still scopes a
 * branch operator to their branch; admin (branch_id=0) sees all.
 */
class OrderHistoryController extends AdminController
{
    private OrderService $orderService;

    public function __construct(OrderService $order)
    {
        parent::__construct();
        $this->orderService = $order;
    }

    public function index(
        PaginateRequest $request
    ): \Illuminate\Http\Response|\Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        // [GOAL-CAISSE-UNIFIED 2026-05-30 abuse-e2e P3 heal] Tightened to the
        // pos-order surface baseline (PosOrderController gates pos-orders|pos).
        // Dropped 'online-orders' — it would have let a future online-only role
        // see Borne/Caisse/walk-in orders this unified view aggregates, broader
        // than the split surface it replaces. No seeded role has online-orders
        // without pos/pos-orders, so this is defense-in-depth, not a live fix.
        abort_unless(
            auth()->user()?->can('pos-orders')
                || auth()->user()?->can('pos'),
            403
        );

        try {
            return SimpleOrderResource::collection($this->orderService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * [GOAL-CAISSE-UNIFIED W4.4 2026-06-08] Read-only export of the unified
     * order history — the spreadsheet the gérant hands the accountant. Carries
     * order_serial_no + fiscal_sequence_no for NF525 traceability and honors
     * the SAME filters as index() (from_date/to_date, source_surface,
     * order_type, status, payment_status) because it reuses OrderService::list.
     *
     * SECURITY: OrderHistoryController has NO constructor middleware (unlike
     * PosOrderController whose export is gated by ->only('export')). The same
     * inline gate as index()/show() is therefore MANDATORY here — without it
     * this leaks the full all-origin order set. BranchScope still scopes a
     * branch operator to their branch (OrderService::list uses scoped queries);
     * admin (branch_id=0) sees all. No writes, no NF525 mutation.
     */
    public function export(
        PaginateRequest $request
    ): \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        abort_unless(
            auth()->user()?->can('pos-orders')
                || auth()->user()?->can('pos'),
            403
        );

        try {
            return Excel::download(new OrderHistoryExport($this->orderService, $request), 'Historique.xlsx');
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(
        int|string $order
    ): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        // [GOAL-CAISSE-UNIFIED 2026-05-30 abuse-e2e P3 heal] Tightened to the
        // pos-order surface baseline (PosOrderController gates pos-orders|pos).
        // Dropped 'online-orders' — it would have let a future online-only role
        // see Borne/Caisse/walk-in orders this unified view aggregates, broader
        // than the split surface it replaces. No seeded role has online-orders
        // without pos/pos-orders, so this is defense-in-depth, not a live fix.
        abort_unless(
            auth()->user()?->can('pos-orders')
                || auth()->user()?->can('pos'),
            403
        );

        try {
            // Mirror PosOrderController::show — unify ModelNotFound + cross-branch
            // under a single 403 to prevent existence enumeration.
            try {
                $order = Order::withoutGlobalScope(BranchScope::class)->findOrFail($order);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                abort(403, 'Cross-branch access denied');
            }
            abort_unless(
                auth()->user()?->branch_id === 0 || $order->branch_id === auth()->user()?->branch_id,
                403,
                'Cross-branch access denied'
            );

            return new OrderDetailsResource($this->orderService->show($order, false));
        } catch (HttpException $http) {
            throw $http;
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
