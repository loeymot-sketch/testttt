<?php

/**
 * [audit-360 W4 2026-06-21 — DASH-ZERODIV guard]
 *
 * channelStatistics() divides each channel count by $total (the period order count).
 * With ZERO orders in the period (fresh install / quiet day / empty date-filter),
 * $total === 0 and `$x / $total` throws DivisionByZeroError — which is an Error, NOT
 * an Exception, so the method's catch(Exception) does NOT catch it → 500 on the
 * dashboard channel-statistics widget. The guard returns all-zero percentages instead.
 *
 * @group sentinel
 * @group dashboard
 */

namespace Tests\Feature\Dashboard;

use App\Models\Branch;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelStatisticsZeroOrdersGuardSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_channel_statistics_does_not_divide_by_zero_with_no_orders(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');
        Branch::factory()->create();

        // Zero orders exist. Before the guard this threw DivisionByZeroError (uncaught
        // by catch(Exception)) → 500. The test would error on the throw; it must return.
        $channels = collect(app(DashboardService::class)->channelStatistics());

        $this->assertCount(4, $channels, 'channelStatistics must still return the 4-channel partition.');
        foreach (['Web', 'Kiosk/App', 'POS', 'Livraison'] as $name) {
            $row = $channels->firstWhere('name', $name);
            $this->assertNotNull($row, "Channel {$name} must be present.");
            $this->assertSame(0, (int) $row['value'], "Channel {$name} must be 0% with zero orders (no NaN/Infinity/throw).");
        }
    }

    public function test_channel_statistics_endpoint_returns_200_with_no_orders(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');
        Branch::factory()->create();

        $this->getJson('/api/admin/dashboard/channel-statistics')
            ->assertOk();
    }
}
