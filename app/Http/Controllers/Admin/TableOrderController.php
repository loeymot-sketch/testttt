<?php

namespace App\Http\Controllers\Admin;

use App\Exports\OrderExport;
use App\Http\Requests\OrderStatusRequest;
use App\Http\Requests\PaginateRequest;
use App\Http\Requests\PaymentStatusRequest;
use App\Http\Requests\TableOrderTokenRequest;
use App\Http\Resources\OrderDetailsResource;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Exception;
use Maatwebsite\Excel\Facades\Excel;

class TableOrderController extends AdminController
{
    private OrderService $orderService;

    public function __construct(OrderService $order)
    {
        parent::__construct();
        $this->orderService = $order;
        $this->middleware(['permission:table-orders'])->only(
            'index',
            'show',
            'export',
            'changeStatus',
            'changePaymentStatus',
            'selectDeliveryBoy',
            // [SELF-AUDIT R3 P2 2026-07-05 — dérive d'autorisation] tokenCreate ÉTAIT omis de la garde
            // alors que TOUTES ses sœurs mutantes (changeStatus/changePaymentStatus…) l'appliquent, et
            // sa FormRequest authorize() renvoie true → un Chef/POS Operator (sans `table-orders`)
            // pouvait POST /table-order/token-create/{order} et écraser orders.token de n'importe quelle
            // commande de sa branche (tampering). On l'aligne sur les sœurs.
            'tokenCreate'
        );
    }

    public function index(PaginateRequest $request): \Illuminate\Http\Response|\Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return OrderResource::collection($this->orderService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(Order $order): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new OrderDetailsResource($this->orderService->show($order, false));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function export(PaginateRequest $request): \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return Excel::download(new OrderExport($this->orderService, $request), 'Table-Order.xlsx');
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function changeStatus(Order $order, OrderStatusRequest $request): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        // [REFUND-BYPASS-GUARD TWIN 2026-06-27 / P1] Same gate as PosOrderController::changeStatus
        // (commit 10e462149). RETURNED (22) IS the refund transition — OrderService::changeStatus
        // fires PaymentService::cashBack + LoyaltyService::refundPoints. This sibling was gated only
        // by `permission:table-orders` (which a Waiter HAS, but NOT `pos-refund`) → a Waiter could
        // refund ANY paid order in the branch (incl. a POS cash sale; route {order} is type-agnostic).
        // Gate ONLY status=RETURNED so legit transitions (ACCEPT/PREPARING/…) stay open. Fail-fast
        // BEFORE delegating; OrderStateMachine edge stays frozen — authz lives at the controller.
        if ((int) $request->status === \App\Enums\OrderStatus::RETURNED) {
            abort_unless(
                auth()->user()?->can('pos-refund') ?? false,
                403,
                'Permission insuffisante pour effectuer un remboursement.'
            );
        }

        try {
            return new OrderDetailsResource($this->orderService->changeStatus($order, $request));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $http) {
            throw $http; // 403 cross-branch / role guards must reach the client intact.
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function changePaymentStatus(Order $order, PaymentStatusRequest $request): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new OrderDetailsResource($this->orderService->changePaymentStatus($order, $request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function tokenCreate(Order $order, TableOrderTokenRequest $request): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new OrderDetailsResource($this->orderService->tokenCreate($order, $request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
