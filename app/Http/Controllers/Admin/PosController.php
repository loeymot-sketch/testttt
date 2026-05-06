<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Services\OrderService;
use App\Http\Requests\PosOrderRequest;
use App\Http\Resources\OrderDetailsResource;
use Illuminate\Http\JsonResponse;
use App\Rules\ValidJsonOrder;
use App\Services\Delivery\DeliveryFeeService;
use App\Services\Order\OrderQuoteService;
use App\Services\Pos\WalkInCustomerResolver;
use App\Enums\OrderType;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;


class PosController extends AdminController
{
    private OrderService $orderService;
    private OrderQuoteService $orderQuoteService;
    private WalkInCustomerResolver $walkInCustomerResolver;
    private DeliveryFeeService $deliveryFeeService;

    public function __construct(
        OrderService $order,
        OrderQuoteService $orderQuoteService,
        WalkInCustomerResolver $walkInCustomerResolver,
        DeliveryFeeService $deliveryFeeService
    )
    {
        parent::__construct();
        $this->orderService = $order;
        $this->orderQuoteService = $orderQuoteService;
        $this->walkInCustomerResolver = $walkInCustomerResolver;
        $this->deliveryFeeService = $deliveryFeeService;
        $this->middleware(['permission:pos'])->only('store');
    }

    public function store(PosOrderRequest $request): \Illuminate\Http\Response | OrderDetailsResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $this->normalizePosRuntimePayload($request);
            return new OrderDetailsResource($this->orderService->posOrderStore($request));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (HttpException $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], $exception->getStatusCode());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * Resolve the canonical walk-in customer (DB-first) for POS checkout when the
     * operator lacks CustomerController create/list permissions.
     */
    public function walkInCustomer(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('pos'), 403);
        $user = $this->walkInCustomerResolver->resolve();

        return response()->json([
            'status' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function quote(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'branch_id' => ['nullable', 'numeric'],
            'customer_id' => ['nullable', 'numeric'],
            'coupon_id' => ['nullable', 'numeric'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'delivery_charge' => ['nullable', 'numeric', 'min:0'],
            'delivery_distance_km' => ['nullable', 'numeric', 'min:0'],
            'order_type' => ['nullable', 'numeric'],
            'source' => ['nullable', 'numeric'],
            'payment_method' => ['nullable', 'numeric'],
            'pos_payment_method' => ['nullable', 'numeric'],
            'quote_token' => ['nullable', 'string', 'max:64'],
            'quote_signature' => ['nullable', 'string', 'size:64'],
            'consume' => ['nullable', 'boolean'],
            'items' => ['required', 'json', new ValidJsonOrder],
        ]);

        try {
            $this->normalizePosRuntimePayload($request);
            $surface = $request->is('api/frontend/*') ? 'kiosk' : (string) $request->input('surface', 'pos');
            $quote = $this->orderQuoteService->quote($request, $surface);

            return response()->json([
                'status' => true,
                'data' => $this->orderQuoteService->response($quote),
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (HttpException $exception) {
            return response()->json([
                'status' => false,
                'message' => $exception->getMessage(),
            ], $exception->getStatusCode());
        } catch (Exception $exception) {
            return response()->json([
                'status' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    private function normalizePosRuntimePayload(Request $request): void
    {
        $surface = $request->is('api/frontend/*') ? 'kiosk' : (string) $request->input('surface', 'pos');
        if ($surface !== 'pos') {
            return;
        }

        if ((int) $request->input('customer_id', 0) <= 0) {
            $request->merge([
                'customer_id' => $this->walkInCustomerResolver->resolve()->id,
            ]);
        }

        if ((int) $request->input('order_type', 0) === OrderType::DELIVERY && $request->filled('delivery_distance_km')) {
            $request->merge([
                'delivery_charge' => $this->deliveryFeeService->fromDistanceKm($request->input('delivery_distance_km')),
            ]);
        }
    }
}
