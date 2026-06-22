<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\KioskMachine;
use App\Models\Branch;
use App\Models\User;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Enums\Ask;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\OrderType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Laravel\Sanctum\Sanctum;

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

    /**
     * Même si is_login=YES en base, le contrôleur autorise une nouvelle session :
     * révocation des anciens tokens kiosk + nouveau token (reprise borne / reclaim).
     */
    public function test_kiosk_login_succeeds_when_machine_already_marked_logged_in(): void
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
        KioskMachine::forceCreate([
            'machine_id' => 'MACHINE_001',
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'username' => 'kiosk_test_001',
            'password' => bcrypt('password123'),
            'status' => Status::ACTIVE,
            'is_login' => Ask::YES,
        ]);

        $response = $this->postJson('/api/auth/kiosk-login', [
            'username' => 'kiosk_test_001',
            'password' => 'password123',
        ]);

        $response->assertStatus(201);
        $this->assertNotEmpty($response->json('token'));
    }

    /**
     * [GAP-22-1] La borne envoie TAKEAWAY (à emporter) ou KIOSK (sur place) ; le serveur conserve
     * la valeur (pas de normalisation forcée vers 25), aligné sur KioskCartComponent.
     *
     * @dataProvider validKioskOrderTypesProvider
     */
    public function test_kiosk_order_type_persists_when_valid(int $orderType): void
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
        KioskMachine::forceCreate([
            'machine_id' => 'MACHINE_002',
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'username' => 'kiosk_test_002',
            'password' => bcrypt('password123'),
            'status' => Status::ACTIVE,
            'is_login' => Ask::NO,
        ]);

        Sanctum::actingAs($user, ['kiosk:order']);

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

        $payload = [
            'branch_id' => $branch->id,
            'subtotal' => $item->price,
            'total' => $item->price,
            'order_type' => $orderType,
            'is_advance_order' => 0,
            'source' => Source::WEB,
            'items' => json_encode([
                ['item_id' => $item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []]
            ]),
        ];

        $response = $this->postJson('/api/frontend/order', $this->withQuote($payload));

        $response->assertStatus(201);
        $this->assertDatabaseHas('orders', [
            'branch_id' => $branch->id,
            'order_type' => $orderType,
        ]);
    }

    public static function validKioskOrderTypesProvider(): array
    {
        return [
            'takeaway' => [OrderType::TAKEAWAY],
            'kiosk_sur_place' => [OrderType::KIOSK],
        ];
    }

    /**
     * [SPLASH SECURITY] branch_id du payload ne doit pas permettre d’attribuer la commande à une autre succursale.
     */
    public function test_kiosk_branch_id_is_forced_from_machine(): void
    {
        $branchKiosk = Branch::forceCreate([
            'name' => 'Branch Kiosk',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75001',
            'address' => '1 rue Kiosk',
            'status' => 1
        ]);
        $branchOther = Branch::forceCreate([
            'name' => 'Branch Other',
            'city' => 'Lyon',
            'state' => 'ARA',
            'zip_code' => '69001',
            'address' => '2 rue Other',
            'status' => 1
        ]);
        $user = User::forceCreate([
            'name' => 'Test User 003',
            'email' => 'test003@example.com',
            'username' => 'testuser003',
            'password' => bcrypt('password'),
            'status' => 5,
            'branch_id' => $branchKiosk->id
        ]);
        KioskMachine::forceCreate([
            'machine_id' => 'MACHINE_003',
            'branch_id' => $branchKiosk->id,
            'user_id' => $user->id,
            'username' => 'kiosk_test_003',
            'password' => bcrypt('password123'),
            'status' => Status::ACTIVE,
            'is_login' => Ask::NO,
        ]);

        Sanctum::actingAs($user, ['kiosk:order']);

        $category = ItemCategory::forceCreate([
            'name' => 'Cat 3',
            'slug' => 'cat-3',
            'status' => Status::ACTIVE,
        ]);
        $item = Item::forceCreate([
            'name' => 'Item 3',
            'slug' => 'item-3',
            'price' => 5.00,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
        ]);

        $payload = [
            'branch_id' => $branchOther->id,
            'subtotal' => $item->price,
            'total' => $item->price,
            'order_type' => OrderType::KIOSK,
            'is_advance_order' => 0,
            'source' => Source::WEB,
            'items' => json_encode([
                ['item_id' => $item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []]
            ]),
        ];

        $response = $this->postJson('/api/frontend/order', $this->withQuote($payload));

        $response->assertStatus(201);
        $this->assertDatabaseHas('orders', [
            'branch_id' => $branchKiosk->id,
            'order_type' => OrderType::KIOSK,
        ]);
        $this->assertDatabaseMissing('orders', [
            'branch_id' => $branchOther->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_kiosk_order_rejects_token_without_registered_machine(): void
    {
        $branch = Branch::forceCreate([
            'name' => 'Branch No Machine',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75002',
            'address' => '3 rue No Machine',
            'status' => 1,
        ]);
        $user = User::forceCreate([
            'name' => 'Test User 004',
            'email' => 'test004@example.com',
            'username' => 'testuser004',
            'password' => bcrypt('password'),
            'status' => 5,
            'branch_id' => $branch->id,
        ]);
        Sanctum::actingAs($user, ['kiosk:order']);

        $category = ItemCategory::forceCreate([
            'name' => 'Cat 4',
            'slug' => 'cat-4',
            'status' => Status::ACTIVE,
        ]);
        $item = Item::forceCreate([
            'name' => 'Item 4',
            'slug' => 'item-4',
            'price' => 5.00,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
        ]);

        $this->postJson('/api/frontend/order', [
            'branch_id' => $branch->id,
            'subtotal' => $item->price,
            'total' => $item->price,
            'order_type' => OrderType::KIOSK,
            'is_advance_order' => 0,
            'source' => Source::WEB,
            'items' => json_encode([
                ['item_id' => $item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []],
            ]),
        ])->assertStatus(422);
    }

    public function test_kiosk_order_rejects_inactive_machine(): void
    {
        $branch = Branch::forceCreate([
            'name' => 'Branch Inactive Machine',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75003',
            'address' => '4 rue Inactive',
            'status' => 1,
        ]);
        $user = User::forceCreate([
            'name' => 'Test User 005',
            'email' => 'test005@example.com',
            'username' => 'testuser005',
            'password' => bcrypt('password'),
            'status' => 5,
            'branch_id' => $branch->id,
        ]);
        KioskMachine::forceCreate([
            'machine_id' => 'MACHINE_005',
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'username' => 'kiosk_test_005',
            'password' => bcrypt('password123'),
            'status' => Status::INACTIVE,
            'is_login' => Ask::NO,
        ]);
        Sanctum::actingAs($user, ['kiosk:order']);

        $category = ItemCategory::forceCreate([
            'name' => 'Cat 5',
            'slug' => 'cat-5',
            'status' => Status::ACTIVE,
        ]);
        $item = Item::forceCreate([
            'name' => 'Item 5',
            'slug' => 'item-5',
            'price' => 5.00,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
        ]);

        $this->postJson('/api/frontend/order', [
            'branch_id' => $branch->id,
            'subtotal' => $item->price,
            'total' => $item->price,
            'order_type' => OrderType::KIOSK,
            'is_advance_order' => 0,
            'source' => Source::WEB,
            'items' => json_encode([
                ['item_id' => $item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []],
            ]),
        ])->assertStatus(422);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withQuote(array $payload): array
    {
        $quote = $this->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()
            ->json('data');

        return $payload + [
            'quote_token' => $quote['quote_token'],
            'quote_signature' => $quote['signature'],
        ];
    }
}
