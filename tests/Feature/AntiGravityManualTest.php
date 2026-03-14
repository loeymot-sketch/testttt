<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Item;
use App\Models\ItemCategory;

class AntiGravityManualTest extends TestCase
{
    public function test_ag_02_and_03()
    {
        $admin = User::where('email', 'admin@lecayenne-henin-beaumont.fr')->first() ?? User::find(1);
        $item = Item::where('name', 'Tacos L (2 Viandes)')->first();

        echo "\n--- AG-02: API wizard_template ---\n";
        $response = $this->actingAs($admin)->getJson("/api/admin/item/{$item->id}");
        $response->assertStatus(200);
        $data = $response->json('data');
        echo "Template: " . ($data['wizard_template'] ?? 'MISSING') . "\n";
        echo "HasMenu: " . (isset($data['has_menu']) ? ($data['has_menu'] ? 'true' : 'false') : 'MISSING') . "\n";

        echo "\n--- AG-03-T01: D-001 Fake Item ID ---\n";
        $response = $this->actingAs($admin)->postJson('/api/admin/pos', [
            'items' => json_encode([['item_id' => 999999, 'quantity' => 1, 'item_price' => 0.01]]),
            'type' => 2,
            'token' => 'test'
        ]);
        echo "Status: " . $response->status() . "\n";
        echo "Message: " . ($response->json('message') ?? '') . "\n";

        echo "\n--- AG-03-T02: D-004 Missing Item ID ---\n";
        $response = $this->actingAs($admin)->postJson('/api/admin/pos', [
            'items' => json_encode([['quantity' => 1, 'item_price' => 5.00]]),
            'type' => 2
        ]);
        echo "Status: " . $response->status() . "\n";
        echo "Errors: " . json_encode($response->json('errors') ?? []) . "\n";

        echo "\n--- AG-03-T03: Valid Order ---\n";
        $response = $this->actingAs($admin)->postJson('/api/admin/pos', [
            'items' => json_encode([['item_id' => $item->id, 'quantity' => 1, 'item_price' => $item->price]]),
            'type' => 2,
            'token' => 'test_success'
        ]);
        echo "Status: " . $response->status() . "\n";
    }
}
