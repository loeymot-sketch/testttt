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
use App\Enums\PaymentGateway;
use App\Enums\OrderStatus;
use App\Exceptions\Delivery\GeocodeUnavailableException;
use App\Http\Requests\Frontend\PaymentConfirmRequest;
use App\Models\KioskMachine;
use App\Models\Scopes\BranchScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Validation\ValidationException;

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
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (GeocodeUnavailableException $exception) {
            return response([
                'status' => false,
                'code' => GeocodeUnavailableException::ERROR_CODE,
                'message' => $exception->getMessage(),
            ], $exception->getStatusCode());
        } catch (HttpException $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], $exception->getStatusCode());
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
    public function paymentConfirm(FrontendOrder $frontendOrder, PaymentConfirmRequest $request): \Illuminate\Http\Response|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        // [BYPASS-P2] Audit-log structuré si payment.bypass.enabled — invariants
        // (sealing fiscal, Outbox, audit, idempotency) restent intacts ci-dessous.
        \App\Services\Bypass\BypassAuditLogger::paymentBypassed([
            'controller' => 'Frontend\\OrderController::paymentConfirm',
            'order_id' => $frontendOrder->id,
            'transaction_id' => $request->input('transaction_id'),
        ]);

        try {
            $authenticatedUserId = $request->user('sanctum')?->id
                ?? $request->user()?->id
                ?? Auth::id();

            if (!$authenticatedUserId) {
                return response(['status' => false, 'message' => 'Unauthenticated'], 401);
            }
            $authenticatedUserId = (int) $authenticatedUserId;

            $kioskMachine = KioskMachine::query()
                ->where('user_id', $authenticatedUserId)
                ->first();

            if (!$kioskMachine) {
                return response(['status' => false, 'message' => 'Unauthorized'], 403);
            }

            if ((int) $frontendOrder->user_id !== $authenticatedUserId) {
                return response(['status' => false, 'message' => 'Unauthorized'], 403);
            }

            $alreadyPaid = false;
            $lateAfterCleanup = false;
            $nonConfirmableStatus = null;

            DB::transaction(function () use ($frontendOrder, $request, $kioskMachine, &$alreadyPaid, &$lateAfterCleanup, &$nonConfirmableStatus) {
                $locked = FrontendOrder::withoutGlobalScope(BranchScope::class)
                    ->where('id', $frontendOrder->id)
                    ->lockForUpdate()
                    ->first();

                if (!$locked) {
                    abort(404);
                }

                if ((int) $locked->branch_id !== (int) $kioskMachine->branch_id) {
                    abort(403, 'Unauthorized');
                }

                if (!in_array((int) $locked->payment_method, [PaymentGateway::CARD, PaymentGateway::TICKET_RESTAURANT], true)) {
                    throw ValidationException::withMessages([
                        'payment_method' => 'This order is not waiting for a deferred kiosk card payment.',
                    ]);
                }

                if ($request->filled('payment_method') && (int) $request->payment_method !== (int) $locked->payment_method) {
                    throw ValidationException::withMessages([
                        'payment_method' => 'Payment method does not match the original kiosk order.',
                    ]);
                }

                $duplicateTransaction = FrontendOrder::withoutGlobalScope(BranchScope::class)
                    ->where('transaction_id', $request->transaction_id)
                    ->where('id', '!=', $locked->id)
                    ->exists();

                if ($duplicateTransaction) {
                    abort(409, 'This payment transaction is already attached to another order.');
                }

                if ((int) $locked->payment_status === PaymentStatus::PAID) {
                    if (filled($locked->transaction_id) && (string) $locked->transaction_id !== (string) $request->transaction_id) {
                        abort(409, 'This order is already paid with a different payment transaction.');
                    }

                    if (blank($locked->transaction_id)) {
                        $locked->transaction_id = $request->transaction_id;
                        $locked->card_type = $request->card_type;
                        $locked->save();
                    }

                    $alreadyPaid = true;
                    $frontendOrder->setRawAttributes($locked->getAttributes(), true);
                    return;
                }

                if ((int) $locked->status !== OrderStatus::PENDING) {
                    $nonConfirmableStatus = (int) $locked->status;
                    $lateAfterCleanup = in_array((int) $locked->status, [OrderStatus::REJECTED, OrderStatus::CANCELED], true);
                    return;
                }

                $locked->payment_status = PaymentStatus::PAID;
                $locked->transaction_id = $request->transaction_id;
                $locked->card_type = $request->card_type;
                $locked->save();

                $frontendOrder->setRawAttributes($locked->getAttributes(), true);
            });

            if ($nonConfirmableStatus !== null) {
                try {
                    \App\Models\ActionLog::create([
                        'user_id' => $authenticatedUserId,
                        'action' => $lateAfterCleanup ? 'payment_late_after_cleanup' : 'payment_confirm_invalid_status',
                        'resource' => 'Commande #' . $frontendOrder->order_serial_no,
                        'details' => sprintf(
                            'Kiosk payment confirm rejected for non-confirmable status=%s.',
                            $nonConfirmableStatus
                        ),
                    ]);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[Kiosk] Rejected payment ActionLog write failed: ' . $e->getMessage());
                }

                return response(['status' => false, 'message' => 'Payment confirmation is no longer accepted for this order.'], 422);
            }

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
        } catch (HttpException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
