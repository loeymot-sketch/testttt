<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Item;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AntiGravityFinalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_ag_02_and_04()
    {
        $admin = User::factory()->create(['email' => 'admin@test.com']);
        $admin->assignRole('Admin');
        $item = Item::factory()->create(['name' => 'Tacos L (2 Viandes)', 'price' => 10.00]);

        echo "\n\n--- AG-02: API wizard_template ---\n";
        $response = $this->actingAs($admin)->getJson("/api/admin/item/{$item->id}");
        $data = $response->json('data');
        echo "[POS-WIZARD] wizard_template from API: " . ($data['wizard_template'] ?? 'MISSING') . "\n";
        echo "has_menu: " . (isset($data['has_menu']) && $data['has_menu'] ? 'true' : 'false') . "\n";

        echo "\n--- AG-04: Order with Instruction ---\n";
        $payload = [
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 1,
                'item_price' => $item->price,
                'instruction' => 'VIANDES: Merguez, Poulet. SAUCE: Harissa. FORMULE: Menu Complet.'
            ]]),
            'type' => 2,
            'token' => 'test_ag_04'
        ];

        $orderResponse = $this->actingAs($admin)->postJson('/api/admin/pos', $payload);
        echo "Order Status: " . $orderResponse->status() . "\n";

        $orderItem = OrderItem::latest()->first();
        echo "Saved Instruction in DB: " . ($orderItem->instruction ?? 'NULL') . "\n";
        
        $response->assertStatus(200);
        $orderResponse->assertStatus(200);
    }
}
