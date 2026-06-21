<?php

namespace Tests\Feature\KDS;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CHARACTERIZATION (verify-before-report) for KDS-ABUSE-01.
 *
 * Claim: GET /api/admin/kds-order/items (cook items board) leaks line items
 * from UNPAID/unreleased orders because orderItems() filters only by status
 * (itemBoardStatuses) and NEVER applies KitchenReleaseRule::applyBoardReleaseFilter(),
 * whereas list() does. Control: the same order is correctly ABSENT from list().
 */
class KdsItemsBoardUnreleasedLeakCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_unpaid_delivery_item_is_absent_from_items_board_and_sync(): void
    {
        $branch = Branch::factory()->create();

        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');
        $this->actingAs($chef, 'sanctum');

        $tax = Tax::create([
            'name' => 'TVA 10 LEAK',
            'code' => 'TVA10LEAK',
            'tax_rate' => 10,
            'type' => 2,
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Burgers LEAK',
            'slug' => 'burgers-leak',
            'status' => Status::ACTIVE,
        ]);

        // The secret item whose presence on the cook board proves the leak.
        $secret = Item::forceCreate([
            'name' => 'SECRET_UNPAID_BURGER',
            'slug' => 'secret-unpaid-burger',
            'price' => 9.50,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        // UNPAID DELIVERY order at status=ACCEPT — a REAL reachable state
        // (proven by KdsUnreleasedOrderBumpP1Test). NOT released to the board:
        // payment_status=UNPAID, order_type=DELIVERY, no POS-cash exemption.
        $order = Order::forceCreate([
            'order_serial_no' => 'LEAK-UNPAID-001',
            'user_id' => $chef->id,
            'branch_id' => $branch->id,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::UNPAID,
            'payment_method' => 1,
            'order_type' => OrderType::DELIVERY,
            'source' => Source::WEB,
            'is_advance_order' => Ask::NO,
            'subtotal' => 28.50,
            'total' => 28.50,
            'discount' => 0,
            'delivery_charge' => 0,
            'order_datetime' => now(),
        ]);

        OrderItem::forceCreate([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $secret->id,
            'quantity' => 3,
            'discount' => 0,
            'tax_name' => 'TVA 10 LEAK',
            'tax_rate' => 10,
            'tax_type' => 2,
            'tax_amount' => 0.95,
            'price' => 9.50,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([]),
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'total_price' => 28.50,
            'instruction' => null,
            'allergens_snapshot' => null,
        ]);

        $apiKey = config('app.api_key');

        // CONTROL — list() must NOT surface the unreleased order.
        $listResp = $this->withHeader('x-api-key', $apiKey)
            ->getJson('/api/admin/kds-order');
        $listResp->assertOk();
        $listItemIds = collect(data_get($listResp->json(), 'data', []))
            ->pluck('id')->all();
        $this->assertNotContains(
            $order->id,
            $listItemIds,
            'CONTROL: unreleased UNPAID delivery order must be ABSENT from list() (board cards).'
        );

        // SUBJECT — items board (cook aggregation).
        $itemsResp = $this->withHeader('x-api-key', $apiKey)
            ->getJson('/api/admin/kds-order/items');
        $itemsResp->assertOk();
        $boardItemIds = collect(data_get($itemsResp->json(), 'data', []))
            ->pluck('item_id')->all();

        // SENTINEL (post-heal KDS-ABUSE-01): the UNPAID/unreleased item must be ABSENT from the
        // cook items board — board-release parity with list() restored via applyBoardReleaseFilter().
        $this->assertNotContains(
            $secret->id,
            $boardItemIds,
            'SECRET_UNPAID_BURGER (UNPAID delivery) must NOT appear on the cook items board.'
        );

        // SENTINEL (post-heal KDS-ABUSE-02): the WS-down polling fallback (sync) must also exclude
        // the unreleased order from its orders[] delta payload (no UNPAID leak, no customer phone).
        $syncResp = $this->withHeader('x-api-key', $apiKey)
            ->getJson('/api/admin/kds-order/sync?since=' . urlencode(now()->subHour()->toIso8601String()));
        $syncResp->assertOk();
        $syncOrderIds = collect(data_get($syncResp->json(), 'data.orders', data_get($syncResp->json(), 'orders', [])))
            ->pluck('id')->all();
        $this->assertNotContains(
            $order->id,
            $syncOrderIds,
            'Unreleased UNPAID order must NOT appear in the KDS sync delta payload.'
        );
    }
}
