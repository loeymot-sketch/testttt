<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDeliveryPlatformWebhookJob;
use App\Models\DeliveryWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * [PARALLEL-TRACK-1.2 / Delivery Platform Integration — Phase 2]
 *
 * Skinny webhook ingress.
 *
 * Contract:
 *   - The middleware (VerifyDeliverySignature) has already validated
 *     the signature, replay nonce, and resolved branch_id +
 *     delivery_platform_config_id. We trust those request attributes
 *     unconditionally.
 *   - Persist the raw body in `delivery_webhook_events` (forensic
 *     replay + outbox for the queue worker).
 *   - Dispatch `ProcessDeliveryPlatformWebhookJob` on the `high` queue
 *     and immediately return 202.
 *
 * No business logic in this controller — it MUST stay below 200ms
 * including DB write so the platform never times us out and retries.
 */
class DeliveryWebhookController extends Controller
{
    /**
     * Webhook entrypoint.
     *
     * Route: POST /api/webhooks/delivery/{platform}/{event}
     */
    public function __invoke(Request $request, string $platform, string $event): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            // Defense in depth — middleware should have caught this.
            $payload = ['_unparseable' => true];
        }

        try {
            /** @var DeliveryWebhookEvent $row */
            $row = DeliveryWebhookEvent::create([
                'platform'         => $platform,
                'event_type'       => substr($event, 0, 64),
                'signature_header' => $this->signatureHeaderFor($request, $platform),
                'signature_valid'  => true,
                'nonce'            => $this->nonceFor($request),
                'headers'          => $this->collectHeaders($request),
                'body'             => $payload,
                'branch_id'        => (int) $request->attributes->get('delivery_branch_id'),
                'received_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            // The unique(platform, nonce) index already rejected this as a
            // replay — swallow + 202 to mirror the success shape.
            Log::info('[DeliveryWebhook] persist swallowed', [
                'platform' => $platform,
                'event'    => $event,
                'error'    => $e->getMessage(),
            ]);
            return response()->json(['status' => 'accepted', 'reason' => 'replay'], 202);
        }

        // Dispatch the job on `high` so KDS broadcast wins over batch jobs.
        ProcessDeliveryPlatformWebhookJob::dispatch($row->id)->onQueue('high');

        return response()->json([
            'event_id' => $row->id,
            'status'   => 'queued',
        ], 202);
    }

    private function signatureHeaderFor(Request $request, string $platform): ?string
    {
        $header = match ($platform) {
            'uber_eats' => 'X-Uber-Signature',
            'deliveroo' => 'X-Deliveroo-Hmac-Sha256',
            'delicity'  => 'X-Delicity-Signature',
            default     => null,
        };
        if ($header === null) {
            return null;
        }
        $value = (string) $request->header($header, '');
        return $value !== '' ? substr($value, 0, 512) : null;
    }

    private function nonceFor(Request $request): ?string
    {
        $nonce = (string) $request->attributes->get('delivery_platform_nonce', '');
        return $nonce !== '' ? substr($nonce, 0, 128) : null;
    }

    /**
     * @return array<string,string>
     */
    private function collectHeaders(Request $request): array
    {
        $skip = ['authorization', 'cookie', 'set-cookie', 'php-auth-pw'];
        $out  = [];
        foreach ($request->headers->all() as $name => $values) {
            if (in_array(strtolower($name), $skip, true)) {
                continue;
            }
            $out[$name] = is_array($values) ? implode(',', $values) : (string) $values;
        }
        return $out;
    }
}
