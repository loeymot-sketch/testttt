<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\KioskMachine;
use App\Models\Branch;
use App\Models\User;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Enums\Ask;
use App\Enums\Status;
use App\Enums\OrderType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;

class KioskSecurityTest extends TestCase
{
    use RefreshDatabase, WithoutMiddleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => 'test-api-key']);
        $this->withHeaders([
            'x-api-key' => 'test-api-key',
            'Accept' => 'application/json',
        ]);
    }

    public function test_kiosk_double_login_rejected(): void
    {
        $branch = Branch::forceCreate([
            'name' => 'Test Branch 001',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '1 rue Test',
            'status' => 1
        ]);
        $user = User::forceCreate([
            'name' => 'Test User 001',
            'email' => 'test001@example.com',
            'username' => 'testuser001',
            'password' => bcrypt('password'),
            'status' => 5,
            'branch_id' => $branch->id
        ]);
        $kiosk = KioskMachine::forceCreate([
            'machine_id' => 'MACHINE_001',
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'username' => 'kiosk_test_001',
            'password' => bcrypt('password123'),
            'status' => Status::ACTIVE,
            'is_login' => Ask::YES, // Déjà connecté
        ]);

        $response = $this->postJson('/api/auth/kiosk-login', [
            'username' => 'kiosk_test_001',
            'password' => 'password123',
        ]);

        $response->assertStatus(400);
        $responseData = $response->json();
        $errorMessage = $responseData['errors']['validation'] ?? '';
        // Vérifie le message en français ou anglais
        $this->assertTrue(
            str_contains(strtolower($errorMessage), 'already') ||
            str_contains(strtolower($errorMessage), 'déjà'),
            "Expected error message to contain 'already' or 'déjà', got: {$errorMessage}"
        );
    }

    public function test_kiosk_order_type_is_kiosk(): void
    {
        $branch = Branch::forceCreate([
            'name' => 'Test Branch 002',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '1 rue Test',
            'status' => 1
        ]);
        $user = User::forceCreate([
            'name' => 'Test User 002',
            'email' => 'test002@example.com',
            'username' => 'testuser002',
            'password' => bcrypt('password'),
            'status' => 5,
            'branch_id' => $branch->id
        ]);
        $kiosk = KioskMachine::forceCreate([
            'machine_id' => 'MACHINE_002',
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'username' => 'kiosk_test_002',
            'password' => bcrypt('password123'),
            'status' => Status::ACTIVE,
            'is_login' => Ask::NO,
        ]);

        // Login
        $loginResponse = $this->postJson('/api/auth/kiosk-login', [
            'username' => 'kiosk_test_002',
            'password' => 'password123',
        ]);
        $loginResponse->assertStatus(201);
        $kioskToken = $loginResponse->json('token');

        // Créer une catégorie et un item pour la commande
        $category = ItemCategory::forceCreate([
            'name' => 'Test Category',
            'slug' => 'test-category',
            'status' => Status::ACTIVE,
        ]);
        $item = Item::forceCreate([
            'name' => 'Test Item',
            'slug' => 'test-item',
            'price' => 10.00,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
        ]);

        // Créer une commande
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $kioskToken,
        ])->postJson('/api/frontend/order', [
            'branch_id' => $branch->id,
            'subtotal' => $item->price,
            'total' => $item->price,
            'order_type' => 10, // Client envoie TAKEAWAY
            'is_advance_order' => 0,
            'source' => 1,
            'items' => json_encode([
                ['item_id' => $item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []]
            ]),
        ]);

        $response->assertStatus(201);
        // La commande doit avoir order_type = KIOSK (25), pas TAKEAWAY (10)
        $this->assertDatabaseHas('orders', [
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
        ]);
    }
}
