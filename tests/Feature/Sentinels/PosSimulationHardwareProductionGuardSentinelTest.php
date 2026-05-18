<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * @FK-ID FK-POS-SIM-HARDWARE-PROD-GUARD
 * @source CLAUDE.md §8 + V1 Cloud-Prep insights 2026-05-18 P0-#1
 *
 * Sentinel: AppServiceProvider MUST refuse to boot when
 * POS_SIMULATION_HARDWARE=true in production. Prevents accidental NF525
 * cash-trail bypass via env misconfiguration. The flag is a dev-only
 * convenience while the physical cash drawer is not wired — in production
 * the controller-level precondition (assertCashDrawerSessionOpenIfCashInvolved)
 * MUST fire normally.
 *
 * Anti-régression: si quelqu'un retire le bloc prod-guard ou inverse la
 * condition, ce test casse immédiatement.
 */
class PosSimulationHardwareProductionGuardSentinelTest extends TestCase
{
    public function test_production_env_with_simulation_true_throws(): void
    {
        $this->app['env'] = 'production';
        config(['pos.simulation_hardware' => true]);

        // Neutralize the other prod guards so we only test our own
        config(['payment.bypass.enabled' => false]);
        config(['printing.bypass.enabled' => false]);
        config(['broadcasting.default' => 'pusher']);
        config(['queue.default' => 'redis']);
        config(['cache.default' => 'redis']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/POS_SIMULATION_HARDWARE must be false in production/');

        $provider = new \App\Providers\AppServiceProvider($this->app);
        $provider->boot();
    }

    public function test_production_env_with_simulation_false_does_not_throw_simulation_guard(): void
    {
        $this->app['env'] = 'production';
        config(['pos.simulation_hardware' => false]);

        config(['payment.bypass.enabled' => false]);
        config(['printing.bypass.enabled' => false]);
        config(['broadcasting.default' => 'pusher']);
        config(['queue.default' => 'redis']);
        config(['cache.default' => 'redis']);

        $provider = new \App\Providers\AppServiceProvider($this->app);
        // Should not throw the simulation guard (may still throw other prod
        // guards depending on test env, so we just assert no simulation-related
        // exception)
        try {
            $provider->boot();
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString(
                'POS_SIMULATION_HARDWARE',
                $e->getMessage(),
                'AppServiceProvider must NOT throw simulation guard when flag is false'
            );
        }
    }

    public function test_local_env_with_simulation_true_boots_clean(): void
    {
        $this->app['env'] = 'local';
        config(['pos.simulation_hardware' => true]);

        $provider = new \App\Providers\AppServiceProvider($this->app);
        // Should NOT throw — simulation flag is allowed outside production
        $provider->boot();
        $this->assertTrue(true);
    }

    public function test_config_default_is_false_when_env_unset(): void
    {
        // Verify config/pos.php returns false when POS_SIMULATION_HARDWARE
        // is not set (or set to a falsey value). This is the production
        // default that keeps NF525 invariants enforced out-of-the-box.
        $fresh = require base_path('config/pos.php');

        // env() resolves via the underlying putenv/$_ENV — in PHPUnit it
        // typically resolves false. We assert the config shape is correct.
        $this->assertArrayHasKey('simulation_hardware', $fresh);
        $this->assertIsBool($fresh['simulation_hardware']);
        $this->assertFalse(
            $fresh['simulation_hardware'],
            'config/pos.php must default simulation_hardware to false when POS_SIMULATION_HARDWARE is unset'
        );
    }
}
