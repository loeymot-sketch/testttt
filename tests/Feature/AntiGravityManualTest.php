<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Branch;
use App\Models\Tax;
use App\Enums\TaxType;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AntiGravityManualTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_ag_02_and_03()
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['email' => 'admin@test.com', 'branch_id' => $branch->id]);
        $admin->assignRole('Admin');
        $tax = Tax::factory()->create(['tax_rate' => 0, 'type' => TaxType::FIXED]);
        $item = Item::factory()->create(['name' => 'Tacos L (2 Viandes)', 'price' => 10.00, 'tax_id' => $tax->id]);

        echo "\n--- AG-02: API wizard_template ---\n";
        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->getJson("/api/admin/item/show/{$item->id}");
        $response->assertStatus(200);
        $data = $response->json('data');
        echo "Template: " . ($data['wizard_template'] ?? 'MISSING') . "\n";
        echo "HasMenu: " . (isset($data['has_menu']) ? ($data['has_menu'] ? 'true' : 'false') : 'MISSING') . "\n";

        echo "\n--- AG-03-T01: D-001 Fake Item ID ---\n";
        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', [
            'customer_id' => $admin->id,
            'branch_id' => $branch->id,
            'order_type' => \App\Enums\OrderType::POS,
            'subtotal' => 0.01,
            'discount' => 0,
            'coupon_id' => 0,
            'total' => 0.01,
            'source' => \App\Enums\Source::POS,
            'is_advance_order' => 0,
            'pos_payment_method' => \App\Enums\PosPaymentMethod::CASH,
            'pos_received_amount' => 999,
            'items' => json_encode([['item_id' => 999999, 'quantity' => 1, 'item_price' => 0.01, 'item_variations' => [], 'item_extras' => []]]),
            'token' => 'test'
        ]);
        echo "Status: " . $response->status() . "\n";
        echo "Message: " . ($response->json('message') ?? '') . "\n";

        echo "\n--- AG-03-T02: D-004 Missing Item ID ---\n";
        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', [
            'customer_id' => $admin->id,
            'branch_id' => $branch->id,
            'order_type' => \App\Enums\OrderType::POS,
            'subtotal' => 5.00,
            'discount' => 0,
            'coupon_id' => 0,
            'total' => 5.00,
            'source' => \App\Enums\Source::POS,
            'is_advance_order' => 0,
            'pos_payment_method' => \App\Enums\PosPaymentMethod::CASH,
            'pos_received_amount' => 999,
            'items' => json_encode([['quantity' => 1, 'item_price' => 5.00, 'item_variations' => [], 'item_extras' => []]]),
        ]);
        echo "Status: " . $response->status() . "\n";
        echo "Errors: " . json_encode($response->json('errors') ?? []) . "\n";

        echo "\n--- AG-03-T03: Valid Order ---\n";
        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', [
            'customer_id' => $admin->id,
            'branch_id' => $branch->id,
            'order_type' => \App\Enums\OrderType::POS,
            'subtotal' => (float) $item->price,
            'discount' => 0,
            'coupon_id' => 0,
            'total' => (float) $item->price,
            'source' => \App\Enums\Source::POS,
            'is_advance_order' => 0,
            'pos_payment_method' => \App\Enums\PosPaymentMethod::CASH,
            'pos_received_amount' => 999,
            'items' => json_encode([['item_id' => $item->id, 'quantity' => 1, 'item_price' => $item->price, 'item_variations' => [], 'item_extras' => []]]),
            'token' => 'test_success'
        ]);
        echo "Status: " . $response->status() . "\n";
    }
}
