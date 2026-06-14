<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROD-GUARD (ultra-audit P2, 2026-06-14) — kiosk:simulate-orders creates REAL PAID
 * orders (fake revenue) + fires notifications. It must hard-refuse in production so
 * a stray invocation can never pollute the NF525 sales/Z chain with fictitious sales.
 */
class SimulateKioskOrdersProdGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulate_orders_refused_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $exit = $this->artisan('kiosk:simulate-orders', ['count' => 3])->run();

        $this->assertNotSame(0, (int) $exit, 'Command must fail (non-zero exit) in production.');
        $this->assertSame(0, Order::query()->count(), 'No fake PAID orders may be created in production.');
    }
}
