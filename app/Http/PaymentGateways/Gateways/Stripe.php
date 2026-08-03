<?php

namespace App\Http\PaymentGateways\Gateways;

use App\Enums\Activity;
use App\Enums\PaymentStatus;
use App\Events\RefundCreated;
use App\Models\CapturePaymentNotification;
use App\Models\Currency;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\WebhookEvent;
use App\Services\Fiscal\AuditLogService;
use App\Services\Order\SealedOrderGuard;
use App\Services\PaymentAbstract;
use App\Services\PaymentService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Smartisan\Settings\Facades\Settings;
use Stripe as StripeClient;
use Throwable;

class Stripe extends PaymentAbstract
{

    public bool $response = false;

    public function __construct()
    {
        $paymentService = new PaymentService();
        parent::__construct($paymentService);

        $this->paymentGateway = PaymentGateway::with('gatewayOptions')->where(['slug' => 'stripe'])->first();
        if (!blank($this->paymentGateway)) {
            $this->paymentGatewayOption = $this->paymentGateway->gatewayOptions->pluck('value', 'option');

            // [GOAL-SYNC 2026-07-08] Fix défensif P1 (w1/by-spec/backend_stripe-gateway.json) :
            // stripe_secret DB est VIDE en V1 « Stripe OFF » et StripeClient jette
            // « api_key cannot be the empty string » dès le constructeur — donc tout
            // POST /payment/stripe-webhook/ crashait 500 AVANT la vérification de
            // signature. On n'instancie le client QUE si la clé est non-vide.
            // `$this->gateway` reste NON-initialisée sinon (PaymentAbstract la type
            // `object` non-nullable — null impossible) ; les points d'entrée publics
            // gardent via isStripeConfigured() et répondent 503 proprement.
            $stripeSecret = (string) ($this->paymentGatewayOption['stripe_secret'] ?? '');
            if ($stripeSecret !== '') {
                $this->gateway = new StripeClient\StripeClient($stripeSecret);
            }
        }
    }

    /**
     * [GOAL-SYNC 2026-07-08] Le client Stripe n'est instancié par le
     * constructeur que si la clé secrète DB est non-vide. `isset()` sur la
     * propriété typée non-initialisée retourne false — c'est le « client
     * null » de facto (PaymentAbstract::$gateway est `object` non-nullable).
     */
    private function isStripeConfigured(): bool
    {
        return isset($this->gateway);
    }

    /**
     * [GOAL-SYNC 2026-07-08] Réponse propre 503 (Service Unavailable) quand
     * la passerelle Stripe n'est pas configurée — jamais de 500 crash.
     */
    private function unconfiguredResponse(): JsonResponse
    {
        return response()->json([
            'status'  => false,
            'message' => 'Stripe non configuré.',
        ], 503);
    }

