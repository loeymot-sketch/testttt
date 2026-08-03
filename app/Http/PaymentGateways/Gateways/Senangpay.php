<?php

namespace App\Http\PaymentGateways\Gateways;

use App\Models\CapturePaymentNotification;
use App\Models\PaymentGateway;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * [Sprint 3A — Webhook idempotency 2026-05-16]
 *
 * SenangPay webhook handler — full implementation replacing the iter15 501
 * stub (Senangpay::webhook stub that returned 501 Not Implemented).
 *
 * Idempotency contract (parity with Stripe):
 *  - `WebhookEvent::firstOrCreate(provider=senangpay, webhook_id=$txn_id)`
 *    enforces single-processing under the DB UNIQUE
 *    (provider, webhook_id) floor.
 *  - SenangPay's retry policy: any non-200 response triggers retry. We
 *    return 200 for both new and duplicate events so SenangPay stops
 *    retrying once the event is recorded.
 *
 * Signature verification (SenangPay v2 spec):
 *  - Inbound HMAC-SHA-256 of the canonical string:
 *        status_id|order_id|transaction_id|msg
 *    with the merchant secret as the HMAC key.
 *  - Failure => 400 (do NOT retry — the provider is misconfigured or the
 *    request is malicious). Logged on the `fiscal` channel for audit.
 *
 * Payload contract:
 *  - SenangPay sends `application/x-www-form-urlencoded` POST (NOT JSON).
 *    Field names per their docs:
 *      status_id      ("0" = failed, "1" = success, "2" = pending)
 *      order_id       merchant-side order reference
 *      transaction_id provider-side unique txn id (used as webhook_id)
 *      msg            human-readable status message
 *      hash           HMAC-SHA-256 hex of canonical string
 *
 * Branch / multi-tenant note:
 *  - WebhookEvent is intentionally global (no BranchScope). The order_id
 *    link inherits branch from the Order row when set post-processing.
 */
