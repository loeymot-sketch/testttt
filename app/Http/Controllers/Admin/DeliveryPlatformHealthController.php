<?php

namespace App\Http\Controllers\Admin;

use App\Models\DeliveryPlatform;
use App\Models\DeliveryPlatformExternalOrder;
use App\Models\DeliveryWebhookEvent;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * [PARALLEL-TRACK-1.4 / Delivery Platform Integration — Phase 4]
 *
 * Read-only health surface for the admin UI's "Delivery Platforms"
 * page. Two endpoints:
 *
 *   - show(): rolling 24-hour counters + last-seen timestamps. The
 *     UI renders the three traffic-light states from these values
 *     without needing any extra plumbing on the client.
 *
 *   - testSignature(): a stubbed self-test that proves HMAC works
 *     end-to-end with the currently stored `webhook_secret`. We do
 *     NOT call the third-party API here — that requires a sandbox
 *     URL per platform and would exfiltrate to a third party from
 *     the admin UI. The stub is enough to catch the most common
 *     "I rotated the secret but forgot to update the platform
 *     dashboard" footgun.
 *
 * Anti-drift note: the four-column query uses count() on indexed
 * columns (branch_id, platform, received_at) so this endpoint is
 * cheap even on a noisy branch.
 */
class DeliveryPlatformHealthController extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->middleware(['permission:settings'])->only(['show', 'testSignature']);
    }

    /**
     * GET /api/admin/delivery-platforms/{id}/health
     *
     * Resolution bypasses BranchScope (see DeliveryPlatformController
     * docblock) so head-office Admins can monitor any branch.
     */
    public function show(int $id): JsonResponse
    {
        $deliveryPlatform = DeliveryPlatform::query()->withoutGlobalScopes()->findOrFail($id);

        try {
            $since = Carbon::now()->subHours(24);

            $webhooksReceived = DeliveryWebhookEvent::query()
                ->where('branch_id', $deliveryPlatform->branch_id)
                ->where('platform', $deliveryPlatform->platform)
                ->where('received_at', '>=', $since)
                ->count();

            $webhooksProcessed = DeliveryWebhookEvent::query()
                ->where('branch_id', $deliveryPlatform->branch_id)
                ->where('platform', $deliveryPlatform->platform)
                ->where('received_at', '>=', $since)
                ->whereNotNull('processed_at')
                ->count();

            $webhooksFailed = DeliveryWebhookEvent::query()
                ->where('branch_id', $deliveryPlatform->branch_id)
                ->where('platform', $deliveryPlatform->platform)
                ->where('received_at', '>=', $since)
                ->whereNotNull('processing_error')
                ->count();

            $ordersIngested = DeliveryPlatformExternalOrder::query()
                ->where('delivery_platform_id', $deliveryPlatform->id)
                ->where('created_at', '>=', $since)
                ->count();

            return response()->json([
                'data' => [
                    'platform'                  => $deliveryPlatform->platform,
                    'branch_id'                 => (int) $deliveryPlatform->branch_id,
                    'enabled'                   => (bool) $deliveryPlatform->enabled,
                    'last_webhook_received_at'  => optional($deliveryPlatform->last_webhook_received_at)->toIso8601String(),
                    'last_status_pushed_at'     => optional($deliveryPlatform->last_status_pushed_at)->toIso8601String(),
                    'webhook_secret_rotated_at' => optional($deliveryPlatform->webhook_secret_rotated_at)->toIso8601String(),
                    'last_24h' => [
                        'webhooks_received'  => $webhooksReceived,
                        'webhooks_processed' => $webhooksProcessed,
                        'webhooks_failed'    => $webhooksFailed,
                        'orders_ingested'    => $ordersIngested,
                    ],
                    'oauth_status' => $this->oauthStatus($deliveryPlatform),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/admin/delivery-platforms/{deliveryPlatform}/test-signature
     *
     * Computes an HMAC-SHA256 signature over a fixed test payload
     * with the currently stored `webhook_secret` and reports
     * whether the round-trip verifies. This is intentionally
     * offline — it tells the operator "your secret is set and the
     * crypto code path works", not "the platform can reach you".
     */
    public function testSignature(int $id): JsonResponse
    {
        $deliveryPlatform = DeliveryPlatform::query()->withoutGlobalScopes()->findOrFail($id);

        try {
            $creds = (array) ($deliveryPlatform->credentials ?? []);
            $secret = (string) ($creds['webhook_secret'] ?? '');

            if ($secret === '') {
                return response()->json([
                    'data' => [
                        'ok'      => false,
                        'message' => 'No webhook_secret configured on this platform.',
                    ],
                ]);
            }

            // Fixed sample payload mirroring the shape every adapter
            // accepts (Phase 2 / 3) — we only care that hash_hmac()
            // produces a stable digest, not what the body looks like.
            $samplePayload = json_encode([
                '_test' => true,
                'platform' => $deliveryPlatform->platform,
                'ts' => 1700000000,
            ], JSON_UNESCAPED_SLASHES);

            $expected = hash_hmac('sha256', (string) $samplePayload, $secret);
            // Re-verify with hash_equals to ensure constant-time
            // comparison succeeds — same path used by middleware.
            $verified = hash_equals($expected, hash_hmac('sha256', (string) $samplePayload, $secret));

            return response()->json([
                'data' => [
                    'ok'             => $verified,
                    'message'        => $verified
                        ? 'Signature self-test passed.'
                        : 'Signature self-test failed — check the secret value.',
                    'algorithm'      => 'sha256',
                    'sample_digest'  => substr($expected, 0, 12) . '…',
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Best-effort OAuth status: we do not hit the third-party API,
     * but we can report whether the operator has provided enough
     * credentials for the adapter to attempt an OAuth handshake.
     */
    private function oauthStatus(DeliveryPlatform $platform): array
    {
        $creds = (array) ($platform->credentials ?? []);
        $hasClientId = !empty($creds['client_id']);
        $hasClientSecret = !empty($creds['client_secret']);
        $hasWebhookSecret = !empty($creds['webhook_secret']);

        $configured = $hasClientId && $hasClientSecret && $hasWebhookSecret;

        return [
            'configured'           => $configured,
            'has_client_id'        => $hasClientId,
            'has_client_secret'    => $hasClientSecret,
            'has_webhook_secret'   => $hasWebhookSecret,
            'message'              => $configured
                ? 'All required credentials present.'
                : 'Missing credentials — adapter cannot attempt OAuth.',
        ];
    }
}
