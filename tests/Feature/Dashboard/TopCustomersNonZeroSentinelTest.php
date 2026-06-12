<?php

namespace Tests\Feature\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [W-REM T-R2.3 Q-8 — 2026-06-12] "Meilleurs Clients" must never pad with
 * zero-order customers.
 *
 * Finding D-B1 (micro-audit loyalty-validation 2026-06-12, VÉRACITÉ DB):
 * the dashboard "Meilleurs Clients" card listed 2 customers with 0 orders —
 * DashboardService::topCustomers() does `withCount(orders)->orderBy(desc)
 * ->limit(8)` WITHOUT filtering `orders_count > 0`, so when fewer than 8
 * customers ever ordered, the card pads with random 0-order customers
 * ("best customer: 0 commandes" = nonsense for the gérant).
 */
class TopCustomersNonZeroSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_top_customers_excludes_customers_with_zero_orders(): void
    {
        $branch = Branch::factory()->create();

        $buyer = User::factory()->create(['branch_id' => 0]);
        $buyer->assignRole('Customer');
        $ghost = User::factory()->create(['branch_id' => 0]);
        $ghost->assignRole('Customer');

        Order::factory()->create([
            'branch_id'      => $branch->id,
            'user_id'        => $buyer->id,
            'status'         => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'total'          => 25.00,
            'source'         => Source::POS,
            'queue_number'   => 'TOPC-1',
            'order_datetime' => now(),
        ]);

        $top = app(DashboardService::class)->topCustomers();

        $ids = $top->pluck('id')->all();
        $this->assertContains($buyer->id, $ids, 'The customer WITH an order must rank.');
        $this->assertNotContains($ghost->id, $ids, 'A 0-order customer must NEVER pad the Meilleurs Clients card.');

        foreach ($top as $customer) {
            $this->assertGreaterThan(0, (int) $customer->orders_count);
        }
    }

    public function test_refund_mirror_only_customer_is_excluded(): void
    {
        $branch = Branch::factory()->create();

        $buyer = User::factory()->create(['branch_id' => 0]);
        $buyer->assignRole('Customer');
        $refundGhost = User::factory()->create(['branch_id' => 0]);
        $refundGhost->assignRole('Customer');

        $parent = Order::factory()->create([
            'branch_id'      => $branch->id,
            'user_id'        => $buyer->id,
            'status'         => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'total'          => 25.00,
            'source'         => Source::POS,
            'queue_number'   => 'TOPC-2',
            'order_datetime' => now(),
        ]);

        // refundGhost's ONLY row is a refund counter-entry mirror — already
        // excluded from the count (heal 2026-06-01); with the >0 filter the
        // customer row itself must now disappear too.
        Order::factory()->create([
            'branch_id'       => $branch->id,
            'user_id'         => $refundGhost->id,
            'parent_order_id' => $parent->id,
            'status'          => OrderStatus::RETURNED,
            'payment_status'  => PaymentStatus::REFUNDED,
            'total'           => -25.00,
            'source'          => Source::POS,
            'queue_number'    => 'TOPC-2-R',
            'order_datetime'  => now(),
        ]);

        $ids = app(DashboardService::class)->topCustomers()->pluck('id')->all();

        $this->assertContains($buyer->id, $ids);
        $this->assertNotContains($refundGhost->id, $ids);
    }
}
