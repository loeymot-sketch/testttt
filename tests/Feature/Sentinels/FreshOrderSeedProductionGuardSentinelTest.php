<?php

namespace Tests\Feature\Sentinels;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @FK-ID FK-FRESH-ORDER-SEED-PROD-GUARD
 * @source Wave I — WI-3 DBA-005 / SEC-009 (2026-05-19)
 *
 * Sentinel : `seed:fresh-orders` (App\Console\Commands\FreshOrderSeed) MUST
 * refuse to run in APP_ENV=production. The command disables
 * `FOREIGN_KEY_CHECKS` and TRUNCATEs both `orders` and `order_items`
 * unconditionally. Without the env guard, an operator running the wrong
 * command on a production console would WIPE the entire fiscal order
 * history — NF525 chain breakage, irreversible data loss.
 *
 * Mirrors the canonical guard pattern used by E2EStressCommand (which also
 * refuses production), CleanupTestFixturesCommand, EnsureKioskMachineCommand,
 * and Iter15CleanupTestOrdersCommand.
 *
 * Anti-régression : si quelqu'un retire le bloc prod-guard ou inverse la
 * condition, ce test casse immédiatement et la mission redevient bloquante.
 */
class FreshOrderSeedProductionGuardSentinelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Coerce the runtime environment to `production` so
     * `app()->environment('production')` gates inside the command fire.
     * Mirrors the canonical pattern documented in
     * tests/Feature/Outbox/OutboxDeliveryTest.php::forceProductionEnv.
     */
    private function forceProductionEnv(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertTrue(
            $this->app->environment('production'),
            'Test setup must successfully coerce APP_ENV=production for the gate to be exercised.'
        );
    }

    public function test_production_env_refuses_and_preserves_orders(): void
    {
        // Seed a canary order via the standard factory (auto-builds FK
        // dependencies + correct columns). The unguarded TRUNCATE would
        // wipe this row.
        $canary = Order::factory()->create([
            'order_serial_no' => 'WJ2-CANARY-PROD-GUARD',
        ]);

        $this->forceProductionEnv();

        // Command MUST exit with FAILURE and NOT truncate.
        $this->artisan('seed:fresh-orders')
            ->expectsOutputToContain('refuse')
            ->assertExitCode(Command::FAILURE);

        // The canary row MUST still exist — proves the TRUNCATE was blocked
        // by the early-return guard BEFORE the destructive statement ran.
        // (CRITICAL: a failure here means fresh-orders truncated the orders
        // table in production — NF525 chain breakage + irreversible data loss.)
        $this->assertDatabaseHas('orders', [
            'id' => $canary->id,
            'order_serial_no' => 'WJ2-CANARY-PROD-GUARD',
        ]);
    }

    public function test_local_env_does_not_emit_production_refusal(): void
    {
        // Coerce to local — guard MUST NOT fire.
        $this->app->detectEnvironment(fn () => 'local');
        $this->assertFalse($this->app->environment('production'));

        // We don't assert the command succeeds end-to-end because the
        // downstream OrderTableSeeder chain isn't designed to run under
        // the :memory: sqlite test fixture. What we DO assert : the
        // production-refusal short-circuit branch is NOT taken.
        // Capture the artisan output and assert the refusal message is
        // absent — the command at least proceeds past the env guard.
        $exitCode = -1;
        try {
            $exitCode = $this->artisan('seed:fresh-orders')->run();
        } catch (\Throwable $e) {
            // Downstream seeders may fail under sqlite — irrelevant to this
            // sentinel. What matters is the failure is NOT the env-guard one.
            $this->assertStringNotContainsString(
                'refuse to run in APP_ENV=production',
                $e->getMessage(),
                'Local env must NOT trip the production refusal guard.'
            );
            return;
        }

        // If it ran cleanly to completion, exit code is also fine — what
        // matters is no production-refusal branch was taken.
        $this->assertNotSame(
            Command::FAILURE,
            $exitCode,
            'Local env must not return the production-refusal FAILURE code.'
        );
    }

    public function test_testing_env_does_not_emit_production_refusal(): void
    {
        // Default phpunit env is `testing`. Same expectation as local —
        // guard MUST NOT fire in non-production envs.
        $this->assertFalse(
            $this->app->environment('production'),
            'Default phpunit env should not be production.'
        );

        $exitCode = -1;
        try {
            $exitCode = $this->artisan('seed:fresh-orders')->run();
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString(
                'refuse to run in APP_ENV=production',
                $e->getMessage(),
                'Testing env must NOT trip the production refusal guard.'
            );
            return;
        }

        $this->assertNotSame(
            Command::FAILURE,
            $exitCode,
            'Testing env must not return the production-refusal FAILURE code.'
        );
    }
}
