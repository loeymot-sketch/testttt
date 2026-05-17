<?php

namespace Tests\Feature\Orders;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
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

class KDSAllergenVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_kds_endpoint_exposes_per_item_allergens_snapshot(): void
    {
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $branch = Branch::forceCreate([
            'name' => 'KDS Allergen Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '40 rue kds',
            'status' => 1,
        ]);

        $chef = User::forceCreate([
            'name' => 'Chef Allergens',
            'email' => 'chef-allergens@test.local',
            'username' => 'chef_allergens',
            'phone' => '0600000040',
            'password' => bcrypt('password123'),
            'branch_id' => $branch->id,
            // [Sprint H1 Z6-06 2026-05-17] Was `1`; canonical user-status
            // ACTIVE = 5 (App\Enums\Status). EnsureUserStatusActive rejects
            // any non-ACTIVE user — pre-existing test-fixture data bug.
            'status' => Status::ACTIVE,
        ]);
        $chef->assignRole('Chef');

        $customer = User::factory()->create([
            'branch_id' => $branch->id,
        ]);

        $tax = Tax::create([
            'name' => 'TVA 10',
            'code' => 'TVA10',
            'tax_rate' => 10,
            'type' => 2,
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'KDS Category',
            'slug' => 'kds-category-allergens',
            'status' => Status::ACTIVE,
        ]);

        $peanutItem = Item::forceCreate([
            'name' => 'Sauce Arachides',
            'slug' => 'sauce-arachides',
            'price' => 8.50,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        $plainItem = Item::forceCreate([
            'name' => 'Simple Frites',
            'slug' => 'simple-frites',
            'price' => 4.00,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        $order = Order::forceCreate([
            'order_serial_no' => 'KDS-ALLERGEN-001',
            'user_id' => $customer->id,
            'branch_id' => $branch->id,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'payment_method' => 1,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'subtotal' => 12.50,
            'total' => 12.50,
            'discount' => 0,
            'delivery_charge' => 0,
            'order_datetime' => now(),
        ]);

        OrderItem::forceCreate([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $peanutItem->id,
            'quantity' => 1,
            'discount' => 0,
            'tax_name' => 'TVA 10',
            'tax_rate' => 10,
            'tax_type' => 2,
            'tax_amount' => 0.85,
            'price' => 8.50,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([]),
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'total_price' => 8.50,
            'instruction' => 'Attention allergene',
            'allergens_snapshot' => ['arachides'],
        ]);

        OrderItem::forceCreate([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $plainItem->id,
            'quantity' => 1,
            'discount' => 0,
            'tax_name' => 'TVA 10',
            'tax_rate' => 10,
            'tax_type' => 2,
            'tax_amount' => 0.40,
            'price' => 4.00,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([]),
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'total_price' => 4.00,
            'instruction' => null,
            'allergens_snapshot' => [],
        ]);

        $response = $this
            ->actingAs($chef, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/kds-order');

        $response->assertOk();
        $response->assertJsonPath('data.0.order_items.0.allergens_snapshot.0', 'arachides');
        $response->assertJsonPath('data.0.order_items.1.allergens_snapshot', []);
    }
}
