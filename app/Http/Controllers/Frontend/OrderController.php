<?php

namespace App\Http\Controllers\Frontend;


use App\Http\Resources\UserOrderResource;
use Exception;
use App\Models\FrontendOrder;
use App\Models\Order;
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
use Illuminate\Http\Request;
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

    /**
     * [GOAL WEB COMMANDE Wave D 2026-07-28] Estimation d'attente retrait —
     * public (throttled route-side), dérivée de la file cuisine réelle via
     * WaitEstimateService (sémantique SSOT KitchenReleaseRule). SELECT-only.
     */
    public function waitEstimate(Request $request, \App\Services\WaitEstimateService $waitEstimateService): \Illuminate\Http\JsonResponse | \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return response()->json(
                $waitEstimateService->estimate((int) $request->input('branch_id', 1))
            );
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * [T-C SUIVI-CLIENT 2026-08-16 · GOAL owner] Suivi public d'UNE commande par
     * son `tracking_token` opaque (jamais l'id/serial, séquentiels et devinables).
     * `found=false` (jamais 404/500) sur un token inconnu — un lien expiré/mal
     * copié doit rester un écran propre côté client, pas une erreur brute.
     */
    public function track(string $trackingToken, \App\Services\OrderTrackingService $orderTrackingService): \Illuminate\Http\JsonResponse
    {
        return response()->json($orderTrackingService->track($trackingToken));
    }

    /**
     * [T-C SUIVI-CLIENT 2026-08-16 · GOAL owner] QR de la borne vers la page de
     * suivi publique — même mécanisme (simplesoftwareio/simple-qrcode, format
     * SVG, errorCorrection H) que WheelCounterController::kiosk()/enterWithPass(),
     * pour que le rendu et le comportement scanner soient cohérents dans toute
     * l'app. 404 franc (pas de found:false ici) : un <img> cassé se voit
     * immédiatement côté borne si le token est invalide, contrairement à un JSON
     * silencieux.
     */
    public function trackQr(string $trackingToken, \App\Services\OrderTrackingService $orderTrackingService): \Illuminate\Http\Response
    {
        if (! $orderTrackingService->findByToken($trackingToken)) {
            abort(404);
        }
        $url = url('/suivi/' . $trackingToken);
        $svg = \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')
            ->size(320)
            ->margin(1)
            ->errorCorrection('H')
            ->generate($url);

        return response($svg, 200)->header('Content-Type', 'image/svg+xml');
    }

    /**
     * [T-C SUIVI-CLIENT 2026-08-16] Position file / fourchette temps (SSOT
     * OrderTrackingService::forOrder) + tracking_token brut — la borne en a
     * besoin pour construire le lien/QR "suivez votre commande" (le token
     * n'a pas sa place dans OrderDetailsResource, consommée par bien d'autres
     * écrans qui n'ont rien à voir avec le suivi public).
     */
    private function trackingPayload(Order|FrontendOrder $order): array
    {
        return app(\App\Services\OrderTrackingService::class)->forOrder($order) + [
            'tracking_token' => $order->tracking_token,
        ];
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
                // [T-C SUIVI-CLIENT 2026-08-16] Position file + fourchette temps dès la
                // création — même calcul SSOT que la page de suivi publique (voir show()).
                'tracking' => $this->trackingPayload($order),
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
            $order = $this->frontendOrderService->show($frontendOrder);
            // [T-C SUIVI-CLIENT 2026-08-16] Polled by KioskWaitingComponent.vue — même
            // calcul SSOT (position file / fourchette temps) que la page de suivi
            // publique, pour que la borne et le téléphone du client affichent la
            // même chose.
            return (new OrderDetailsResource($order))->additional([
                'tracking' => $this->trackingPayload($order),
            ]);
        } catch (HttpException $exception) {
            // [W14 AUTHZ 2026-07-20] Préserver le code du service — l'abort(403) IDOR (heal
            // FRONT-SHOW-403-422, FrontendOrderService::show) était aplati en 422 par le
            // catch(Exception) ci-dessous. Même pattern que store() plus haut dans ce fichier.
            return response(['status' => false, 'message' => $exception->getMessage()], $exception->getStatusCode());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * [TICKET-UNIFY 2026-07-01] Octets ESC/POS du ticket borne (client|cuisine) rendus par le
     * MÊME service serveur que la caisse (EscPosTicketBytesService) → ticket papier IDENTIQUE.
     * La borne fetch ces octets et les POSTe au pont local (au lieu de reconstruire un ticket
     * côté client). Garde de propriété : le token borne ne peut imprimer QUE sa propre commande.
     */
    public function escpos(FrontendOrder $frontendOrder, Request $request): \Illuminate\Http\JsonResponse
    {
        $authenticatedUserId = (int) ($request->user('sanctum')?->id ?? $request->user()?->id ?? Auth::id() ?? 0);
        if (! $authenticatedUserId) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated'], 401);
        }
        $kioskMachine = KioskMachine::query()->where('user_id', $authenticatedUserId)->first();
        if (! $kioskMachine) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
        }
        if ((int) $frontendOrder->user_id !== $authenticatedUserId
            || (int) $frontendOrder->branch_id !== (int) $kioskMachine->branch_id) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
        }

        $ticket = $request->query('ticket') === 'kitchen' ? 'kitchen' : 'client';
        // [TICKET-BORNE-LONG 2026-07-02] kioskClient=true → ticket client borne LONG + coupe
        // partielle (ne tombe pas). La caisse (PosTicketBytesController) reste en défaut court.
        $bytes = app(\App\Services\Hardware\EscPosTicketBytesService::class)
            ->render((int) $frontendOrder->branch_id, (int) $frontendOrder->id, $ticket, false, true);

        return response()->json([
            'order_id'   => $frontendOrder->id,
            'ticket'     => $ticket,
            'escpos_b64' => $bytes === null ? null : base64_encode($bytes),
        ]);
    }

    public function changeStatus(FrontendOrder $frontendOrder, OrderStatusRequest $request): \Illuminate\Http\Response | OrderDetailsResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new OrderDetailsResource($this->frontendOrderService->changeStatus($frontendOrder, $request));
        } catch (HttpException $exception) {
            // [W14 AUTHZ 2026-07-20] idem show() : préserver le 403 IDOR (service abort) au lieu de 422.
            return response(['status' => false, 'message' => $exception->getMessage()], $exception->getStatusCode());
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

            // [AUDIT-F-002] Pre-transaction branch check — préserve la priorité 403
            // pour les rejets cross-branch AVANT le guard F-002 amount echo.
            if ((int) $frontendOrder->branch_id !== (int) $kioskMachine->branch_id) {
                return response(['status' => false, 'message' => 'Unauthorized'], 403);
            }

            // [AUDIT-F-002] TPE Amount Echo Verification — gate AVANT toute mutation state.
            // NF525 + PCI-DSS exigent que le montant approuvé par le TPE corresponde à order.total.
            // Tolérance ±1 centime pour absorber les arrondis flottants. Au-delà = anomalie.
            // Error code stable AMOUNT_ECHO_MISMATCH utilisé par dashboards ops — NE PAS CHANGER.
            $expectedCents = (int) round((float) $frontendOrder->total * 100);
            $providedCents = (int) $request->input('amount_cents');
            if (abs($providedCents - $expectedCents) > 1) {
                \Illuminate\Support\Facades\Log::warning('[Kiosk Payment] amount echo mismatch', [
                    'order_id'       => $frontendOrder->id,
                    'expected_cents' => $expectedCents,
                    'provided_cents' => $providedCents,
                    'transaction_id' => $request->input('transaction_id'),
                    'gate'           => 'AUDIT-F-002',
                ]);
                return response([
                    'status'     => false,
                    'message'    => 'Amount approved by TPE does not match order total',
                    'error_code' => 'AMOUNT_ECHO_MISMATCH',
                ], 422);
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

            // [test-e2e fix B-001 round-2 2026-05-10] Payment is already persisted at
            // this point (DB::transaction above committed payment_status=PAID +
            // transaction_id at lines 215-220 / 198-202). The remaining work in
            // finalizePaidKioskOrder() is fiscal_sequence allocation + post-commit
            // event dispatch (OrderCreated → SendFcmOnOrderCreated → FCM job).
            // Under QUEUE_CONNECTION=sync, any FCM dispatch failure
            // (RuntimeException 'FCM send failed — will retry') propagates up
            // through the event dispatcher into our outer catch(Exception) and
            // returns 422 to the kiosk — even though the payment IS committed.
            // Wave B audit (rush-hour-50x50-2026-05-10/round-1) observed 35/35
            // 422 responses on this code path with payment_status=PAID +
            // transaction_id persisted server-side. Result: silent error in
            // kiosk-toast-container (empty), kiosk retries 3× then queues a
            // reconcile-event despite payment already being final.
            //
            // FCM and other post-commit side-effects are best-effort: their
            // failure MUST NOT invalidate the HTTP success contract that
            // payment_status=PAID + transaction_id are committed. The
            // fiscal_sequence_no allocation path inside finalizePaidKioskOrder
            // is already protected (see FrontendOrderService:1132-1167 which
            // sets fiscal_alloc_error_at and returns promoted=false WITHOUT
            // throwing). So catching Throwable here is safe — the only
            // observable behaviour change is: the response is now 200 (was
            // 422) when a post-commit side-effect throws.
            $promoted = false;
            try {
                $promoted = $this->frontendOrderService->finalizePaidKioskOrder(
                    $frontendOrder->fresh()
                );
            } catch (\Throwable $sideEffectException) {
                \Illuminate\Support\Facades\Log::warning('[Kiosk Payment] finalizePaidKioskOrder side-effect failed (non-blocking)', [
                    'order_id'       => $frontendOrder->id,
                    'transaction_id' => $request->input('transaction_id'),
                    'error'          => $sideEffectException->getMessage(),
                    'gate'           => 'test-e2e-fix-B-001-round-2',
                ]);
                // Do not bubble — payment is persisted. The retry cron
                // (foodking:fiscal:retry-alloc) plus the outbox SSOT
                // (PersistOrderCreatedToOutbox runs FIRST in the listener
                // chain per EventServiceProvider:142) ensure eventual
                // consistency for KDS/Kiosk/POS sync and Z aggregation.
            }

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
