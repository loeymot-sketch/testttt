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
use App\Enums\PaymentStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
            $order = $this->frontendOrderService->myOrderStore($request);
            // [AUDIT-P2] Include loyalty_applied flag so the kiosk can show a toast
            // if the client sent a loyalty discount that was silently dropped server-side
            // (e.g. race condition, insufficient points at commit time).
            return (new OrderDetailsResource($order))->additional([
                'loyalty_applied' => $this->frontendOrderService->loyaltyApplied,
            ]);
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
     * [BORNE-WINDOWS + SPLASH SECURITY] Confirm card payment from physical terminal.
     * Idempotent: calling twice with same transaction_id returns 200 without double-billing.
     * Called by the Electron app after TPE approves the transaction.
     */
    public function paymentConfirm(FrontendOrder $frontendOrder, \Illuminate\Http\Request $request): \Illuminate\Http\Response|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $request->validate([
                'transaction_id' => ['required', 'string', 'max:255'],
                'card_type'      => ['nullable', 'string', 'max:50'],
                'payment_method' => ['nullable', 'integer'],
            ]);
            $authenticatedUserId = $request->user('sanctum')?->id
                ?? $request->user()?->id
                ?? Auth::id();

            if (!$authenticatedUserId) {
                return response(['status' => false, 'message' => 'Unauthenticated'], 401);
            }
            $authenticatedUserId = (int) $authenticatedUserId;

            if ((int) $frontendOrder->user_id !== $authenticatedUserId) {
                return response(['status' => false, 'message' => 'Unauthorized'], 403);
            }

            $alreadyPaid = false;
            $promoted = false;

            DB::transaction(function () use ($frontendOrder, $request, &$alreadyPaid) {
                $locked = FrontendOrder::where('id', $frontendOrder->id)
                    ->lockForUpdate()
                    ->first();

                if ((int) $locked->payment_status === PaymentStatus::PAID) {
                    $alreadyPaid = true;
                    return;
                }

                $locked->payment_status = PaymentStatus::PAID;
                $locked->payment_method = $request->payment_method ?? $locked->payment_method;
                $locked->transaction_id = $request->transaction_id;
                $locked->card_type = $request->card_type;
                $locked->save();

                $frontendOrder->refresh();
            });

            $promoted = $this->frontendOrderService->finalizePaidKioskOrder(
                $frontendOrder->fresh()
            );

            if ($alreadyPaid && !$promoted) {
                return response([
                    'status'  => true,
                    'message' => 'Paiement déjà confirmé',
                    'data'    => ['order_id' => $frontendOrder->id],
                ], 200);
            }

            try {
                \App\Models\ActionLog::create([
                    'user_id'  => $authenticatedUserId,
                    'action'   => 'Paiement carte confirmé (borne)',
                    'resource' => 'Commande #' . $frontendOrder->order_serial_no,
                    'details'  => sprintf(
                        'Transaction: %s | Carte: %s',
                        $request->transaction_id,
                        $request->card_type ?? 'N/A'
                    ),
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[Kiosk] ActionLog write failed: ' . $e->getMessage());
            }

            return response(['status' => true, 'message' => 'Paiement confirmé', 'data' => ['order_id' => $frontendOrder->id]], 200);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
