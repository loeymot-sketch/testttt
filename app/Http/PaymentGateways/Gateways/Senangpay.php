<?php

namespace App\Http\PaymentGateways\Gateways;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * [iter15-P0-11 owner G0-B Option C] SenangPay webhook handler stub.
 *
 * Pre-fix the route `/payment/senangpay-webhook/` referenced this class but
 * the file did NOT exist — every inbound SenangPay webhook (or scanner probe)
 * triggered a `ClassNotFoundException` → uncaught HTTP 500. That leaked an
 * implementation gap and hid genuine 500s from observability.
 *
 * Implementation deferred to V1.x (post creds + business validation).
 * Returns 501 Not Implemented to close the production 500 leak while
 * preserving iter11's `webhook_events` idempotency infrastructure
 * (UNIQUE provider+webhook_id, firstOrCreate pattern).
 *
 * When SenangPay creds become available + business green-light: implement
 * webhook() following the WebhookEvent::firstOrCreate pattern documented
 * in tests/Feature/Webhooks/WebhookEventIdempotencyTest.php.
 *
 * NOTE: The 501 response is the right semantic. 404 would tell the provider
 * to give up retrying entirely; 501 keeps the endpoint discoverable but
 * declares the feature unimplemented per RFC 7231 §6.6.2. SenangPay should
 * back off; we get observability via the `fiscal` log channel.
 */
class Senangpay
{
    public function webhook(Request $request): JsonResponse
    {
        Log::channel('fiscal')->info('senangpay.webhook.stub.received', [
            'event'        => 'senangpay_webhook_stub_501',
            'method'       => $request->method(),
            'payload_size' => strlen($request->getContent()),
            'ip'           => $request->ip(),
        ]);

        return response()->json([
            'error'   => 'not_implemented',
            'message' => 'SenangPay webhook handler not yet implemented. Endpoint reserved for V1.x.',
        ], 501);
    }
}
