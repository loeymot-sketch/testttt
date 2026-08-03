<?php

namespace Tests\Feature\KDS;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Http\Resources\KDSOrderDetailsResource;
use App\Models\Branch;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [KDS-SCHEDULED-CARD-MISLEADS 2026-07-22] The KDS card must anchor its ATTENTE
 * chrono on the kitchen RELEASE instant (scheduled_at - lead), not created_at.
 * The backend exposes that instant as `kitchen_timer_anchor_iso` so the front has
 * no lead duplication. ASAP orders (no scheduled_at) expose null → the card falls
 * back to created_at, unchanged.
 */
class KdsScheduledTimerAnchorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        config(['kds.scheduled_lead_minutes' => 20]);
    }

    private function make(?Carbon $scheduledAt): Order
    {
        $branch = Branch::factory()->create();

        return Order::factory()->create([
            'branch_id'        => $branch->id,
            'order_type'       => OrderType::TAKEAWAY,
            'status'           => OrderStatus::ACCEPT,
            'payment_status'   => PaymentStatus::PAID,
            'order_datetime'   => now(),
            'is_advance_order' => Ask::NO,
            'scheduled_at'     => $scheduledAt,
        ]);
    }

    /** @test */
    public function scheduled_order_exposes_release_anchor_equal_to_scheduled_at_minus_lead(): void
    {
        $order = $this->make(Carbon::parse('2026-03-10 13:00:00', 'Europe/Paris'))->fresh();

        $payload = (new KDSOrderDetailsResource($order))->resolve();

        // Lead 20 → release = 12:40. Compare against the order's own cast value so
        // the tz/format matches the resource exactly.
        $expected = $order->scheduled_at->copy()->subMinutes(20)->toIso8601String();

        $this->assertSame($expected, $payload['kitchen_timer_anchor_iso'],
            'kitchen_timer_anchor_iso must equal scheduled_at - lead (kitchen release instant).');
        // Sanity: it is EARLIER than the target time, LATER than nothing weird.
        $this->assertTrue(
            Carbon::parse($payload['kitchen_timer_anchor_iso'])->lessThan($order->scheduled_at),
            'The release anchor must precede the scheduled target time.'
        );
    }

    /** @test */
    public function asap_order_exposes_null_anchor(): void
    {
        $order = $this->make(null)->fresh();

        $payload = (new KDSOrderDetailsResource($order))->resolve();

        $this->assertNull($payload['kitchen_timer_anchor_iso'],
            'ASAP orders (no scheduled_at) must expose a null anchor → card keeps created_at.');
    }
}
