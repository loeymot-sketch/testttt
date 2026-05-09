<?php

namespace App\Http\Controllers\Frontend;


use Exception;
use App\Models\Order;
use App\Services\OrderService;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\OrderDetailsResource;
use App\Http\Resources\DeliveryBoyOrderCountResource;
use App\Http\Resources\SimpleDeliveryBoyOrderResource;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class DeliveryBoyOrderController extends Controller
{
    private OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    public function index(PaginateRequest $request): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return SimpleDeliveryBoyOrderResource::collection($this->orderService->deliveryBoyOrder($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(Order $order): \Illuminate\Http\Response | OrderDetailsResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new OrderDetailsResource($this->orderService->deliveryBoyOrderDetails($order));
        } catch (HttpExceptionInterface $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], $exception->getStatusCode());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function orderCount()
    {
        try {
            return new DeliveryBoyOrderCountResource($this->orderService->deliveryBoyOrderCount());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function deliveryBoyOrderChangeStatus(Order $order, Request $request)
    {
        try {
            $request->validate([
                'status' => ['required', 'integer'],
            ]);

            return new OrderDetailsResource($this->orderService->deliveryBoyOrderChangeStatus($order, $request));
        } catch (HttpExceptionInterface $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], $exception->getStatusCode());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
