<?php

namespace App\Http\Controllers\Table;


use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\TableOrderRequest;
use App\Models\FrontendOrder;
use App\Models\Order;
use Exception;
use Illuminate\Http\Request;
use App\Services\OrderService;
use App\Http\Resources\OrderDetailsResource;


class OrderController extends Controller
{
    private OrderService $orderService;

    public function __construct(OrderService $order)
    {
        $this->orderService = $order;
    }

    public function store(TableOrderRequest $request): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new OrderDetailsResource($this->orderService->tableOrderStore($request));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function show(FrontendOrder $frontendOrder, Request $request): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        // [abuse-heal 2026-06-19 table-show-idor] BOLA / PII-enumeration guard.
        // This route is intentionally UNAUTHENTICATED (QR table flow, apiKey only),
        // so route-model-binding resolves ANY FrontendOrder by its id — and every
        // kiosk/borne/web order is a FrontendOrder. Without an ownership check a
        // holder of the shared apiKey could enumerate show/1, show/2, … and read
        // full PII (customer name/phone/email, address, items, totals) for every
        // order. We require the caller to present the order's OWN per-order token
        // as a capability: the legit client receives this token in the create
        // response (OrderDetailsResource:'token'); an id-enumerating attacker has
        // the id but NOT the token. hash_equals = constant-time; a null/empty
        // order token or an empty provided token can never satisfy the gate.
        $provided = (string) $request->query('token', $request->input('token', ''));
        abort_unless(
            filled($frontendOrder->token)
                && filled($provided)
                && hash_equals((string) $frontendOrder->token, $provided),
            403
        );

        // Defense-in-depth: this is the table flow — it must only ever expose
        // DINING_TABLE orders, never an arbitrary kiosk/web/delivery order that
        // happens to share a (guessed or reused) token. 404 to avoid confirming
        // the order's existence to a caller it isn't meant to serve.
        abort_unless((int) $frontendOrder->order_type === OrderType::DINING_TABLE, 404);

        try {
            return new OrderDetailsResource($frontendOrder);
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }
}