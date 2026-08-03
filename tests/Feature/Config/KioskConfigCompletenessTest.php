<?php

namespace Tests\Feature\Config;

use Tests\TestCase;

/**
 * Sentinel: config/kiosk.php returns through TWO branches (KIOSK_REQUIRE_MACHINE_LOGIN
 * true → login-form branch, false → production auto-login branch). Any key read at
 * runtime MUST appear in BOTH branches, otherwise flipping the env var silently drops
 * it to its fallback.
 *
 *   - P2-s: queue_start_number (read by OrderService + FrontendOrderService,
 *     fallback 1) was present only in the production branch → with
 *     KIOSK_REQUIRE_MACHINE_LOGIN=true the daily queue restarted at A0001
 *     instead of A0032. Same class as the RED-08 heal for payment_route_all_to_counter.
 *   - P2-t: env() read raw in Blade / AppServiceProvider returns null once
 *     `php artisan config:cache` runs (every deploy). Flags/guards must read
 *     config() so the value survives caching.
 *
 * DB-safe: no database access, no RefreshDatabase.
 */
class KioskConfigCompletenessTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        $this->savedEnv = [];
        parent::tearDown();
    }

    private function rememberEnv(string $key): void
    {
        if (! array_key_exists($key, $this->savedEnv)) {
            $this->savedEnv[$key] = getenv($key) === false ? null : getenv($key);
        }
    }

    private function setEnv(string $key, string $value): void
    {
        $this->rememberEnv($key);
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function unsetEnv(string $key): void
    {
        $this->rememberEnv($key);
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    /**
     * Re-evaluate config/kiosk.php from source for the requested branch.
     * `require` (not require_once) re-runs the file and returns its array each call,
     * reading env() live — so we can inspect both branches deterministically.
     *
     * @return array<string, mixed>
     */
    private function loadKioskConfig(bool $requireForm): array
    {
        $this->setEnv('KIOSK_REQUIRE_MACHINE_LOGIN', $requireForm ? 'true' : 'false');

        /** @var array<string, mixed> $config */
        $config = require config_path('kiosk.php');

        return $config;
    }

    /**
     * P2-s — queue_start_number must exist in BOTH branches with the same value,
     * so KIOSK_REQUIRE_MACHINE_LOGIN never silently resets the daily queue to A0001.
     */
    public function test_queue_start_number_present_in_both_config_branches(): void
    {
        $this->setEnv('KIOSK_QUEUE_START_NUMBER', '32');

        $production = $this->loadKioskConfig(false);
        $loginForm = $this->loadKioskConfig(true);

        $this->assertArrayHasKey('queue_start_number', $production, 'queue_start_number missing from production branch');
        $this->assertArrayHasKey('queue_start_number', $loginForm, 'queue_start_number missing from KIOSK_REQUIRE_MACHINE_LOGIN=true branch');

        $this->assertSame(32, $production['queue_start_number']);
        $this->assertSame(32, $loginForm['queue_start_number']);
        $this->assertSame(
            $production['queue_start_number'],
            $loginForm['queue_start_number'],
            'queue_start_number diverges between config branches'
        );
    }

    /**
     * P2-s companion — auto_login_secret was also present only in the production
     * branch. It is inert under requireForm (spa_payload is null) but must still
     * exist in both branches so master.blade.php never reads a missing key.
     */
    public function test_auto_login_secret_present_in_both_config_branches(): void
    {
        $production = $this->loadKioskConfig(false);
        $loginForm = $this->loadKioskConfig(true);

        $this->assertArrayHasKey('auto_login_secret', $production, 'auto_login_secret missing from production branch');
        $this->assertArrayHasKey('auto_login_secret', $loginForm, 'auto_login_secret missing from KIOSK_REQUIRE_MACHINE_LOGIN=true branch');
    }

    /**
     * P2-t — use_pos_wizard must exist in both branches and be typed via
     * filter_var (the old blade cast `(bool) env('...')` turned the string
     * "false" into true).
     */
    public function test_use_pos_wizard_present_in_both_branches_and_typed_via_filter_var(): void
    {
        $this->setEnv('KIOSK_USE_POS_WIZARD', 'true');
        $enabledProd = $this->loadKioskConfig(false);
        $enabledForm = $this->loadKioskConfig(true);

        $this->assertArrayHasKey('use_pos_wizard', $enabledProd, 'use_pos_wizard missing from production branch');
        $this->assertArrayHasKey('use_pos_wizard', $enabledForm, 'use_pos_wizard missing from KIOSK_REQUIRE_MACHINE_LOGIN=true branch');
        $this->assertTrue($enabledProd['use_pos_wizard']);
        $this->assertTrue($enabledForm['use_pos_wizard']);

        // The string "false" must resolve to boolean false (raw (bool) cast would give true).
        $this->setEnv('KIOSK_USE_POS_WIZARD', 'false');
        $disabled = $this->loadKioskConfig(false);
        $this->assertFalse($disabled['use_pos_wizard'], 'filter_var must turn "false" into boolean false');
    }

    /**
     * P2-t — under `php artisan config:cache` env() returns null at runtime; the
     * flag must survive via config(), and the Blade must read config() not raw env().
     */
    public function test_use_pos_wizard_survives_config_cache_and_blade_reads_config_not_env(): void
    {
        // Simulate a cached config: value baked into config(), env() unavailable.
        config(['kiosk.use_pos_wizard' => true]);
        $this->unsetEnv('KIOSK_USE_POS_WIZARD');

        $this->assertTrue((bool) config('kiosk.use_pos_wizard'));
        $this->assertNull(
            env('KIOSK_USE_POS_WIZARD'),
            'env() must be null once the key is unset — proving config() is the correct source under config:cache'
        );

        $blade = file_get_contents(resource_path('views/master.blade.php'));
        $this->assertNotFalse($blade);
        $this->assertStringContainsString("config('kiosk.use_pos_wizard')", $blade, 'master.blade.php must read the flag via config()');
        $this->assertStringNotContainsString("env('KIOSK_USE_POS_WIZARD')", $blade, 'master.blade.php must not read KIOSK_USE_POS_WIZARD via raw env()');
    }

    /**
     * P2-t — the Stripe webhook boot guard must read config('services.stripe.webhook_secret')
     * (baked at config:cache time) instead of raw env(), which returns null under
     * config:cache and would silently neutralise the fail-fast guard.
     */
    public function test_stripe_webhook_secret_guard_reads_config_not_env(): void
    {
        // The config key must exist and resolve (defaults to '' when the env var is unset).
        config(['services.stripe.webhook_secret' => 'whsec_test_value']);
        $this->assertSame('whsec_test_value', config('services.stripe.webhook_secret'));

        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $this->assertNotFalse($provider);
        $this->assertStringContainsString(
            "config('services.stripe.webhook_secret')",
            $provider,
            'The Stripe boot guard must read the secret via config()'
        );
        $this->assertStringNotContainsString(
            "env('STRIPE_WEBHOOK_SECRET')",
            $provider,
            'The Stripe boot guard must not read STRIPE_WEBHOOK_SECRET via raw env() (null under config:cache)'
        );
    }
}
