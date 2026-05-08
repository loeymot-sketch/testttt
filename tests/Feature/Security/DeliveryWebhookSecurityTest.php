<?php

namespace Tests\Feature\Security;

use App\Models\DeliveryWebhookEvent;
use App\Services\Delivery\Adapters\UberEatsAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use ReflectionClass;
use Tests\TestCase;

/**
 * [PARALLEL-TRACK-5 / 5.4] Delivery webhook security invariants —
 * complementary to Track 1.2's `VerifySignatureMiddlewareTest`.
 *
 * Track 1.2 owns the middleware (currently PENDING — see
 * `find app -name VerifySignature*` returns nothing). When the
 * middleware lands, the four `markTestSkipped` cases below should be
 * unblocked and become hard assertions.
 *
 * In the meantime, this suite enforces the security invariants we CAN
 * assert today against existing production code:
 *   - the persisted `DeliveryWebhookEvent` model lays down a unique
 *     index on (platform, nonce) — replay defense at the schema level
 *     (see migration 2026_05_08_030000_create_delivery_webhook_events_table.php);
 *   - the only adapter that ships a signature verifier (UberEatsAdapter)
 *     uses `hash_equals` for timing-constant comparison;
 *   - the same adapter rejects non-hex signature payloads to protect
 *     `hash_equals` from odd-length input;
 *   - the timestamp-window replay guard rejects stale envelopes.
 *
 * Tests are purely READ-ONLY over production code. We never mutate the
 * adapter or middleware code path; failures here mean a regression in
 * the production code, not a test that needs softening.
 */
class DeliveryWebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $this->seedMinimalSettings();
    }

    /**
     * Replay attack defense — schema-level. Two webhooks with the same
     * (platform, nonce) tuple must NOT coexist; the second insert is
     * rejected by the UNIQUE index. Once Track 1.2 lands the middleware,
     * the second POST will be swallowed (HTTP 202 + log) instead of
     * triggering a constraint violation.
     */
    public function test_delivery_webhook_event_unique_platform_nonce_constraint(): void
    {
        $first = DeliveryWebhookEvent::create([
            'platform'         => 'uber_eats',
            'event_type'       => 'order.notify',
            'signature_header' => 'v=1,t=1700000000,h=' . str_repeat('a', 64),
            'signature_valid'  => true,
            'nonce'            => 'unique-nonce-replay-test-001',
            'headers'          => ['X-Uber-Signature' => '...'],
            'body'             => ['data' => ['id' => 'ext-1']],
            'received_at'      => now(),
        ]);

        $this->assertNotNull($first->id);

        // Second insert with the SAME nonce must be rejected at the DB
        // level. We catch any QueryException / RuntimeException — the
        // unique-violation behaviour is driver-specific (SQLite raises
        // RuntimeException, MySQL raises Illuminate\Database\QueryException).
        $threw = false;
        try {
            DeliveryWebhookEvent::create([
                'platform'         => 'uber_eats',
                'event_type'       => 'order.notify',
                'signature_header' => 'v=1,t=1700000099,h=' . str_repeat('b', 64),
                'signature_valid'  => true,
                'nonce'            => 'unique-nonce-replay-test-001', // SAME nonce
                'headers'          => ['X-Uber-Signature' => '...'],
                'body'             => ['data' => ['id' => 'ext-2']],
                'received_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue(
            $threw,
            'Schema must reject a second DeliveryWebhookEvent with the same (platform, nonce) — replay defense'
        );
    }

    /**
     * Different nonces (or NULL nonce) must be allowed — a platform that
     * does not carry a nonce produces many rows with nonce=NULL and the
     * UNIQUE index treats NULL as distinct.
     */
    public function test_delivery_webhook_event_allows_distinct_nonces(): void
    {
        DeliveryWebhookEvent::create([
            'platform'         => 'uber_eats',
            'event_type'       => 'order.notify',
            'signature_header' => 'v=1,t=1,h=' . str_repeat('a', 64),
            'signature_valid'  => true,
            'nonce'            => 'nonce-A',
            'headers'          => ['X-Uber-Signature' => '...'],
            'body'             => [],
            'received_at'      => now(),
        ]);

        DeliveryWebhookEvent::create([
            'platform'         => 'uber_eats',
            'event_type'       => 'order.notify',
            'signature_header' => 'v=1,t=2,h=' . str_repeat('b', 64),
            'signature_valid'  => true,
            'nonce'            => 'nonce-B',
            'headers'          => ['X-Uber-Signature' => '...'],
            'body'             => [],
            'received_at'      => now(),
        ]);

        $this->assertSame(2, DeliveryWebhookEvent::query()->count());
    }

    /**
     * Timing-attack defense — UberEatsAdapter::verifySignature() MUST
     * call `hash_equals()` (not `===`) when comparing the expected and
     * received HMAC. We assert this via source inspection because the
     * comparison is a private method and reflection on `hash_equals`
     * would otherwise pass a `==` regression silently.
     */
    public function test_uber_eats_adapter_uses_hash_equals_for_signature_comparison(): void
    {
        $reflection = new ReflectionClass(UberEatsAdapter::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString(
            'hash_equals',
            $source,
            'UberEatsAdapter must use hash_equals() for signature comparison (timing-attack defense). '
            . 'Plain `===` is vulnerable to side-channel timing attacks.'
        );

        $this->assertStringNotContainsString(
            'strcmp(',
            $source,
            'Avoid strcmp for HMAC comparison — not constant-time.'
        );
    }

    /**
     * UberEatsAdapter must reject signature payloads whose `h=` field is
     * not a 64-char lower-hex string. Otherwise an attacker can pass an
     * odd-length string and trigger PHP warnings inside hash_equals
     * (length-leak side channel + log noise).
     */
    public function test_uber_eats_adapter_rejects_non_hex_signature_payload(): void
    {
        $adapter = new UberEatsAdapter();
        $rawBody = '{"data":{"id":"order-1"}}';
        $secret  = 'webhook-secret-test';

        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X-UBER-SIGNATURE' => 'v=1,t=' . time() . ',k=key,h=NOT_HEX_PAYLOAD',
        ], $rawBody);

        $valid = $adapter->verifySignature($request, $rawBody, $secret);

        $this->assertFalse(
            $valid,
            'verifySignature must reject non-hex `h=` payloads (parseSignatureHeader returns null)'
        );
    }

    /**
     * UberEatsAdapter rejects stale envelopes (timestamp > 300s old by
     * default). This is the configured replay-window defense.
     */
    public function test_uber_eats_adapter_rejects_stale_timestamp(): void
    {
        $adapter = new UberEatsAdapter();
        $rawBody = '{"data":{"id":"order-1"}}';
        $secret  = 'webhook-secret-test';

        // Build a "valid" signature with a stale timestamp (1h old).
        $staleTs    = time() - 3600;
        $payload    = $staleTs . '.' . $rawBody;
        $validHmac  = hash_hmac('sha256', $payload, $secret);

        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X-UBER-SIGNATURE' => "v=1,t={$staleTs},k=key,h={$validHmac}",
        ], $rawBody);

        $valid = $adapter->verifySignature($request, $rawBody, $secret);

        $this->assertFalse(
            $valid,
            'verifySignature must reject envelopes outside the replay window (default 300s)'
        );
    }

    /**
     * UberEatsAdapter rejects an empty signature header. Without this
     * guard a malicious caller could omit the header entirely and
     * reach the parsing/HMAC path with attacker-controlled state.
     */
    public function test_uber_eats_adapter_rejects_missing_signature_header(): void
    {
        $adapter = new UberEatsAdapter();
        $rawBody = '{"data":{}}';

        $request = Request::create('/webhook', 'POST', [], [], [], [], $rawBody);

        $this->assertFalse(
            $adapter->verifySignature($request, $rawBody, 'any-secret'),
            'Missing X-Uber-Signature header must be treated as an invalid signature'
        );
    }

    /**
     * UberEatsAdapter accepts a valid, fresh signature — counter-test to
     * keep the negative cases honest. If this fails the production code
     * is likely broken (and Track 1.2 should be paused).
     */
    public function test_uber_eats_adapter_accepts_valid_fresh_signature(): void
    {
        $adapter = new UberEatsAdapter();
        $rawBody = '{"data":{"id":"order-1"}}';
        $secret  = 'webhook-secret-test';

        $now        = time();
        $payload    = $now . '.' . $rawBody;
        $validHmac  = hash_hmac('sha256', $payload, $secret);

        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X-UBER-SIGNATURE' => "v=1,t={$now},k=key,h={$validHmac}",
        ], $rawBody);

        $this->assertTrue(
            $adapter->verifySignature($request, $rawBody, $secret),
            'A fresh, correctly-signed envelope must be accepted (counter-test sanity)'
        );
    }

    /**
     * UberEatsAdapter rejects a tampered body — same timestamp, same
     * (now-stale) HMAC, but a body modified after signing.
     */
    public function test_uber_eats_adapter_rejects_tampered_body(): void
    {
        $adapter = new UberEatsAdapter();
        $originalBody = '{"data":{"id":"order-1","total":100}}';
        $tamperedBody = '{"data":{"id":"order-1","total":1}}'; // 100x discount
        $secret  = 'webhook-secret-test';

        $now       = time();
        // Sign the ORIGINAL body...
        $hmac      = hash_hmac('sha256', $now . '.' . $originalBody, $secret);

        // ...but submit the TAMPERED body with the original signature.
        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X-UBER-SIGNATURE' => "v=1,t={$now},k=key,h={$hmac}",
        ], $tamperedBody);

        $this->assertFalse(
            $adapter->verifySignature($request, $tamperedBody, $secret),
            'Tampered body with original signature must be rejected'
        );
    }

    /**
     * VerifyDeliverySignature middleware (Track 1.2) — the middleware
     * MUST be registered as a route alias. A typo or accidental removal
     * would silently disable the trust gate.
     */
    public function test_verify_delivery_signature_middleware_alias_is_registered(): void
    {
        if (! class_exists('App\\Http\\Middleware\\VerifyDeliverySignature')) {
            $this->markTestSkipped('Track 1.2 has not yet shipped VerifyDeliverySignature.');
        }

        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $reflection = new ReflectionClass($kernel);
        $aliasesProp = $reflection->getProperty('routeMiddleware');
        $aliasesProp->setAccessible(true);
        $aliases = $aliasesProp->getValue($kernel);

        $this->assertArrayHasKey(
            'delivery.verify-signature',
            $aliases,
            'Alias "delivery.verify-signature" must be registered in app/Http/Kernel.php $routeMiddleware'
        );
        $this->assertSame(
            \App\Http\Middleware\VerifyDeliverySignature::class,
            $aliases['delivery.verify-signature'],
            'Alias must point to the correct middleware class'
        );
    }

    /**
     * The webhook route MUST be wired through the verify-signature
     * middleware. A regression that drops the middleware from the route
     * stack would silently expose the platform to unauthenticated
     * webhook ingestion.
     */
    public function test_webhook_route_uses_verify_signature_middleware(): void
    {
        if (! class_exists('App\\Http\\Middleware\\VerifyDeliverySignature')) {
            $this->markTestSkipped('Track 1.2 has not yet shipped VerifyDeliverySignature.');
        }

        $route = \Illuminate\Support\Facades\Route::getRoutes()
            ->getByName('webhooks.delivery');

        $this->assertNotNull(
            $route,
            'Webhook route "webhooks.delivery" must exist (see routes/api.php Track 1.2 block)'
        );

        $middleware = $route->middleware();
        $this->assertContains(
            'delivery.verify-signature',
            $middleware,
            'Webhook route MUST include the delivery.verify-signature middleware. '
            . 'Stack observed: ' . implode(', ', $middleware)
        );
    }

    /**
     * The webhook route MUST NOT include the `apiKey` middleware. Webhooks
     * come from third parties whose call surface we cannot mutate; the
     * signature gate is the trust boundary. A regression that adds the
     * apiKey middleware would silently 400 every legitimate webhook.
     */
    public function test_webhook_route_does_not_require_api_key_middleware(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()
            ->getByName('webhooks.delivery');

        if ($route === null) {
            $this->markTestSkipped('Webhook route not yet defined (Track 1.2).');
        }

        $middleware = $route->middleware();

        $this->assertNotContains(
            'apiKey',
            $middleware,
            'Webhook route MUST NOT require apiKey — third parties cannot send our internal key. '
            . 'Stack observed: ' . implode(', ', $middleware)
        );
    }

    /**
     * The webhook route MUST be throttled. Without throttle, a malicious
     * third party can flood the audit log with signature-failure rows.
     */
    public function test_webhook_route_is_throttled(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()
            ->getByName('webhooks.delivery');

        if ($route === null) {
            $this->markTestSkipped('Webhook route not yet defined (Track 1.2).');
        }

        $middleware = $route->middleware();
        $hasThrottle = false;
        foreach ($middleware as $m) {
            if (str_starts_with($m, 'throttle:') || $m === 'throttle') {
                $hasThrottle = true;
                break;
            }
        }

        $this->assertTrue(
            $hasThrottle,
            'Webhook route MUST be rate-limited. Stack: ' . implode(', ', $middleware)
        );
    }
}