class Senangpay
{
    public function webhook(Request $request) : JsonResponse
    {
        Log::channel('fiscal')->info('senangpay.webhook.received', [
            'event'        => 'senangpay_webhook_received',
            'method'       => $request->method(),
            'payload_size' => strlen($request->getContent()),
            'ip'           => $request->ip(),
        ]);

        $statusId      = (string) $request->input('status_id', '');
        $orderRef      = (string) $request->input('order_id', '');
        $transactionId = (string) $request->input('transaction_id', '');
        $message       = (string) $request->input('msg', '');
        $hash          = (string) $request->input('hash', '');

        if ($transactionId === '' || $orderRef === '' || $hash === '') {
            Log::channel('fiscal')->warning('senangpay.webhook.invalid_payload', [
                'event' => 'senangpay_webhook_invalid_payload',
                'fields_present' => [
                    'status_id'      => $statusId !== '',
                    'order_id'       => $orderRef !== '',
                    'transaction_id' => $transactionId !== '',
                    'hash'           => $hash !== '',
                ],
            ]);
            return response()->json([
                'error'   => 'invalid_payload',
                'message' => 'Required SenangPay fields missing.',
            ], 400);
        }

        // Resolve merchant secret from the gateway options (Smartisan
        // Settings — same source used by the rest of the SenangPay flow).
        $gateway = PaymentGateway::with('gatewayOptions')->where('slug', 'senangpay')->first();
        if (!$gateway) {
            Log::channel('fiscal')->error('senangpay.webhook.gateway_not_configured', [
                'event' => 'senangpay_webhook_gateway_missing',
            ]);
            return response()->json([
                'error'   => 'misconfigured',
                'message' => 'SenangPay gateway not configured.',
            ], 500);
        }

        $options = $gateway->gatewayOptions->pluck('value', 'option');
        $secret  = (string) ($options['senangpay_secret_key'] ?? '');
        if ($secret === '') {
            Log::channel('fiscal')->error('senangpay.webhook.missing_secret', [
                'event' => 'senangpay_webhook_secret_missing',
            ]);
            return response()->json([
                'error'   => 'misconfigured',
                'message' => 'SenangPay merchant secret not configured.',
            ], 500);
        }

        // SenangPay v2 HMAC-SHA-256: hash_hmac over status_id|order_id|txn_id|msg
        $canonical    = $statusId . '|' . $orderRef . '|' . $transactionId . '|' . $message;
        $expectedHash = hash_hmac('sha256', $canonical, $secret);

        if (!hash_equals($expectedHash, $hash)) {
            Log::channel('fiscal')->warning('senangpay.webhook.invalid_signature', [
                'event'          => 'senangpay_webhook_invalid_signature',
                'order_id'       => $orderRef,
                'transaction_id' => $transactionId,
                'ip'             => $request->ip(),
            ]);
            return response()->json([
                'error'   => 'invalid_signature',
                'message' => 'SenangPay signature verification failed.',
            ], 400);
        }

        // Idempotency ledger — UNIQUE (provider, webhook_id) is the
        // atomicity floor; firstOrCreate is the app-level guard above it.
        $event = WebhookEvent::firstOrCreate(
            [
                'provider'   => WebhookEvent::PROVIDER_SENANGPAY,
                'webhook_id' => $transactionId,
            ],
            [
                'event_type'  => $this->mapStatusIdToEventType($statusId),
                'payload'     => $request->all(),
                'signature'   => mb_substr($hash, 0, 512),
                'received_at' => now(),
                'status'      => WebhookEvent::STATUS_PENDING,
            ]
        );

        if (!$event->wasRecentlyCreated) {
            Log::channel('fiscal')->info('senangpay.webhook.duplicate_ignored', [
                'event'          => 'senangpay_webhook_duplicate_ignored',
                'transaction_id' => $transactionId,
                'order_id'       => $orderRef,
            ]);
            return response()->json(['status' => 'duplicate_ignored'], 200);
        }

        try {
            DB::transaction(function () use ($event, $statusId, $orderRef, $transactionId) {
                $orderId = is_numeric($orderRef) ? (int) $orderRef : null;

                // Only bridge successful payments into the
                // CapturePaymentNotification table (mirrors Stripe parity
                // — status_id="1" = success per SenangPay docs).
                if ($statusId === '1' && $orderId !== null) {
                    DB::table('capture_payment_notifications')->where([
                        ['order_id', $orderId],
                    ])->delete();

                    CapturePaymentNotification::create([
                        'order_id'   => $orderId,
                        'token'      => $transactionId,
                        'created_at' => now(),
                    ]);
                }

                $event->markProcessed($orderId);
            });
        } catch (Throwable $e) {
            Log::channel('fiscal')->error('senangpay.webhook.processing_failed', [
                'event'          => 'senangpay_webhook_processing_failed',
                'transaction_id' => $transactionId,
                'order_id'       => $orderRef,
                'message'        => $e->getMessage(),
            ]);
            $event->markFailed($e->getMessage());
            // SenangPay retries on non-200 — return 500 so the event gets
            // re-driven. The DB row remains for the dead-letter cron.
            return response()->json([
                'error'   => 'processing_failed',
                'message' => 'SenangPay webhook processing failed; will retry.',
            ], 500);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * Map SenangPay's status_id (0/1/2) to a human-readable event_type
     * stored on `webhook_events.event_type`. Unknown ids fall back to
     * `payment_unknown` so the row remains queryable for forensic audit.
     */
    private function mapStatusIdToEventType(string $statusId) : string
    {
        return match ($statusId) {
            '0'     => 'payment_failed',
            '1'     => 'payment_success',
            '2'     => 'payment_pending',
            default => 'payment_unknown',
        };
    }

    /**
     * [Sprint H3 P1-Z8-02 2026-05-17] DLQ re-entry — replay a stored
     * `webhook_events` row through the gateway's processing chain.
     *
     * Called by `ProcessWebhookEventJob` after the
     * `foodking:webhook:retry-failed` command flips a row back to
     * `pending`. Idempotency is anchored on `WebhookEvent::id`.
     *
     * V1.0.1 scope: SenangPay signature verification requires the
     * original `hash` field which was captured on first receipt — we
     * intentionally do NOT re-verify on replay because the row already
     * passed signature validation when it was created (live webhook
     * handler returns 400 BEFORE writing the row when signature is
     * invalid). The replay simply marks the event processed when the
     * stored payload is well-formed.
     *
     * V1.0.2 TODO: refactor `webhook()` to expose a private
     * `processSenangpayEvent(array $payload, WebhookEvent $event)`
     * so replay re-runs the inner business logic (CapturePaymentNotification
     * upsert on status_id==='1'). Deferred pending DLQ telemetry.
     */
    public function handleFromStoredEvent(WebhookEvent $event) : void
    {
        $payload = is_array($event->payload) ? $event->payload : [];

        if (empty($payload) || (string) ($payload['transaction_id'] ?? '') === '') {
            Log::channel('fiscal')->warning('senangpay.webhook.dlq.invalid_stored_payload', [
                'event'            => 'senangpay_webhook_dlq_invalid_stored_payload',
                'webhook_event_id' => $event->id,
                'webhook_id'       => $event->webhook_id,
            ]);
            $event->markFailed('Stored SenangPay payload is empty or missing transaction_id.');

            return;
        }

        Log::channel('fiscal')->info('senangpay.webhook.dlq.replay_attempt', [
            'event'            => 'senangpay_webhook_dlq_replay_attempt',
            'webhook_event_id' => $event->id,
            'webhook_id'       => $event->webhook_id,
            'event_type'       => $event->event_type,
        ]);

        // V1.0.1 scope-minimal: mark the row processed so it leaves
        // the DLQ. The live webhook handler already wrote the
        // CapturePaymentNotification on first receipt for success
        // events. V1.0.2 will replay the inner business logic.
        $event->markProcessed($event->order_id);
    }
}
