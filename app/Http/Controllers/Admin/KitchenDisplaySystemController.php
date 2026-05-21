<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Models\Order;
use Illuminate\Http\Request;
use App\Http\Requests\Kds\KdsOrderStatusRequest;
use App\Http\Resources\KDSOrderItemsResource;
use App\Http\Resources\KDSOrderDetailsResource;
use App\Services\KitchenDisplaySystemOrderService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class KitchenDisplaySystemController extends AdminController
{
    private KitchenDisplaySystemOrderService $kitchenDisplaySystemOrderService;

    public function __construct(KitchenDisplaySystemOrderService $kitchenDisplaySystemOrderService)
    {
        parent::__construct();
        $this->kitchenDisplaySystemOrderService = $kitchenDisplaySystemOrderService;
        $this->middleware(['permission:kitchen-display-system'])->only('index', 'changeStatus', 'orderItems', 'historyToday');
    }

    public function index(Request $request): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $orders = $this->kitchenDisplaySystemOrderService->list($request);

            return KDSOrderDetailsResource::collection($orders)->additional([
                'meta' => [
                    'overflow' => $this->kitchenDisplaySystemOrderService->lastListOverflow(),
                    'limit' => 50,
                ],
            ]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function changeStatus(Order $order, KdsOrderStatusRequest $request): \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $this->kitchenDisplaySystemOrderService->changeStatus($order, $request);
            return response('', 202);
        } catch (HttpException $e) {
            return response(['status' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function orderItems(): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return KDSOrderItemsResource::collection($this->kitchenDisplaySystemOrderService->orderItems());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * [Wave X3 2026-05-21] KDS "Historique du jour" — read-only V1.
     *
     * Owner mandate: chef sees all PREPARED/OUT/DELIVERED orders today to
     * verify content if a customer reports an error. Read-only — revert
     * (PREPARED → PREPARING) deferred to V1.0.2 because OrderStateMachine
     * (frozen §7) forbids reverse transitions and a LOCK plan + owner
     * countersign is required.
     */
    public function historyToday(): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return KDSOrderDetailsResource::collection($this->kitchenDisplaySystemOrderService->historyToday());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