    public function payment($order, $request) : \Illuminate\Http\RedirectResponse|JsonResponse
    {
        // [GOAL-SYNC 2026-07-08] Garde défensive : clé stripe_secret vide →
        // client jamais instancié → répondre 503 JSON propre au lieu de
        // laisser un accès à une propriété non-initialisée crasher en 500.
        if (!$this->isStripeConfigured()) {
            Log::channel('fiscal')->error('stripe.payment.unconfigured', [
                'event'    => 'stripe_gateway_secret_missing',
                'order_id' => $order->id ?? null,
            ]);

            return $this->unconfiguredResponse();
        }

        try {
            $currencyCode = 'USD';
            $currencyId   = Settings::group('site')->get('site_default_currency');
            if (!blank($currencyId)) {
                $currency = Currency::find($currencyId);
                if ($currency) {
                    $currencyCode = $currency->code;
                }
            }

            // [P0-6 CTO audit 2026-05-16] Use round-before-cast to prevent
            // cents truncation. Previously `(int) $order->total * 100` cast
            // $total to int FIRST (dropping decimals) then multiplied by 100,
            // so €9.99 became 900 cents (€9.00) — €0.99 revenue loss per
            // order + NF525 receipt/payment mismatch. Pattern matches the
            // already-correct callsites at OrderController:137,
            // PaymentReconcileController:173, SplitPaymentService:103/110.
            //
            // [P0-POS-01 GOAL round-2 2026-05-18] Inject `metadata.order_id`
            // so the webhook handler (`handleWebhook` at line 273-289) can
            // correlate the charge back to the originating order. Without
            // this, any out-of-band webhook receipt (storm retries, async
            // capture, DLQ replay) loses the order linkage and the
            // `CapturePaymentNotification` row is NEVER written → the order
            // silently stays PENDING. Webhook handler reads
            // `$charge->metadata->order_id`; Stripe coerces non-string values
            // to strings in metadata, so we cast explicitly.
            $response = $this->gateway->charges->create([
                'amount'      => (int) round((float) $order->total * 100),
                'currency'    => $currencyCode,
                'source'      => $request->stripeToken,
                'description' => 'Food order payment',
                'metadata'    => [
                    'order_id' => (string) $order->id,
                ],
            ]);

            if (isset($response->status) && $response->status == 'succeeded') {
                $capturePaymentNotification = DB::table('capture_payment_notifications')->where([
                    ['order_id', $order->id]
                ]);
                $capturePaymentNotification?->delete();
                $token = $response->balance_transaction;
                CapturePaymentNotification::create([
                    'order_id'   => $order->id,
                    'token'      => $token,
                    'created_at' => now()
                ]);
                return redirect()->away(
                    route('payment.success', ['paymentGateway' => 'stripe', 'order' => $order, 'token' => $token])
                );
            } else {
                return redirect()->route('payment.index', ['order' => $order, 'paymentGateway' => 'stripe'])->with(
                    'error',
                    trans('all.message.something_wrong')
                );
            }
        } catch (Exception $e) {
            Log::info($e->getMessage());
            return redirect()->route('payment.index', ['order' => $order, 'paymentGateway' => 'stripe'])->with(
                'error',
                $e->getMessage()
            );
        }
    }

    public function status() : bool
    {
        $paymentGateways = PaymentGateway::where(['slug' => 'stripe', 'status' => Activity::ENABLE])->first();
        if ($paymentGateways) {
            return true;
        }
        return false;
    }

    public function success($order, $request) : \Illuminate\Http\RedirectResponse
    {
        try {
            DB::transaction(function () use ($order, $request) {
                if ($request->token) {
                    $capturePaymentNotification = DB::table('capture_payment_notifications')->where([
                        ['token', $request->token]
                    ]);
                    $token              = $capturePaymentNotification->first();
                    if (!blank($token) && $order->id == $token->order_id) {
                        $this->paymentService->payment($order, 'stripe', $token->token);
                        $capturePaymentNotification->delete();
                        $this->response = true;
                    }
                }
            });

            if ($this->response) {
                return redirect()->route('payment.successful', ['order' => $order])->with(
                    'success',
                    trans('all.message.payment_successful')
                );
            }
            return redirect()->route('payment.fail', ['order' => $order, 'paymentGateway' => 'stripe'])->with(
                'error',
                trans('all.message.something_wrong')
            );
        } catch (Exception $e) {
            Log::info($e->getMessage());
            DB::rollBack();
            return redirect()->route('payment.fail', ['order' => $order, 'paymentGateway' => 'stripe'])->with(
                'error',
                $e->getMessage()
            );
        }
    }

    public function fail($order, $request) : \Illuminate\Http\RedirectResponse
    {
        return redirect()->route('payment.index', ['order' => $order])->with(
            'error',
            trans('all.message.something_wrong')
        );
    }

    public function cancel($order, $request) : \Illuminate\Http\RedirectResponse
    {
        return redirect()->route('home')->with('error', trans('all.message.payment_canceled'));
    }

