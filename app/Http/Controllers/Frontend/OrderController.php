<?php

namespace App\Http\Controllers\Frontend;


use App\Http\Resources\UserOrderResource;
use Exception;
use App\Models\FrontendOrder;
use App\Http\Requests\OrderRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Requests\PaginateRequest;
use App\Services\FrontendOrderService;
use App\Http\Requests\OrderStatusRequest;
use App\Http\Resources\OrderDetailsResource;

class OrderController extends Controller
{
    private FrontendOrderService $frontendOrderService;

    public function __construct(FrontendOrderService $frontendOrderService)
    {
        $this->frontendOrderService = $frontendOrderService;
    }

    public function index(PaginateRequest $request): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return UserOrderResource::collection($this->frontendOrderService->myOrder($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function store(OrderRequest $request): \Illuminate\Http\Response | OrderDetailsResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new OrderDetailsResource($this->frontendOrderService->myOrderStore($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(FrontendOrder $frontendOrder): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {

        try {
            return new OrderDetailsResource($this->frontendOrderService->show($frontendOrder));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function changeStatus(FrontendOrder $frontendOrder, OrderStatusRequest $request): \Illuminate\Http\Response | OrderDetailsResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new OrderDetailsResource($this->frontendOrderService->changeStatus($frontendOrder, $request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * [BORNE-WINDOWS] Confirm card payment from physical terminal.
     * Called by the Electron app after the terminal approves the transaction.
     * Stores the transaction_id and marks the order as PAID.
     */
    public function paymentConfirm(FrontendOrder $frontendOrder, \Illuminate\Http\Request $request): \Illuminate\Http\Response|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $request->validate([
                'transaction_id' => ['required', 'string', 'max:255'],
                'card_type'      => ['nullable', 'string', 'max:50'],
                'payment_method' => ['nullable', 'integer'],
            ]);

            // Ensure the order belongs to the authenticated user
            if ($frontendOrder->user_id !== \Illuminate\Support\Facades\Auth::id()) {
                return response(['status' => false, 'message' => 'Unauthorized'], 403);
            }

            $frontendOrder->update([
                'payment_status' => \App\Enums\PaymentStatus::PAID,
                'payment_method' => $request->payment_method ?? $frontendOrder->payment_method,
            ]);

            \App\Models\ActionLog::create([
                'user_id'  => \Illuminate\Support\Facades\Auth::id(),
                'action'   => 'Paiement carte confirmé (borne)',
                'resource' => 'Commande #' . $frontendOrder->order_serial_no,
                'details'  => sprintf(
                    'Transaction: %s | Carte: %s',
                    $request->transaction_id,
                    $request->card_type ?? 'N/A'
                ),
            ]);

            return response(['status' => true, 'message' => 'Paiement confirmé'], 200);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
