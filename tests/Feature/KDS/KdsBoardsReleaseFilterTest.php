<?php

namespace Tests\Feature\Kds;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @FK-ID RED-KDS-01 + KDS-OSS-02 (P2/P3, ultra-audit 2026-06-10) | LOT E
 *
 * The MAIN board query filters non-released orders out
 * (KitchenDisplaySystemOrderService:74-82), but the ITEMS board
 * (orderItems(), GET /kds-order/items) and the SYNC feed (KdsSyncService,
 * GET /kds-order/sync) filtered on status/date ONLY — unreleased orders
 * (DELIVERY+UNPAID, POS+UNPAID non-cash) leaked into kitchen prep through
 * both side doors. Same release predicate everywhere.
 */
class KdsBoardsReleaseFilterTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $chef;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
        $this->chef   = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->chef->assignRole('Chef');
    }

    private function makeOrder(array $attrs, ?string $itemName = null): Order
    {
        $order = Order::factory()->create(array_merge([
            'branch_id'      => $this->branch->id,
            'status'         => OrderStatus::ACCEPT,
            'order_datetime' => now(),
        ], $attrs));

        if ($itemName !== null) {
            $item = \App\Models\Item::factory()->create(['name' => $itemName]);
            \App\Models\OrderItem::create([
                'order_id'             => $order->id,
                'branch_id'            => $this->branch->id,
                'item_id'              => $item->id,
                'quantity'             => 1,
                'discount'             => 0,
                'price'                => 5.00,
                'item_variations'      => json_encode([]),
                'item_extras'          => json_encode([]),
                'item_variation_total' => 0,
                'item_extra_total'     => 0,
                'total_price'          => 5.00,
                'tax_name'             => 'TVA 10',
                'tax_rate'             => 10,
                'tax_type'             => 1,
                'tax_amount'           => 0.45,
            ]);
        }

        return $order;
    }

    public function test_items_board_excludes_unreleased_orders(): void
    {
        $this->makeOrder([
            'order_type'     => OrderType::DELIVERY,
            'payment_status' => PaymentStatus::UNPAID,
        ], 'ItemFantomeNonPaye');
        $this->makeOrder([
            'order_type'         => OrderType::TAKEAWAY,
            'payment_status'     => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
        ], 'ItemPlanBLegitime');

        $response = $this->actingAs($this->chef, 'sanctum')
            ->getJson('/api/admin/kds-order/items')
            ->assertSuccessful();

        $body = (string) json_encode($response->json());
        $this->assertStringNotContainsString('ItemFantomeNonPaye', $body, 'Unreleased order item leaked into the items board.');
        $this->assertStringContainsString('ItemPlanBLegitime', $body, 'Plan B counter-collect item must stay on the items board.');
    }

    public function test_sync_feed_excludes_unreleased_orders(): void
    {
        $unreleased = $this->makeOrder([
            'order_type'     => OrderType::DELIVERY,
            'payment_status' => PaymentStatus::UNPAID,
        ]);
        $released = $this->makeOrder([
            'order_type'         => OrderType::TAKEAWAY,
            'payment_status'     => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
        ]);

        $response = $this->actingAs($this->chef, 'sanctum')
            ->getJson('/api/admin/kds-order/sync?since=' . urlencode(now()->subHour()->toIso8601String()))
            ->assertSuccessful();

        $orderIds = collect($response->json('orders'))->pluck('id')->all();

        $this->assertNotContains($unreleased->id, $orderIds, 'Unreleased order leaked into the sync feed.');
        $this->assertContains($released->id, $orderIds, 'Plan B counter-collect order must stay in the feed.');
    }
}
