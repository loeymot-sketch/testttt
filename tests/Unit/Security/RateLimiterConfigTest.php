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
        // [Wave Y RATE-LIMIT 2026-05-21] admin-mutation default doubled from 30
        // to 60/min via env-knob ADMIN_MUTATION_RATE_LIMIT (RouteServiceProvider
        // L77-117 + config/app.php). Still tight enough for brute-force defence
        // against admin-CRUD abuse; generous enough to absorb owner manual-test
        // bursts on online-order/table-order Livré/Cancel CTAs. Local dev
        // overrides to 1000 in `.env`. NF525 chain UNAFFECTED.
        'admin-mutation' => [60, true],
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
        // [Wave Y RATE-LIMIT 2026-05-21] CRUD path now resolves to env-knob
        // value (default 60/min, doubled from prior 30/min hardcoded).
        $this->assertSame(
            max(1, (int) config('app.admin_mutation_rate_limit', 60)),
            $crudLimit->maxAttempts,
            'CRUD path must respect config(app.admin_mutation_rate_limit)'
        );
    }

    /**
     * [Wave Y RATE-LIMIT 2026-05-21] Env-knob ADMIN_MUTATION_RATE_LIMIT must
     * be honored through `config('app.admin_mutation_rate_limit')`. This guards
     * the env-pattern parity with POS_RATE_LIMIT_* / KDS_RATE_LIMIT_BUMP and
     * prevents silent regression to a hardcoded ceiling.
     */
    public function test_admin_mutation_owner_rapid_ctas_are_lifted_to_120_per_minute(): void
    {
        $resolver = RateLimiter::limiter('admin-mutation');
        $this->assertIsCallable($resolver);

        // online-order/change-status — owner-tested "Livré" CTA
        $onlineOrderReq = Request::create('/api/admin/online-order/change-status/42', 'POST');
        $onlineOrderLimit = $resolver($onlineOrderReq);
        $this->assertInstanceOf(Limit::class, $onlineOrderLimit);
        $this->assertSame(
            120,
            $onlineOrderLimit->maxAttempts,
            'online-order/change-status must be lifted to 120/min — owner rapid-CTA family'
        );

        // table-order/change-status — table service status flip
        $tableOrderReq = Request::create('/api/admin/table-order/change-status/7', 'POST');
        $tableOrderLimit = $resolver($tableOrderReq);
        $this->assertInstanceOf(Limit::class, $tableOrderLimit);
        $this->assertSame(
            120,
            $tableOrderLimit->maxAttempts,
            'table-order/change-status must be lifted to 120/min — owner rapid-CTA family'
        );
    }

    public function test_admin_mutation_cap_matches_config(): void
    {
        $resolver = RateLimiter::limiter('admin-mutation');
        $this->assertIsCallable($resolver);

        $crudRequest = Request::create('/api/admin/items', 'POST');
        $crudLimit = $resolver($crudRequest);
        $this->assertInstanceOf(Limit::class, $crudLimit);

        $expected = max(1, (int) config('app.admin_mutation_rate_limit', 60));
        $this->assertSame(
            $expected,
            $crudLimit->maxAttempts,
            'admin-mutation cap must resolve from config(app.admin_mutation_rate_limit) — env-knob ADMIN_MUTATION_RATE_LIMIT'
        );
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
        $limits = $resolver($request);

        // [SEC MISSION-31 2026-07-31] Le limiteur renvoie désormais un ARRAY : couche par-(email|IP) +
        // plafond GLOBAL (ferme le bypass X-Forwarded-For sous TrustProxies='*'). Miroir des limiteurs PIN.
        $limits = is_array($limits) ? $limits : [$limits];
        $this->assertGreaterThanOrEqual(2, count($limits), 'login-lockout doit avoir une couche par-clé + un plafond global (anti XFF-spoof)');
        $this->assertContainsOnlyInstancesOf(Limit::class, $limits);
        $this->assertSame(
            max(1, (int) config('auth.login_lockout.max_attempts', 10)),
            $limits[0]->maxAttempts,
            'login-lockout maxAttempts (couche par-clé) must match config(auth.login_lockout.max_attempts) (default 10 in prod)'
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
     * [P1-2 SÉCU 2026-08-04] Anti email-bombing : le throttle otp-send était indexé sur
     * `phone ?: email` (= toujours le téléphone, les deux étant requis) → un attaquant qui
     * FIXE l'email d'une victime et FAIT TOURNER le numéro spammait l'email (chaque numéro =
     * nouveau seau). Il DOIT y avoir un plafond DÉDIÉ PAR EMAIL.
     */
    public function test_otp_send_has_a_per_email_bucket(): void
    {
        $resolver = RateLimiter::limiter('otp-send');
        $request = Request::create('/api/auth/guest-signup/email-otp', 'POST', [
            'phone' => '0699000111', 'email' => 'Victime@Example.com',
        ]);
        $limits = $resolver($request);
        $limits = is_array($limits) ? $limits : [$limits];

        $keys = array_map(fn ($l) => (string) $l->key, $limits);
        $hasEmailBucket = false;
        foreach ($keys as $k) {
            if (str_contains($k, 'otp-email:') && str_contains(strtolower($k), 'victime@example.com')) {
                $hasEmailBucket = true;
            }
        }
        $this->assertTrue($hasEmailBucket, 'otp-send doit plafonner PAR EMAIL (anti-bombing par rotation du numéro). Seaux: '.implode(', ', $keys));
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
