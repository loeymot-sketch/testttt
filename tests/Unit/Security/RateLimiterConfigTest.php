<?php

namespace Tests\Unit\Security;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Regression guard for TASK_V1_SEC_CORS_RATELIMIT_001.
 *
 * Any named RateLimiter declared in RouteServiceProvider::configureRateLimiting()
 * MUST remain registered and MUST yield a Limit with the documented per-minute
 * ceiling. Dropping a limiter or loosening a cap silently breaks an acceptance
 * criterion and is rejected here.
 *
 * @see app/Providers/RouteServiceProvider.php
 * @see docs/RATE_LIMITS_MATRIX.md
 */
class RateLimiterConfigTest extends TestCase
{
    /**
     * name => [expectedPerMinute, bindsUserId]
     *
     * `bindsUserId=true` → limiter is expected to key by authenticated user id
     * when available (admin/pos buckets). Guest fallback (ip) is acceptable.
     *
     * @var array<string, array{0:int,1:bool}>
     */
    private const EXPECTED_LIMITERS = [
        'admin-mutation' => [30, true],
        'pos-quote' => [120, true],
        'pos-order-create' => [60, true],
        'pos-order-update' => [120, true],
    ];

    /** @dataProvider namedLimiterProvider */
    public function test_named_limiter_is_registered_with_expected_cap(string $name, int $expectedPerMinute): void
    {
        $resolver = RateLimiter::limiter($name);

        $this->assertIsCallable(
            $resolver,
            sprintf('Named RateLimiter `%s` is not registered in RouteServiceProvider', $name)
        );

        $request = Request::create('/api/probe', 'POST');
        $limit = $resolver($request);

        $this->assertInstanceOf(
            Limit::class,
            $limit,
            sprintf('RateLimiter `%s` must return an Illuminate Limit instance', $name)
        );

        $this->assertSame(
            $expectedPerMinute,
            $limit->maxAttempts,
            sprintf(
                'RateLimiter `%s` cap drifted: expected %d/min, got %d/min',
                $name,
                $expectedPerMinute,
                $limit->maxAttempts
            )
        );
    }

    public function test_admin_mutation_elevates_cap_for_pos_quote_preview(): void
    {
        $resolver = RateLimiter::limiter('admin-mutation');
        $this->assertIsCallable($resolver);

        $quoteRequest = Request::create('/api/admin/pos/quote', 'POST');
        $quoteLimit = $resolver($quoteRequest);
        $this->assertInstanceOf(Limit::class, $quoteLimit);
        $this->assertSame(
            120,
            $quoteLimit->maxAttempts,
            'POS quote preview must not share the 30/min CRUD ceiling (E2E + busy checkout)'
        );

        $counterRequest = Request::create('/api/admin/pos/counter-collect/1/confirm', 'POST');
        $counterLimit = $resolver($counterRequest);
        $this->assertInstanceOf(Limit::class, $counterLimit);
        $this->assertSame(120, $counterLimit->maxAttempts);

        $crudRequest = Request::create('/api/admin/items', 'POST');
        $crudLimit = $resolver($crudRequest);
        $this->assertInstanceOf(Limit::class, $crudLimit);
        $this->assertSame(30, $crudLimit->maxAttempts);
    }

    public function test_api_limiter_cap_matches_config(): void
    {
        $resolver = RateLimiter::limiter('api');

        $this->assertIsCallable(
            $resolver,
            'Named RateLimiter `api` is not registered in RouteServiceProvider'
        );

        $request = Request::create('/api/probe', 'POST');
        $limit = $resolver($request);

        $this->assertInstanceOf(Limit::class, $limit);
        $expected = max(1, (int) config('app.api_throttle_per_minute', 120));
        $this->assertSame(
            $expected,
            $limit->maxAttempts,
            'api limiter maxAttempts must match config(app.api_throttle_per_minute) (default 120)'
        );
    }

    public function test_login_lockout_limiter_is_registered(): void
    {
        $resolver = RateLimiter::limiter('login-lockout');

        $this->assertIsCallable(
            $resolver,
            'Named RateLimiter `login-lockout` is not registered in RouteServiceProvider'
        );

        $request = Request::create('/api/auth/login', 'POST', ['email' => 'abuse@example.com']);
        $limit = $resolver($request);

        $this->assertInstanceOf(Limit::class, $limit);
        $this->assertSame(
            max(1, (int) config('auth.login_lockout.max_attempts', 10)),
            $limit->maxAttempts,
            'login-lockout maxAttempts must match config(auth.login_lockout.max_attempts) (default 10 in prod)'
        );
    }

    public function test_kiosk_orders_limiter_is_registered(): void
    {
        $resolver = RateLimiter::limiter('kiosk-orders');

        $this->assertIsCallable(
            $resolver,
            'Named RateLimiter `kiosk-orders` is not registered in RouteServiceProvider'
        );

        $request = Request::create('/api/frontend/order', 'POST');
        $limit = $resolver($request);

        $this->assertInstanceOf(Limit::class, $limit);
        $this->assertSame(
            (int) config('kiosk.order_rate_limit', 5),
            $limit->maxAttempts,
            'kiosk-orders cap must come from config(kiosk.order_rate_limit)'
        );
    }

    /**
     * @return array<string, array{0:string,1:int}>
     */
    public static function namedLimiterProvider(): array
    {
        $cases = [];
        foreach (self::EXPECTED_LIMITERS as $name => [$cap]) {
            $cases[$name] = [$name, $cap];
        }

        return $cases;
    }
}
