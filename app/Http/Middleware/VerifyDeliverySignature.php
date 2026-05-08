<?php

namespace App\Http\Middleware;

use App\Domain\Delivery\PlatformAdapterRegistry;
use App\Models\DeliveryPlatform;
use App\Models\DeliveryWebhookEvent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * [PARALLEL-TRACK-1.2 / Delivery Platform Integration — Phase 2]
 *
 * Webhook signature gate.
 *
 * Trust pipeline (in order — every step is fail-soft, returning 202
 * with a persisted forensic row instead of leaking failure modes back
 * to the caller):
 *
 *   1. Read raw body BEFORE Laravel rebuilds JSON (HMAC verifications
 *      are byte-sensitive on some platforms).
 *   2. Resolve the per-platform adapter from the URL parameter.
 *   3. Decode the body just enough to extract `external_store_id` —
 *      this is the lookup key into `delivery_platforms`.
 *   4. Replay defense via Cache::add — first call wins, all subsequent
 *      callers within `replay_cache_ttl` are silently dropped (202).
 *   5. Adapter-level HMAC verification against the per-branch
 *      webhook_secret (encrypted credentials).
 *   6. On success: stash branch_id + config_id on the request so the
 *      controller / job can route + persist without re-doing the
 *      lookup.
 *   7. On any failure: persist a `delivery_webhook_events` row with
 *      `signature_valid=false` (defense-in-depth — failure rate
 *      monitoring + forensic replay).
 *
 * Why 202 on failure rather than 401:
 *   - 401 leaks "this branch exists, but your sig is wrong" → enables
 *     credential probing.
 *   - 202 mirrors the success shape, denying the attacker a useful
 *     side-channel. The forensic row + log are sufficient for SIEM
 *     correlation downstream.
 */
class VerifyDeliverySignature
{
    public function __construct(
        private PlatformAdapterRegistry $registry,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $platform = (string) $request->route('platform');
        $rawBody  = (string) $request->getContent();

        // ---- 1) Adapter resolution (route → registry) -----------------------
        try {
            $adapter = $this->registry->for($platform);
        } catch (\Throwable $e) {
            $this->persistFailedEvent($request, $platform, $rawBody, 'unknown_platform', null);
            return response()->json(['status' => 'accepted'], 202);
        }

        // ---- 2) Decode body (best-effort) ---------------------------------
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $this->persistFailedEvent($request, $platform, $rawBody, 'malformed_json', null);
            return response()->json(['status' => 'accepted'], 202);
        }

        // ---- 3) Extract external_store_id -------------------------------
        $externalStoreId = $this->extractStoreId($platform, $payload);
        if ($externalStoreId === null) {
            $this->persistFailedEvent($request, $platform, $rawBody, 'missing_store_id', null);
            return response()->json(['status' => 'accepted'], 202);
        }

        // ---- 4) Replay defense (per-platform per-nonce) -----------------
        // Nonce sourced from the signature header itself when present —
        // it's already unique-per-envelope and non-spoofable without the
        // shared secret. Falls back to (externalId|eventTime) when the
        // platform does not carry one.
        $nonce  = $this->extractNonce($platform, $request, $payload);
        $ttl    = (int) config('delivery_platforms.replay_cache_ttl', 600);
        $cached = Cache::add('delivery:nonce:' . $platform . ':' . $nonce, 1, $ttl);
        if ($cached === false) {
            // Replay attempt — silently swallow.
            Log::info('[VerifyDeliverySignature] replay swallowed', [
                'platform' => $platform,
                'nonce'    => substr($nonce, 0, 24),
            ]);
            return response()->json(['status' => 'accepted'], 202);
        }

        // ---- 5) Resolve config WITHOUT BranchScope (no auth context) -----
        /** @var DeliveryPlatform|null $cfg */
        $cfg = DeliveryPlatform::withoutGlobalScopes()
            ->where('platform', $platform)
            ->where('external_store_id', $externalStoreId)
            ->where('enabled', true)
            ->first();

        if ($cfg === null) {
            $this->persistFailedEvent($request, $platform, $rawBody, 'unknown_store_id', null);
            return response()->json(['status' => 'accepted'], 202);
        }

        // ---- 6) HMAC verification ---------------------------------------
        $secret = (string) ($cfg->credentials['webhook_secret'] ?? '');
        if ($secret === '' || !$adapter->verifySignature($request, $rawBody, $secret)) {
            $this->persistFailedEvent($request, $platform, $rawBody, 'bad_signature', $cfg->branch_id);
            return response()->json(['status' => 'accepted'], 202);
        }

