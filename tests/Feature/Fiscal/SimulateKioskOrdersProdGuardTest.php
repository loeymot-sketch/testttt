<?php

/**
 * [GENIE Wave 0 — FISCAL-SIMULATE-01 2026-06-16] The dev/E2E load command kiosk:simulate-orders
 * creates payment_status=PAID orders without a fiscal sequence (off-book). It must HARD-REFUSE in
 * production so it can never pollute the NF525 trail / escape the Z on a real DB.
 */

namespace Tests\Feature\Fiscal;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimulateKioskOrdersProdGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function test_simulate_command_refuses_in_production_and_creates_no_orders(): void
    {
        $this->app['env'] = 'production'; // force app()->environment('production') === true

        $before = Order::count();

        $this->artisan('kiosk:simulate-orders', ['count' => 5])
            ->expectsOutputToContain('FORBIDDEN in production')
            ->assertExitCode(1);

        $this->assertSame($before, Order::count(), 'No off-book PAID orders may be created in production.');
    }
}
