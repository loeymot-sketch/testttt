<?php

namespace Tests\Feature\Kds;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\KitchenDisplaySystemOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * @FK-ID B-KDS-01 (P2, post-ship backlog 2026-06-14) | SAFE LOT
 *
 * The KDS active board caps the feed at 50 orders. Before the fix the cap
 * kept the 50 MOST RECENT (`id desc` + `take(50)`), hiding the OLDEST
 * orders — the inverse of kitchen FIFO need (cook the oldest first). It
 * also exposed only a boolean overflow flag, so the chip could not show
 * the real DB backlog count.
 *
 * Fix: count the real backlog BEFORE truncating (clone the filtered query),
 * then serve the 50 OLDEST orders (`id asc limit 50`) and expose the true
 * backlog through `lastListBacklog()`.
 */
class KdsFeedFifoCapTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
        // Admin branch_id=0 → sees all branches (mirror list() L86-88).
        $this->admin = User::factory()->create(['branch_id' => 0]);
        $this->admin->assignRole('Admin');
    }

    private function seedActiveOrders(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Order::factory()->create([
                'branch_id'        => $this->branch->id,
                'status'           => OrderStatus::PREPARING,
                'payment_status'   => PaymentStatus::PAID,
                'order_type'       => OrderType::TAKEAWAY,
                'order_datetime'   => now(),
                'is_advance_order' => Ask::NO,
            ]);
        }
    }

    public function test_feed_caps_at_50_and_serves_the_oldest_fifo(): void
    {
        $this->seedActiveOrders(55);

        $svc = app(KitchenDisplaySystemOrderService::class);
        $this->actingAs($this->admin);

        // Real KDS board params: it requests id-desc. Pre-fix this kept the 50
        // NEWEST (ids 6..55) and dropped the oldest; post-fix the cap is FIFO
        // (50 oldest) regardless of the requested display order.
        $result = $svc->list(new Request(['order_column' => 'id', 'order_by' => 'desc']));

        $ids = $result->pluck('id');
        $minId = Order::min('id');

        $this->assertSame(50, $result->count(), 'Feed must be capped at 50.');
        $this->assertTrue(
            $ids->contains($minId),
            'The OLDEST order (FIFO head) must be served — it was dropped before the fix.'
        );
    }

    public function test_last_list_backlog_reports_true_db_overflow(): void
    {
        $this->seedActiveOrders(55);

        $svc = app(KitchenDisplaySystemOrderService::class);
        $this->actingAs($this->admin);

        $svc->list(new Request());

        $this->assertSame(5, $svc->lastListBacklog(), 'Backlog must be (total active − 50).');
        $this->assertTrue($svc->lastListOverflow(), 'Overflow flag must stay true above 50.');
    }

    public function test_no_backlog_when_under_cap(): void
    {
        $this->seedActiveOrders(10);

        $svc = app(KitchenDisplaySystemOrderService::class);
        $this->actingAs($this->admin);

        $result = $svc->list(new Request());

        $this->assertSame(10, $result->count());
        $this->assertSame(0, $svc->lastListBacklog());
        $this->assertFalse($svc->lastListOverflow());
    }
}
