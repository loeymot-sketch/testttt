<?php

namespace Tests\Feature\Webhooks;

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * [T-6.3.1 — V1.0.2 SYNC-ADV4-N1 2026-05-18]
 *
 * Regression: the original `$except` declared only `payment/{provider}-webhook/*`
 * which Laravel matches via `request->is($pattern)`. The pattern requires at
 * least one path segment after `*` — so bare `POST /payment/stripe-webhook`
 * (the actual route registration at `app/Http/PaymentGateways/Routes/{stripe,
 * senangpay}.php`) falls through to CSRF verification and would return 419.
 *
 * Heal: add bare path entries in `VerifyCsrfToken::$except`.
 *
 * This sentinel asserts the middleware-level invariant directly (which `$except`
 * entries are present), not the HTTP-level effect, so the test runs even if
 * the route handler itself rejects the request for other reasons (invalid
 * Stripe signature → 400, missing webhook secret → 500). The HTTP-level
 * proof is implicit: `request->is('payment/stripe-webhook')` MUST return true
 * for at least one $except entry to pass `inExceptArray`.
 */
class WebhookCsrfBareRouteExceptionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Reflective probe — read $except array from the middleware instance.
     */
    private function except(): array
    {
        $mw = new VerifyCsrfToken(
            app('Illuminate\Contracts\Foundation\Application'),
            app('Illuminate\Contracts\Encryption\Encrypter')
        );

        $ref = new \ReflectionClass($mw);
        $prop = $ref->getProperty('except');
        $prop->setAccessible(true);

        return $prop->getValue($mw);
    }

    /**
     * Helper — does any $except entry match a Laravel-style request path?
     * Mirrors VerifyCsrfToken::inExceptArray() semantics.
     */
    private function pathIsExcepted(string $path): bool
    {
        $request = Request::create($path, 'POST');

        foreach ($this->except() as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }
            if ($request->fullUrlIs($except) || $request->is($except)) {
                return true;
            }
        }

        return false;
    }

    public function test_stripe_webhook_bare_route_is_csrf_excepted(): void
    {
        $this->assertTrue(
            $this->pathIsExcepted('/payment/stripe-webhook'),
            'Bare /payment/stripe-webhook must be CSRF-excepted (route registered at this path).'
        );
    }

    public function test_stripe_webhook_wildcard_route_is_csrf_excepted(): void
    {
        $this->assertTrue(
            $this->pathIsExcepted('/payment/stripe-webhook/anything'),
            'Wildcard /payment/stripe-webhook/* preserved for legacy callers.'
        );
    }

    public function test_senangpay_webhook_bare_route_is_csrf_excepted(): void
    {
        $this->assertTrue(
            $this->pathIsExcepted('/payment/senangpay-webhook'),
            'Bare /payment/senangpay-webhook must be CSRF-excepted (route registered at this path).'
        );
    }

    public function test_senangpay_webhook_wildcard_route_is_csrf_excepted(): void
    {
        $this->assertTrue(
            $this->pathIsExcepted('/payment/senangpay-webhook/anything'),
            'Wildcard /payment/senangpay-webhook/* preserved for legacy callers.'
        );
    }

    public function test_unrelated_post_route_is_still_csrf_protected(): void
    {
        $this->assertFalse(
            $this->pathIsExcepted('/payment/some-other-endpoint'),
            'Sentinel: only the explicit webhook paths must be excepted; nothing else.'
        );
        $this->assertFalse(
            $this->pathIsExcepted('/admin/items'),
            'Sentinel: admin routes must remain CSRF-protected.'
        );
    }
}
