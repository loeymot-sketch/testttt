<?php

/**
 * [GOAL_V1_LOCAL_GOLIVE W1.4 — DASH-SEM-04 heal 2026-06-01]
 *
 * channelStatistics counted refund counter-entry mirrors (parent_order_id set,
 * source NULL) and mis-bucketed them into 'Web', skewing the channel percentages.
 * They must be excluded (not placed orders).
 *
 * @group sentinel
 * @group dashboard
 */

namespace Tests\Feature\Dashboard;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ChannelStatisticsMirrorExcludedSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_channel_statistics_excludes_refund_mirrors(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');
        $branch = Branch::factory()->create();
        $now = Carbon::now('Europe/Paris')->setTime(12, 0);

        // 2 real placed orders today.
        $parent = Order::factory()->create([
            'branch_id' => $branch->id, 'status' => OrderStatus::DELIVERED, 'payment_status' => PaymentStatus::PAID,
            'order_type' => OrderType::KIOSK, 'order_datetime' => $now, 'total' => 30, 'source' => Source::WEB,
            'is_advance_order' => Ask::NO,
        ]);
        Order::factory()->create([
            'branch_id' => $branch->id, 'status' => OrderStatus::DELIVERED, 'payment_status' => PaymentStatus::PAID,
            'order_type' => OrderType::KIOSK, 'order_datetime' => $now, 'total' => 20, 'source' => Source::WEB,
            'source_surface' => 'kiosk', 'is_advance_order' => Ask::NO,
        ]);
        // Refund counter-entry mirror today (source NULL, parent_order_id set) — must NOT count.
        Order::factory()->create([
            'branch_id' => $branch->id, 'parent_order_id' => $parent->id, 'status' => OrderStatus::RETURNED,
            'payment_status' => PaymentStatus::REFUNDED, 'order_type' => OrderType::KIOSK, 'order_datetime' => $now,
            'total' => -30, 'source' => null, 'is_advance_order' => Ask::NO,
        ]);

        $channels = collect(app(DashboardService::class)->channelStatistics());
        $web = (float) $channels->firstWhere('name', 'Web')['value'];
        $kiosk = (float) $channels->firstWhere('name', 'Kiosk/App')['value'];

        // 2 real orders (1 Web + 1 kiosk) → 50% / 50%. With the mirror counted
        // (denominator 3, mirror bucketed into Web) it would be Web 66.7% / Kiosk 33.3%.
        $this->assertEqualsWithDelta(50.0, $web, 0.5, 'Web must be 50% with the refund mirror excluded, not 66.7%.');
        $this->assertEqualsWithDelta(50.0, $kiosk, 0.5, 'Kiosk/App must be 50% with the refund mirror excluded.');
    }
}
