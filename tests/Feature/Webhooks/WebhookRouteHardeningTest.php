<?php

namespace Tests\Feature\Webhooks;

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * [Wave 1 P1 heals 2026-05-18]
 * SYNC-RED-01 + SYNC-RED-02 — Stripe / SenangPay webhook hardening.
 *
 * Covers:
 *  - SYNC-RED-02 (Test A): SenangPay webhook MUST reject GET (was
 *    `Route::match(['get','post'], ...)` leaking hash + payment metadata
 *    into Nginx access logs). Heal = `Route::post(...)`.
 *  - SYNC-RED-01 (Test B + C): Both webhook routes MUST throttle to
 *    60 req/min (mitigates LAN HMAC-CPU DoS + fiscal-log disk pressure).
 *    Heal = `->middleware(['throttle:60,1'])`.
 *
 * Cache::flush() in setUp() ensures throttle counters don't leak between
 * tests — the rate-limit middleware persists counts in the cache store.
 */
class WebhookRouteHardeningTest extends TestCase
{
    use RefreshDatabase;

    private bool $createdInstalledFlag = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->ensureInstalledFlag();

        // Throttle middleware stores hit counters in the cache store.
        // Without this flush, tests in the same class poison each other.
        Cache::flush();
    }

    protected function tearDown(): void
    {
        if ($this->createdInstalledFlag && file_exists(storage_path('installed'))) {
            unlink(storage_path('installed'));
        }

        parent::tearDown();
    }

    /**
     * SYNC-RED-02 (P1) — SenangPay webhook route MUST whitelist POST only.
     *
     * We assert the registered route methods directly (not HTTP-level 405)
     * because routes/web.php:62 declares a SPA catchall on Route::get,
     * which swallows GET /payment/senangpay-webhook/ into RootController
     * before the missing-method handler can return 405. The security
     * invariant the audit cares about is the controller-not-reached on
     * GET: assert the route method whitelist instead. (HTTP-level URL
     * leak via nginx access log is a separate concern outside this scope.)
     */
    public function test_senangpay_webhook_route_does_not_accept_get(): void
    {
        $route = Route::getRoutes()->getByName('payment.senangpay.webhook');

        $this->assertNotNull($route, 'SenangPay webhook route must be registered.');
        $this->assertContains('POST', $route->methods(),
            'SYNC-RED-02: POST must remain accepted for production webhooks.');
        $this->assertNotContains('GET', $route->methods(),
            'SYNC-RED-02: GET must NOT be a registered method '
            .'(was Route::match([\'get\',\'post\']) before heal).');
    }

    /**
     * SYNC-RED-01 (P1) — Stripe webhook must throttle at 60 req/min.
     * The first 60 hits return whatever (invalid signature → 400, etc.).
     * The 61st hit MUST be blocked by the rate-limiter with 429.
     *
     * No valid signature needed — the throttle middleware runs before
     * the controller, so any POST body increments the counter.
     */
    public function test_stripe_webhook_throttles_at_60_per_minute(): void
    {
        $last = null;

        for ($i = 1; $i <= 61; $i++) {
            $last = $this->post('/payment/stripe-webhook/', [], [
                'HTTP_STRIPE_SIGNATURE' => 't=1,v1=invalid',
            ]);

            // Bail early if we already hit 429 (defensive — exact threshold
            // may be 60 or 61 depending on Laravel version).
            if ($i <= 59) {
                $this->assertNotEquals(429, $last->getStatusCode(),
                    "Stripe webhook returned 429 at hit #{$i} (expected throttle floor=60).");
            }
        }

        $this->assertSame(429, $last->getStatusCode(),
            'Stripe webhook should throttle after 60 hits/min (got '.$last->getStatusCode().').');
    }

    /**
     * SYNC-RED-01 (P1) — SenangPay webhook must throttle at 60 req/min.
     */
    public function test_senangpay_webhook_throttles_at_60_per_minute(): void
    {
        $last = null;

        for ($i = 1; $i <= 61; $i++) {
            $last = $this->post('/payment/senangpay-webhook/', [
                'status_id'      => '1',
                'order_id'       => '42',
                'transaction_id' => 'TXN-THROTTLE-'.$i,
                'msg'            => 'ok',
                'hash'           => 'invalid_hash_just_to_increment_counter',
            ]);

            if ($i <= 59) {
                $this->assertNotEquals(429, $last->getStatusCode(),
                    "SenangPay webhook returned 429 at hit #{$i} (expected throttle floor=60).");
            }
        }

        $this->assertSame(429, $last->getStatusCode(),
            'SenangPay webhook should throttle after 60 hits/min (got '.$last->getStatusCode().').');
    }

    private function ensureInstalledFlag(): void
    {
        if (file_exists(storage_path('installed'))) {
            return;
        }

        touch(storage_path('installed'));
        $this->createdInstalledFlag = true;
    }
}
