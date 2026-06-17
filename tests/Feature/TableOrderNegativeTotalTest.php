<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\DiningTable;
use Database\Factories\ItemFactory;
use Database\Factories\ItemCategoryFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P6 / P7 — TableOrderRequest (QR / dining-order): negative monetary fields must be rejected.
 */
class TableOrderNegativeTotalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_table_dining_order_rejects_negative_total(): void
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $customer = UserFactory::new()->create(['branch_id' => $branch->id]);
        $table = DiningTable::factory()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategoryFactory::new()->create();
        $item = ItemFactory::new()->create([
            'item_category_id' => $category->id,
            'price' => 12.00,
        ]);

        $payload = [
            'order_type' => OrderType::DINING_TABLE,
            'dining_table_id' => $table->id,
            'subtotal' => 12.00,
            'total' => -12.00,
            'source' => Source::POS,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'is_advance_order' => Ask::NO,
            'items' => json_encode([[
                'item_id' => $item->id,
                'price' => 12.00,
                'quantity' => 1,
            ]]),
        ];

        $response = $this->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/table/dining-order', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['total']);
    }

    public function test_table_dining_order_rejects_negative_subtotal(): void
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $customer = UserFactory::new()->create(['branch_id' => $branch->id]);
        $table = DiningTable::factory()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategoryFactory::new()->create();
        $item = ItemFactory::new()->create([
            'item_category_id' => $category->id,
            'price' => 12.00,
        ]);

        $payload = [
            'order_type' => OrderType::DINING_TABLE,
            'dining_table_id' => $table->id,
            'subtotal' => -5.00,
            'total' => 12.00,
            'source' => Source::POS,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'is_advance_order' => Ask::NO,
            'items' => json_encode([[
                'item_id' => $item->id,
                'price' => 12.00,
                'quantity' => 1,
            ]]),
        ];

        $response = $this->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/table/dining-order', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['subtotal']);
    }

    public function test_table_dining_order_rejects_negative_discount(): void
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $customer = UserFactory::new()->create(['branch_id' => $branch->id]);
        $table = DiningTable::factory()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategoryFactory::new()->create();
        $item = ItemFactory::new()->create([
            'item_category_id' => $category->id,
            'price' => 12.00,
        ]);

        $payload = [
            'order_type' => OrderType::DINING_TABLE,
            'dining_table_id' => $table->id,
            'subtotal' => 12.00,
            'discount' => -2,
            'total' => 12.00,
            'source' => Source::POS,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'is_advance_order' => Ask::NO,
            'items' => json_encode([[
                'item_id' => $item->id,
                'price' => 12.00,
                'quantity' => 1,
            ]]),
        ];

        $response = $this->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/table/dining-order', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['discount']);
    }

    /**
     * [GOAL-2026-05-29 SEC-P1 QR-DISCOUNT] The unauthenticated QR dining-order
     * endpoint had NO manual-discount authorization gate (the POS paths do, via
     * assertPosManualDiscountAllowed). An anonymous customer could self-apply an
     * arbitrary manual discount up to 100% of subtotal. The order is now accepted
     * but the self-applied manual discount MUST be neutralized (forced to 0) —
     * only server-validated coupons may reduce the price.
     */
    public function test_table_dining_order_ignores_self_applied_manual_discount(): void
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $customer = UserFactory::new()->create(['branch_id' => $branch->id]);
        $table = DiningTable::factory()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategoryFactory::new()->create();
        $item = ItemFactory::new()->create([
            'item_category_id' => $category->id,
            'price' => 12.00,
        ]);

        $payload = [
            'order_type' => OrderType::DINING_TABLE,
            'dining_table_id' => $table->id,
            'subtotal' => 12.00,
            'discount' => 12.00, // malicious self-applied 100% discount
            'total' => 12.00,
            'source' => Source::POS,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'is_advance_order' => Ask::NO,
            'items' => json_encode([[
                'item_id' => $item->id,
                'price' => 12.00,
                'quantity' => 1,
            ]]),
        ];

        $response = $this->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/table/dining-order', $payload);

        // Accepted (no hard error) — but the fraudulent discount is neutralized.
        $this->assertContains($response->status(), [200, 201], 'Valid table order should be created.');

        $order = \App\Models\FrontendOrder::query()
            ->where('dining_table_id', $table->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($order, 'Table order should be persisted.');
        $this->assertEquals(
            0.0,
            (float) $order->discount,
            'Self-applied manual discount on the unauthenticated QR path MUST be forced to 0 (pricing-SSOT authorization gate).'
        );
        $this->assertGreaterThanOrEqual(
            11.0,
            (float) $order->total,
            'Total must remain the full backend-computed price (~12), not the fraudulently discounted ~0.'
        );
    }

    /**
     * [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-31 r4] Round-4 bypass-hunt P0:
     * the SSOT branch (V1 default, pricing.use_ssot_service=true) of
     * OrderService::tableOrderStore computed a non-zero server-validated COUPON
     * discount and persisted it with NO gate — the assertDiscretionaryDiscountAllowed
     * call lived only in the dead non-SSOT `else`. At 10% VAT a coupon-discounted
     * table order signs a fiscally-incorrect NF525 Z (frozen F1 split). This
     * sentinel locks the in-SSOT gate: with the flag OFF (V1 default), a valid
     * coupon on a QR table order is refused (422) and nothing is persisted.
     */
    public function test_table_dining_order_refuses_server_validated_coupon_under_killswitch(): void
    {
        // [GOAL-GOLIVE-VAT10 2026-05-31] Post-F1-fix reactivation: default ENABLED.
        // Flip the kill-switch explicitly to verify the table-SSOT coupon gate still
        // refuses (round-4 P0 protection — that path was the live fiscal hole).
        config(['pos.manual_discount_enabled' => false]);

        $branch = \Database\Factories\BranchFactory::new()->create();
        $customer = UserFactory::new()->create(['branch_id' => $branch->id]);
        $table = DiningTable::factory()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategoryFactory::new()->create();
        $item = ItemFactory::new()->create([
            'item_category_id' => $category->id,
            'price' => 20.00,
        ]);
        $coupon = \App\Models\Coupon::forceCreate([
            'name' => 'TABLE10',
            'description' => '10 percent',
            'code' => 'TABLE10',
            'discount' => 10,
            'discount_type' => \App\Enums\DiscountType::PERCENTAGE,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'minimum_order' => 10,
            'maximum_discount' => 5,
            'limit_per_user' => 2,
        ]);

        $payload = [
            'order_type' => OrderType::DINING_TABLE,
            'dining_table_id' => $table->id,
            'subtotal' => 20.00,
            'discount' => 0,
            'total' => 20.00,
            'source' => Source::POS,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'coupon_id' => $coupon->id,
            'is_advance_order' => Ask::NO,
            'items' => json_encode([[
                'item_id' => $item->id,
                'price' => 20.00,
                'quantity' => 1,
            ]]),
        ];

        $response = $this->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/table/dining-order', $payload);

        // The in-SSOT discretionary-discount gate must refuse the coupon discount.
        $response->assertStatus(422);

        // Transaction rolled back — no discounted table order persisted.
        $this->assertSame(
            0,
            \App\Models\FrontendOrder::where('dining_table_id', $table->id)->count(),
            'A coupon-discounted table order must be refused entirely in V1 (no persisted order).'
        );
    }

    /**
     * [prod-finale 2026-06-17 P1 food-safety] tableOrderStore (QR dining-order) MUST freeze the immutable
     * allergen snapshot on each order_item at creation, exactly like posOrderStore (:852) and the
     * frontend/kiosk path. Before this fix tableOrderStore hydrated neither branch → order_item
     * allergens_snapshot persisted NULL → KDSOrderItemsResource decoded [] → orderHasAnyAllergen() false →
     * the chef saw an allergen-bearing item with NO warning line and no orange card border. This sentinel
     * locks the snapshot so a regression re-surfaces as a RED test, not a silent food-safety hole.
     */
    public function test_table_dining_order_freezes_item_allergen_snapshot(): void
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $customer = UserFactory::new()->create(['branch_id' => $branch->id]);
        $table = DiningTable::factory()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategoryFactory::new()->create();
        $item = ItemFactory::new()->create([
            'item_category_id' => $category->id,
            'price' => 12.00,
        ]);

        $gluten = \App\Models\Allergen::forceCreate(['code' => 'gluten', 'name_key' => 'allergens.gluten', 'sort' => 1]);
        $item->allergens()->sync([$gluten->id => ['is_trace' => false]]);

        $payload = [
            'order_type' => OrderType::DINING_TABLE,
            'dining_table_id' => $table->id,
            'subtotal' => 12.00,
            'total' => 12.00,
            'source' => Source::POS,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'is_advance_order' => Ask::NO,
            'items' => json_encode([[
                'item_id' => $item->id,
                'price' => 12.00,
                'quantity' => 1,
            ]]),
        ];

        $response = $this->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/table/dining-order', $payload);

        $this->assertContains($response->status(), [200, 201], 'Valid allergen-bearing table order should be created.');

        $order = \App\Models\FrontendOrder::query()
            ->where('dining_table_id', $table->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($order, 'Table order should be persisted.');

        $snapshotRaw = \Illuminate\Support\Facades\DB::table('order_items')
            ->where('order_id', $order->id)
            ->value('allergens_snapshot');
        $snapshot = json_decode((string) $snapshotRaw, true);

        $this->assertIsArray($snapshot, 'allergens_snapshot MUST be a JSON array frozen at creation, not null (KDS food-safety).');
        $this->assertContains('gluten', $snapshot,
            'The QR-table order_item MUST carry the gluten snapshot so the KDS card shows the allergen warning — parity with POS/kiosk.');
    }
}
