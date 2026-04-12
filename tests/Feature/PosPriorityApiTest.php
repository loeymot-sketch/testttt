<?php

namespace Tests\Feature;

use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Models\Address;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests prioritaires automatisables côté API (coupon / adresse livraison) — alignés local-validation / E2E.
 * Les scénarios wizard complet, édition panier, viandes supplémentaires, reset panier
 * restent à valider en navigateur (Playwright / QA manuel).
 */
class PosPriorityApiTest extends TestCase
{
    use RefreshDatabase;

    private function apiKey(): string
    {
        return config('app.api_key', env('MIX_API_KEY', 'test-api-key'));
    }

    private function setupAdminAndItem(): array
    {
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $branch = \Database\Factories\BranchFactory::new()->create();
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $admin->assignRole('Admin');

        $customer = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $customer->assignRole('Customer');

        $category = \Database\Factories\ItemCategoryFactory::new()->create();
        $item = \Database\Factories\ItemFactory::new()->create([
            'item_category_id' => $category->id,
            'price' => 10.00,
        ]);

        return [$branch, $admin, $customer, $item];
    }

    private function basePosPayload(User $customer, $branch, $item, array $overrides = []): array
    {
        $subtotal = 10.00;
        $total = $subtotal;

        return array_merge([
            'token' => null,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'subtotal' => $subtotal,
            'discount' => 0,
            'coupon_id' => 0,
            'total' => $total,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => Source::POS,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => $total,
            'items' => json_encode([[
                'item_id' => $item->id,
                'item_price' => $item->price,
                'quantity' => 1,
                'total_price' => $subtotal,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ], $overrides);
    }

    public function test_invalid_coupon_id_returns_422(): void
    {
        [$branch, $admin, $customer, $item] = $this->setupAdminAndItem();

        $payload = $this->basePosPayload($customer, $branch, $item, [
            'coupon_id' => 999_999,
        ]);

        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/admin/pos', $payload);

        $response->assertStatus(422);
        $response->assertJsonFragment(['status' => false]);
        $this->assertStringContainsString('Coupon', $response->json('message'));
        $this->assertEquals(0, Order::count());
    }

    public function test_delivery_with_foreign_address_returns_422(): void
    {
        [$branch, $admin, $customer, $item] = $this->setupAdminAndItem();
        $otherCustomer = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $otherCustomer->assignRole('Customer');

        $foreignAddress = Address::create([
            'user_id' => $otherCustomer->id,
            'label' => 'Home',
            'address' => '1 rue test',
            'apartment' => '',
            'latitude' => '0',
            'longitude' => '0',
        ]);

        $subtotal = 10.00;
        $deliveryCharge = 2.00;
        $total = $subtotal + $deliveryCharge;

        $payload = [
            'token' => null,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'subtotal' => $subtotal,
            'discount' => 0,
            'coupon_id' => 0,
            'total' => $total,
            'order_type' => OrderType::DELIVERY,
            'is_advance_order' => 0,
            'source' => Source::POS,
            'delivery_charge' => $deliveryCharge,
            'address_id' => $foreignAddress->id,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => $total,
            'items' => json_encode([[
                'item_id' => $item->id,
                'item_price' => $item->price,
                'quantity' => 1,
                'total_price' => $subtotal,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ];

        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/admin/pos', $payload);

        $response->assertStatus(422);
        $response->assertJsonFragment(['status' => false]);
        $this->assertStringContainsString('Adresse', $response->json('message'));
        $this->assertEquals(0, Order::count());
    }
}
