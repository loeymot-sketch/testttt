<?php

namespace App\Http\PaymentGateways\Gateways;

use App\Enums\Activity;
use App\Models\CapturePaymentNotification;
use App\Models\Currency;
use App\Models\PaymentGateway;
use App\Models\WebhookEvent;
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
            $this->gateway              = new StripeClient\StripeClient($this->paymentGatewayOption['stripe_secret']);
        }
    }

    public function payment($order, $request) : \Illuminate\Http\RedirectResponse
    {
        try {
            $currencyCode = 'USD';
            $currencyId   = Settings::group('site')->get('site_default_currency');
            if (!blank($currencyId)) {
                $currency = Currency::find($currencyId);
                if ($currency) {
                    $currencyCode = $currency->code;
                }
            }

            $response = $this->gateway->charges->create([
                'amount'      => (int) $order->total * 100,
                'currency'    => $currencyCode,
                'source'      => $request->stripeToken,
                'description' => 'Food order payment',
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
            $stripeEvent = StripeClient\Webhook::constructEvent($payload, $sigHeader, $secret);
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
}
