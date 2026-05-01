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
        $this->middleware(['permission:kitchen-display-system'])->only('index', 'changeStatus', 'orderItems');
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
}