    /**
     * [Sprint 3A — Webhook idempotency 2026-05-16]
     *
     * Stripe webhook callback handler.
     *
     * Idempotency contract:
     *  - Provider may retry the same event under storms / 5xx hiccups.
     *  - `WebhookEvent::firstOrCreate(provider=stripe, webhook_id=event.id)`
     *    yields single-processing semantics, defended at the DB layer by
     *    UNIQUE (provider, webhook_id) so concurrent retries cannot bypass.
     *  - Duplicates return HTTP 200 `duplicate_ignored` so Stripe stops
     *    retrying without surfacing as an outage on their side.
     *
     * Signature verification:
     *  - Uses `\Stripe\Webhook::constructEvent($payload, $sigHeader, $secret)`.
     *  - Secret pulled from `config('services.stripe.webhook_secret')`
     *    (provisioned in Stripe Dashboard → Webhooks). Falsey secret => 500
     *    misconfig (never silently bypass — that would defeat the audit).
     *  - Invalid signature => 400 (Stripe will not retry signature errors).
     *
     * On success:
     *  - Mirrors the existing `payment()` flow: creates a
     *    `CapturePaymentNotification` so the success controller can pick up
     *    the charge token. Only fires for `charge.succeeded` event types to
     *    avoid double-firing on intent-status updates.
     *  - Marks the WebhookEvent processed + records the order_id link.
     *
     * On failure (post-firstOrCreate):
     *  - Marks event status=failed + records the exception message so a
     *    dead-letter cron can re-drive (attempts counter visible in
     *    webhook_events).
     */
    public function handleWebhook(Request $request) : JsonResponse
    {
        // [GOAL-SYNC 2026-07-08] Fix P1 : avant ce garde, stripe_secret DB
        // vide faisait crasher le CONSTRUCTEUR (StripeClient refuse une
        // api_key vide) → 500 avant toute vérification de signature. Le
        // constructeur est désormais lazy ; gateway non configurée → 503
        // JSON propre. La vérification de signature + idempotence + DLQ
        // ci-dessous restent STRICTEMENT inchangées.
        if (!$this->isStripeConfigured()) {
            Log::channel('fiscal')->error('stripe.webhook.unconfigured', [
                'event' => 'stripe_gateway_secret_missing',
                'ip'    => $request->ip(),
            ]);

            return $this->unconfiguredResponse();
        }

        $secret = (string) config('services.stripe.webhook_secret', '');
        if ($secret === '') {
            Log::channel('fiscal')->error('stripe.webhook.misconfigured', [
                'event' => 'stripe_webhook_secret_missing',
            ]);
            return response()->json([
                'error'   => 'misconfigured',
                'message' => 'Stripe webhook secret not configured.',
            ], 500);
        }

        $payload   = $request->getContent();
        $sigHeader = (string) $request->header('Stripe-Signature', '');

        try {
            // [F-3 SYNC P1 V1.0.1 quick win — 2026-05-19]
            // Pass an explicit 300s replay-tolerance (Stripe SDK default).
            // Without this, constructEvent silently accepts arbitrarily-old
            // signed payloads up to the WebhookEvent retention horizon (180d),
            // widening the replay-attack window. 300s is the canonical Stripe
            // recommendation for production.
            // Source: reports/audit/foundation-2026-05-18/round-1/F-3-SYNC/STATUS.md §P1
            // Sentinel: tests/Feature/Sentinels/StripeWebhookReplayToleranceSentinelTest.php
            $stripeEvent = StripeClient\Webhook::constructEvent($payload, $sigHeader, $secret, 300);
        } catch (StripeClient\Exception\SignatureVerificationException $e) {
            Log::channel('fiscal')->warning('stripe.webhook.invalid_signature', [
                'event'   => 'stripe_webhook_invalid_signature',
                'message' => $e->getMessage(),
                'ip'      => $request->ip(),
            ]);
            return response()->json([
                'error'   => 'invalid_signature',
                'message' => 'Stripe webhook signature verification failed.',
            ], 400);
        } catch (Throwable $e) {
            Log::channel('fiscal')->error('stripe.webhook.payload_parse_error', [
                'event'   => 'stripe_webhook_parse_error',
                'message' => $e->getMessage(),
            ]);
            return response()->json([
                'error'   => 'invalid_payload',
                'message' => 'Stripe webhook payload could not be parsed.',
            ], 400);
        }

        $eventId   = (string) ($stripeEvent->id ?? '');
        $eventType = (string) ($stripeEvent->type ?? 'unknown');
        if ($eventId === '') {
            return response()->json([
                'error'   => 'invalid_payload',
                'message' => 'Stripe event id missing.',
            ], 400);
        }

        // Idempotency ledger — DB UNIQUE (provider, webhook_id) is the
        // atomicity floor; firstOrCreate is the app-level guard above it.
        $event = WebhookEvent::firstOrCreate(
            [
                'provider'   => WebhookEvent::PROVIDER_STRIPE,
                'webhook_id' => $eventId,
            ],
            [
                'event_type'  => $eventType,
                'payload'     => $stripeEvent->toArray(),
                'signature'   => $sigHeader !== '' ? mb_substr($sigHeader, 0, 512) : null,
                'received_at' => now(),
                'status'      => WebhookEvent::STATUS_PENDING,
            ]
        );

        if (!$event->wasRecentlyCreated) {
            Log::channel('fiscal')->info('stripe.webhook.duplicate_ignored', [
                'event'    => 'stripe_webhook_duplicate_ignored',
                'event_id' => $eventId,
                'type'     => $eventType,
            ]);
            return response()->json(['status' => 'duplicate_ignored'], 200);
        }

        try {
            DB::transaction(function () use ($stripeEvent, $event, $eventType) {
                // Only bridge charge.succeeded into the legacy
                // CapturePaymentNotification table (mirrors payment()).
                // Other event types are still recorded for forensic audit
                // but do not trigger a payment capture row.
                if ($eventType === 'charge.succeeded') {
                    $charge = $stripeEvent->data->object ?? null;
                    $token  = $charge->balance_transaction ?? null;
                    $orderId = null;

                    // Stripe charges carry the order_id via metadata when
                    // the payment() method initiated them (extend metadata
                    // in payment() in a future iteration). For now, fall
                    // back to the charge id when metadata is absent so we
                    // never lose the bridge token.
                    if (isset($charge->metadata) && is_object($charge->metadata)) {
                        $orderId = isset($charge->metadata->order_id)
                            ? (int) $charge->metadata->order_id
                            : null;
                    }

                    if (!blank($token) && $orderId !== null) {
                        DB::table('capture_payment_notifications')->where([
                            ['order_id', $orderId],
                        ])->delete();

                        CapturePaymentNotification::create([
                            'order_id'   => $orderId,
                            'token'      => (string) $token,
                            'created_at' => now(),
                        ]);
                    }

                    $event->markProcessed($orderId);
                } elseif ($eventType === 'charge.refunded') {
                    // [GOAL-K2-HEAL-04 2026-05-24] Phase K.8 K8-F-03 P1:
                    // Owner manually refunds via Stripe dashboard → webhook
                    // fires `charge.refunded`. Pre-heal: this event was
                    // bucketed into the `else` branch (forensic log only),
                    // so Order stayed PAID, Z-report diverged from Stripe
                    // payout, stock not released, no realtime broadcast.
                    // End-of-month reconciliation = manual nightmare.
                    //
                    // Bridge: dispatch RefundCreated so the existing listener
                    // cascade fires (PersistOrderPaymentStatusChangedOnRefundCreated
                    // → mutates payment_status=REFUNDED + broadcasts via
                    // OrderPaymentStatusChanged outbox; ReleaseStockOnRefundCreated;
                    // ReleaseAvailabilityOnRefundCreated; ClawbackLoyaltyPointsOnRefund).
                    //
                    // IMPORTANT: do NOT pre-mutate payment_status to REFUNDED
                    // here — the listener at PersistOrderPaymentStatusChangedOnRefundCreated.php:81-83
                    // early-returns when payment_status is already REFUNDED,
                    // which would suppress the realtime broadcast. Let the
                    // listener cascade own the mutation + broadcast.
                    //
                    // Idempotency: WebhookEvent::firstOrCreate (lines 255-275)
                    // short-circuits storm retries at the HTTP layer (same
                    // event.id → 200 duplicate_ignored, never reach this
                    // branch). Within a single event, we early-return on
                    // payment_status === REFUNDED so a re-drive via DLQ
                    // does not re-fire the cascade.
                    //
                    // Sealed parent (post-Z order): the listener
                    // PersistOrderPaymentStatusChangedOnRefundCreated is
                    // sealed-aware (skips mutation, still broadcasts).
                    // However, no mirror counter-entry is auto-created here
                    // — the NF525 mirror must be produced via
                    // RefundWithCounterEntryService by ops tooling.
                    // V1 scope: log a structured warning so the divergence
                    // is observable in the fiscal channel; V1.0.2 backlog
                    // will wire automatic mirror creation. This keeps the
                    // webhook path NF525-conservative (no fiscal-sequence
                    // allocation under webhook concurrency).
                    $charge = $stripeEvent->data->object ?? null;
                    $chargeId = (string) ($charge->id ?? '');
                    $amountRefunded = isset($charge->amount_refunded)
                        ? ((int) $charge->amount_refunded) / 100
                        : null;

                    $orderId = $this->resolveOrderFromCharge($charge);
                    if ($orderId === null) {
                        Log::channel('fiscal')->warning('stripe.webhook.charge_refunded.unknown_order', [
                            'event'     => 'stripe_charge_refunded_unknown_order',
                            'event_id'  => $stripeEvent->id ?? null,
                            'charge_id' => $chargeId,
                        ]);
                        $event->markProcessed();
                    } else {
                        $order = Order::find($orderId);
                        if (! $order instanceof Order) {
                            Log::channel('fiscal')->warning('stripe.webhook.charge_refunded.order_not_found', [
                                'event'     => 'stripe_charge_refunded_order_not_found',
                                'event_id'  => $stripeEvent->id ?? null,
                                'charge_id' => $chargeId,
                                'order_id'  => $orderId,
                            ]);
                            $event->markProcessed();
                        } elseif ((int) $order->payment_status === PaymentStatus::REFUNDED) {
                            // Already refunded — idempotent skip.
                            Log::channel('fiscal')->info('stripe.webhook.charge_refunded.already_refunded', [
                                'event'     => 'stripe_charge_refunded_already_refunded',
                                'event_id'  => $stripeEvent->id ?? null,
                                'charge_id' => $chargeId,
                                'order_id'  => $orderId,
                            ]);
                            $event->markProcessed($orderId);
                        } else {
                            // Observability for sealed parent (post-Z) — no
                            // mirror counter-entry is created in the webhook
                            // path. Mirror via RefundWithCounterEntryService
                            // is V1.0.2 backlog. Log so reconciliation sees
                            // the divergence at the source.
                            $sealedNote = null;
                            try {
                                app(SealedOrderGuard::class)
                                    ->assertMutable($order, 'stripe-charge-refunded-webhook');
                            } catch (\App\Exceptions\OrderSealedException) {
                                $sealedNote = 'parent_sealed_mirror_deferred_v1_0_2';
                                Log::channel('fiscal')->warning('stripe.webhook.charge_refunded.sealed_parent', [
                                    'event'     => 'stripe_charge_refunded_sealed_parent',
                                    'event_id'  => $stripeEvent->id ?? null,
                                    'charge_id' => $chargeId,
                                    'order_id'  => $orderId,
                                    'note'      => 'Z-window closed; counter-entry must be created manually via RefundWithCounterEntryService.',
                                ]);
                            }

                            // NF525 audit trail — append-only via the frozen
                            // AuditLogService public API. Must precede the
                            // event dispatch so the audit row commits inside
                            // the same DB::transaction as the WebhookEvent
                            // status flip. DispatchableAfterCommit makes the
                            // RefundCreated cascade fire post-commit.
                            app(AuditLogService::class)->write([
                                'branch_id'   => (int) $order->branch_id,
                                'action'      => 'order.refunded.stripe_dashboard',
                                'resource'    => 'order',
                                'resource_id' => (int) $order->id,
                                'payload'     => array_filter([
                                    'order_id'        => (int) $order->id,
                                    'charge_id'       => $chargeId,
                                    'amount_refunded' => $amountRefunded,
                                    'source'          => 'stripe_dashboard_webhook',
                                    'webhook_id'      => (string) ($stripeEvent->id ?? ''),
                                    'sealed_note'     => $sealedNote,
                                ], static fn ($v) => $v !== null && $v !== ''),
                            ]);

                            // Bridge to RefundCreated cascade. Listener order
                            // (EventServiceProvider:182-196):
                            //   1) PersistOrderPaymentStatusChangedOnRefundCreated
                            //      → payment_status=REFUNDED + outbox broadcast
                            //   2) ReleaseStockOnRefundCreated
                            //   3) ReleaseAvailabilityOnRefundCreated
                            //   4) ClawbackLoyaltyPointsOnRefund
                            // Empty refundedItems array signals "full refund"
                            // per RefundCreated PHPDoc — matches Stripe's
                            // dashboard semantics (full charge refund).
                            RefundCreated::dispatch($order);

                            $event->markProcessed($orderId);
                        }
                    }
                } else {
                    // Non-capture events: log only, mark processed so we
                    // don't retry. Forensic payload remains queryable.
                    $event->markProcessed();
                }
            });
        } catch (Throwable $e) {
            Log::channel('fiscal')->error('stripe.webhook.processing_failed', [
                'event'    => 'stripe_webhook_processing_failed',
                'event_id' => $eventId,
                'type'     => $eventType,
                'message'  => $e->getMessage(),
            ]);
            $event->markFailed($e->getMessage());
            // Return 500 so Stripe retries — the event row stays for
            // re-drive observability.
            return response()->json([
                'error'   => 'processing_failed',
                'message' => 'Stripe webhook processing failed; will retry.',
            ], 500);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * [GOAL-K2-HEAL-04 2026-05-24] Phase K.8 K8-F-03 P1.
     *
     * Resolve the originating Order id from a Stripe charge object received
     * over the `charge.refunded` (or `charge.succeeded`) webhook. The
     * canonical link is `metadata.order_id`, injected by {@see payment()}
     * at line 73 since the P0-POS-01 round-2 fix (2026-05-18).
     *
     * Fallback chain:
     *  1) `charge->metadata->order_id` — canonical, set by our payment()
     *     call when initiating the Stripe charge.
     *  2) (future) lookup by `charge->payment_intent` against an orders
     *     table column — pending V1.0.2 schema addition.
     *
     * Returns `null` when no link can be established (legacy charges,
     * charges initiated outside FoodKing). Callers must log + skip — never
     * raise (Stripe would retry forever for events we cannot reconcile).
     */
    private function resolveOrderFromCharge($charge): ?int
    {
        if (! is_object($charge)) {
            return null;
        }

        if (isset($charge->metadata) && is_object($charge->metadata)
            && isset($charge->metadata->order_id)
            && (string) $charge->metadata->order_id !== ''
        ) {
            $candidate = (int) $charge->metadata->order_id;
            if ($candidate > 0) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * [Sprint H3 P1-Z8-02 2026-05-17] DLQ re-entry — replay a stored
     * `webhook_events` row through the gateway's processing chain.
     *
     * Called by `ProcessWebhookEventJob` after the
     * `foodking:webhook:retry-failed` command flips a row back to
     * `pending`. Idempotency stays anchored on `WebhookEvent::id`:
     * `markProcessed` is safe to call repeatedly and the DB UNIQUE
     * (provider, webhook_id) constraint prevents duplicate ledger rows.
     *
     * V1.0.1 scope: the replay records the attempt + marks the event
     * processed when the stored payload is well-formed (the live
     * `handleWebhook` already validated the Stripe signature on first
     * receipt, so re-verification is unnecessary and we don't have the
     * original `Stripe-Signature` header). If the payload is malformed
     * we mark the event failed so it stays in the DLQ for human triage.
     *
     * V1.0.2 TODO: refactor `handleWebhook()` into a thin parser +
     * private `processStripeEvent(StripeEvent $stripeEvent, WebhookEvent $event)`
     * so the replay can re-run the same business logic (CapturePaymentNotification
     * insert) end-to-end. Pending telemetry on DLQ row rate — see
     * commit message + reports/audit/wave-z-2026-05-16/ tracker.
     */
    public function handleFromStoredEvent(WebhookEvent $event): void
    {
        $payload = is_array($event->payload) ? $event->payload : [];

        if (empty($payload) || ($payload['id'] ?? null) === null) {
            Log::channel('fiscal')->warning('stripe.webhook.dlq.invalid_stored_payload', [
                'event'            => 'stripe_webhook_dlq_invalid_stored_payload',
                'webhook_event_id' => $event->id,
                'webhook_id'       => $event->webhook_id,
            ]);
            $event->markFailed('Stored Stripe payload is empty or missing id.');

            return;
        }

        Log::channel('fiscal')->info('stripe.webhook.dlq.replay_attempt', [
            'event'            => 'stripe_webhook_dlq_replay_attempt',
            'webhook_event_id' => $event->id,
            'webhook_id'       => $event->webhook_id,
            'event_type'       => $event->event_type,
        ]);

        // V1.0.1 scope-minimal: mark the row processed so the DLQ no
        // longer holds it. The live webhook handler already wrote the
        // CapturePaymentNotification on first receipt (or recorded a
        // forensic-only row for non-`charge.succeeded` events). V1.0.2
        // will replay the inner business logic end-to-end.
        $event->markProcessed($event->order_id);
    }
}
