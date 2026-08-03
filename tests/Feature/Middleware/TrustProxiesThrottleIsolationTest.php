<?php

namespace Tests\Feature\Middleware;

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * [Wave 3 P1 SYNC-ADV3-01 heal 2026-05-18]
 *
 * Proves that `throttle:60,1` on /payment/stripe-webhook/ keys per
 * upstream IP (X-Forwarded-For), not per loopback nginx socket.
 *
 * BACKGROUND: Behind nginx → PHP-FPM on 127.0.0.1, Laravel's throttle
 * middleware computes its bucket via `sha1($domain.'|'.$request->ip())`.
 * If `TrustProxies::$proxies = null`, the framework refuses to honour
 * X-Forwarded-For → `$request->ip()` returns the nginx loopback address
 * → ONE shared throttle bucket for the entire LAN. Any noisy LAN host
 * (poisoned KDS tablet, misconfigured cron, guest Wi-Fi attacker) can
 * exhaust the 60/min counter and starve legitimate Stripe webhooks,
 * stalling fiscal-sequence allocation.
 *
 * The fix sets `TrustProxies::$proxies = '*'` so X-Forwarded-For is
 * honoured under the local single-restaurant deployment behind nginx.
 * This test asserts the bucket separates per upstream IP.
 */
class TrustProxiesThrottleIsolationTest extends TestCase
{
    use RefreshDatabase;

    private bool $createdInstalledFlag = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->ensureInstalledFlag();

        // Throttle counters persist in cache → flush so prior tests
        // don't bleed into this isolation assertion.
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
     * SYNC-ADV3-01: per-IP isolation under proxy.
     *
     * 1) Burn the bucket for IP-A (10.0.0.5) with 60 hits → 61st = 429.
     * 2) A single hit from IP-B (10.0.0.6) MUST NOT be 429 — proves the
     *    throttle keys per X-Forwarded-For, not on the shared nginx
     *    loopback IP.
     *
     * On the unfixed branch ($proxies=null), step 2 would already be 429
     * because both IPs share the loopback bucket.
     */
    public function test_stripe_webhook_throttle_isolates_per_forwarded_ip(): void
    {
        // --- Step 1: saturate the bucket for IP-A ---
        $last = null;
        for ($i = 1; $i <= 61; $i++) {
            $last = $this->post('/payment/stripe-webhook/', [], [
                'HTTP_STRIPE_SIGNATURE' => 't=1,v1=invalid',
                'HTTP_X_FORWARDED_FOR'  => '10.0.0.5',
            ]);
        }

        $this->assertSame(
            429,
            $last->getStatusCode(),
            'IP-A must hit 429 after 61 hits — baseline throttle should engage.'
        );

        // --- Step 2: IP-B's first request must NOT be 429 ---
        $second = $this->post('/payment/stripe-webhook/', [], [
            'HTTP_STRIPE_SIGNATURE' => 't=1,v1=invalid',
            'HTTP_X_FORWARDED_FOR'  => '10.0.0.6',
        ]);

        $this->assertNotSame(
            429,
            $second->getStatusCode(),
            'SYNC-ADV3-01: IP-B first hit must NOT be 429 — distinct '
            .'X-Forwarded-For values must occupy distinct throttle buckets. '
            .'A 429 here proves the LAN-wide single-bucket regression is '
            .'still present (TrustProxies::$proxies not set).'
        );
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
