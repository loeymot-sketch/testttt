<?php

namespace Tests\Feature\Security;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [SECURITY P1-B DEMO-OTP-BYPASS 2026-07-30]
 *
 * DEMO=true makes OtpManagerService::verify() short-circuit to `true` for
 * ANY OTP code (OtpManagerService.php:82). GuestSignupController::verify()
 * then calls register() which mints a Sanctum `kiosk:order` token for an
 * ARBITRARY phone number (GuestSignupController.php:252) = guest account
 * takeover with a bogus code.
 *
 * Its dev-simulation twins — POS_SIMULATION_HARDWARE, APP_DEBUG,
 * PAYMENT_BYPASS_MODE, PRINTING_BYPASS_MODE — are ALL refused at boot in
 * production by AppServiceProvider (CLAUDE.md §8), but DEMO/demo_mode had
 * NO boot guard, so a production box shipped with DEMO=true booted anyway.
 *
 * These tests pin:
 *   (a)  production + demo_mode=true  -> AppServiceProvider::boot() throws.
 *   (a2) local/dev  + demo_mode=true  -> boot does NOT throw (DEMO stays a
 *        legitimate dev/fixtures convenience; ONLY production is forbidden —
 *        the fix must not change local/dev behaviour).
 *   (a3) production + demo_mode=false -> the DEMO guard does not fire.
 *   (b)  `app:preflight-production --strict` flags DEMO=true as CRITICAL and
 *        exits non-zero (CI/CD go-live gate, mirrors the boot guard).
 *   (b2) preflight with demo_mode=false does not raise the DEMO finding.
 */
class DemoModeProdGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Neutralise every OTHER production boot guard to a known-good value so
     * the ONLY guard that can fire is the DEMO one under test. Mirrors
     * ProductionBootGuardsCompletenessSentinelTest::primeProductionGood().
     */
    private function primeProductionGood(): void
    {
        $this->app['env'] = 'production';

        Config::set([
            'pos.simulation_hardware' => false,
            'payment.bypass.enabled'  => false,
            'printing.bypass.enabled' => false,
            'app.debug'               => false,
            'app.demo_mode'           => false,
            'idempotency.enabled'     => true,
            'loyalty.qr.secret'       => str_repeat('a', 32),
            'app.url'                 => 'https://lecayenne.example.com',
            'broadcasting.default'    => 'pusher',
            'queue.default'           => 'redis',
            'cache.default'           => 'redis',
            'mail.mailers.smtp.host'  => 'smtp.mailgun.org',
            'daily_book.pin'          => '9137',
        ]);
    }

    /** @test (a) production + DEMO=true -> boot refuses (throws RuntimeException). */
    public function production_with_demo_mode_true_refuses_to_boot(): void
    {
        $this->primeProductionGood();
        Config::set('app.demo_mode', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DEMO=true is forbidden in production/');

        (new AppServiceProvider($this->app))->boot();
    }

    /** @test (a3) production + DEMO=false -> DEMO guard must NOT fire. */
    public function production_with_demo_mode_false_does_not_trip_demo_guard(): void
    {
        $this->primeProductionGood();

        try {
            (new AppServiceProvider($this->app))->boot();
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            $this->assertDoesNotMatchRegularExpression(
                '/DEMO=true is forbidden in production/',
                $e->getMessage(),
                'DEMO guard must NOT fire when demo_mode is false. Actual: '.$e->getMessage()
            );
        }
    }

    /** @test (a2) local/dev + DEMO=true -> boot must NOT refuse (dev convenience preserved). */
    public function local_env_with_demo_mode_true_does_not_refuse_boot(): void
    {
        $this->primeProductionGood();
        $this->app['env'] = 'local';
        Config::set('app.demo_mode', true);

        try {
            (new AppServiceProvider($this->app))->boot();
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            $this->assertDoesNotMatchRegularExpression(
                '/DEMO=true is forbidden in production/',
                $e->getMessage(),
                'DEMO guard must be production-only; local/dev must still boot with DEMO=true. Actual: '.$e->getMessage()
            );
        }
    }

    /** @test (b) preflight --strict flags DEMO=true CRITICAL and exits non-zero. */
    public function preflight_strict_fails_when_demo_mode_true(): void
    {
        Config::set('app.env', 'production');
        Config::set('app.demo_mode', true);

        $this->artisan('app:preflight-production --strict')
            ->expectsOutputToContain('DEMO=true bypasses guest OTP')
            ->assertExitCode(1);
    }

    /** @test (b2) preflight with DEMO=false does not raise the DEMO finding. */
    public function preflight_does_not_flag_demo_when_demo_mode_false(): void
    {
        Config::set('app.env', 'production');
        Config::set('app.demo_mode', false);

        $this->artisan('app:preflight-production')
            ->doesntExpectOutputToContain('DEMO=true bypasses guest OTP');
    }
}