        // ---- 7) Stash routing context for downstream layers --------------
        $request->attributes->set('delivery_branch_id', (int) $cfg->branch_id);
        $request->attributes->set('delivery_platform_config_id', (int) $cfg->id);
        $request->attributes->set('delivery_platform_external_store_id', $externalStoreId);
        $request->attributes->set('delivery_platform_nonce', $nonce);

        return $next($request);
    }

    /**
     * Persist a webhook event row tagged signature_valid=false. The unique
     * (platform, nonce) constraint may collide if the bad signature is
     * itself a replay — we swallow the QueryException so the middleware
     * stays fail-soft (the row already exists from a previous attempt).
     */
    private function persistFailedEvent(
        Request $request,
        string $platform,
        string $rawBody,
        string $reason,
        ?int $branchId,
    ): void {
        try {
            DeliveryWebhookEvent::create([
                'platform'         => $platform,
                'event_type'       => 'verification_failed',
                'signature_header' => substr((string) $request->header('X-Uber-Signature', ''), 0, 512)
                                       ?: substr((string) $request->header('X-Deliveroo-Hmac-Sha256', ''), 0, 512)
                                       ?: substr((string) $request->header('X-Delicity-Signature', ''), 0, 512),
                'signature_valid'  => false,
                'nonce'            => substr((string) $this->safeNonce($request, $rawBody), 0, 128),
                'headers'          => $this->collectHeaders($request),
                'body'             => $this->safeJsonForStorage($rawBody),
                'branch_id'        => $branchId,
                'processing_error' => $reason,
                'received_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            // Last-ditch defense: never let a forensic-write failure tear
            // down a webhook response. The Pusher / Soketi pattern.
            Log::warning('[VerifyDeliverySignature] forensic write failed', [
                'platform' => $platform,
                'reason'   => $reason,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string,string>
     */
    private function collectHeaders(Request $request): array
    {
        // Strip Authorization-style headers — never persist secrets.
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

    /**
     * Extract per-platform store id from the decoded payload.
     */
    private function extractStoreId(string $platform, array $payload): ?string
    {
        $candidates = match ($platform) {
            'uber_eats' => [
                $payload['data']['store']['id'] ?? null,
                $payload['data']['store']['external_reference_id'] ?? null,
                $payload['store_id'] ?? null,
            ],
            'deliveroo' => [
                $payload['data']['restaurant']['external_id'] ?? null,
                $payload['restaurant_id'] ?? null,
            ],
            'delicity' => [
                $payload['data']['venue_id'] ?? null,
                $payload['venue_id'] ?? null,
            ],
            default => [],
        };

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Compose a cache-key-safe nonce. Prefer the platform's own header
     * (already unique-per-envelope and signed); fall back to a
     * deterministic composition of payload pointers.
     */
    private function extractNonce(string $platform, Request $request, array $payload): string
    {
        $headerName = match ($platform) {
            'uber_eats' => 'X-Uber-Signature',
            'deliveroo' => 'X-Deliveroo-Sequence-Guid',
            'delicity'  => 'X-Delicity-Event-Id',
            default     => null,
        };

        if ($headerName !== null) {
            $value = (string) $request->header($headerName, '');
            if ($value !== '') {
                // Uber's header includes the timestamp+hmac — already
                // unique per envelope. Hash to bound the cache key length.
                return hash('sha256', $platform . '|' . $value);
            }
        }

        // Fallback composite when no header nonce is available.
        $eventId   = (string) ($payload['event_id'] ?? $payload['id'] ?? '');
        $orderId   = (string) ($payload['data']['id'] ?? $payload['order_id'] ?? '');
        $eventTime = (string) ($payload['event_time'] ?? $payload['data']['order_state'] ?? '');
        return hash('sha256', $platform . '|' . $eventId . '|' . $orderId . '|' . $eventTime);
    }

    private function safeNonce(Request $request, string $rawBody): string
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $payload = [];
        }
        return $this->extractNonce(
            (string) $request->route('platform', 'unknown'),
            $request,
            $payload,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function safeJsonForStorage(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // Persist a stub when the body is not parseable; the raw text
        // would still be replayable from logs.
        return ['_unparseable' => true, '_length' => strlen($rawBody)];
    }
}
