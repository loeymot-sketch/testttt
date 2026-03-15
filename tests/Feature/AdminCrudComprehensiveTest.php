<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Coupon;
use App\Models\DiningTable;
use App\Models\KioskMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Module 2: CRUD Admin Complet (20 tests)
 * 
 * Surface: /api/admin/setting/*, /api/admin/item/*, /api/admin/branch/*, etc.
 * Priorité: 🟡 Haute
 */
class AdminCrudComprehensiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function setupAdmin()
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $admin = \Database\Factories\UserFactory::new()->create([]);
        $admin->assignRole('Admin');
        return [$branch, $admin];
    }

    private function apiKey(): string
    {
        return config('app.api_key', env('MIX_API_KEY', 'test-api-key'));
    }

    // ==================== 2A. GESTION DES PRODUITS (Items) ====================

    /**
     * 2.1 - Lister les items
     * Route: GET /api/admin/setting/item
     */
    public function test_admin_can_list_items()
    {
        [$branch, $admin] = $this->setupAdmin();
        $item = \Database\Factories\ItemFactory::new()->create([]);
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/admin/setting/item');
        
        $response->assertStatus(200);
    }

    /**
     * 2.2 - Créer un item
     * Route: POST /api/admin/item
     */
    public function test_admin_can_create_item()
    {
        [$branch, $admin] = $this->setupAdmin();
        $category = \Database\Factories\ItemCategoryFactory::new()->create();
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/admin/item', [
                'name' => 'Test Item',
                'price' => 10.99,
                'item_category_id' => $category->id,
                'status' => \App\Enums\Status::ACTIVE,
            ]);
        
        $response->assertStatus(201);
        $this->assertDatabaseHas('items', ['name' => 'Test Item']);
    }

    /**
     * 2.3 - Voir un item
     * Route: GET /api/admin/setting/item/show/{id}
     */
    public function test_admin_can_view_item()
    {
        [$branch, $admin] = $this->setupAdmin();
        $item = \Database\Factories\ItemFactory::new()->create([]);
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson("/api/admin/setting/item/show/{$item->id}");
        
        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $item->id]);
    }

    /**
     * 2.4 - Modifier un item (prix)
     * Route: PUT /api/admin/setting/item/{id}
     */
    public function test_admin_can_update_item_price()
    {
        [$branch, $admin] = $this->setupAdmin();
        $item = \Database\Factories\ItemFactory::new()->create([
            'price' => 10.00
        ]);
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->putJson("/api/admin/setting/item/{$item->id}", [
                'name' => $item->name,
                'price' => 15.99,
                'item_category_id' => $item->item_category_id,
                'status' => $item->status,
            ]);
        
        $response->assertStatus(200);
        $this->assertDatabaseHas('items', ['id' => $item->id, 'price' => 15.99]);
    }

    /**
     * 2.5 - Supprimer un item
     * Route: DELETE /api/admin/setting/item/{id}
     */
    public function test_admin_can_delete_item()
    {
        [$branch, $admin] = $this->setupAdmin();
        $item = \Database\Factories\ItemFactory::new()->create([]);
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->deleteJson("/api/admin/setting/item/{$item->id}");
        
        $response->assertStatus(200);
        $this->assertDatabaseMissing('items', ['id' => $item->id]);
    }

    // ==================== 2B. GESTION DES CATÉGORIES ====================

    /**
     * 2.6 - Lister catégories
     * Route: GET /api/admin/setting/item-category
     */
    public function test_admin_can_list_categories()
    {
        [$branch, $admin] = $this->setupAdmin();
        $category = \Database\Factories\ItemCategoryFactory::new()->create();
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/admin/setting/item-category');
        
        $response->assertStatus(200);
    }

    /**
     * 2.7 - Créer une catégorie
     * Route: POST /api/admin/setting/item-category
     */
    public function test_admin_can_create_category()
    {
        [$branch, $admin] = $this->setupAdmin();
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/admin/setting/item-category', [
                'name' => 'Test Category',
                'status' => \App\Enums\Status::ACTIVE,
            ]);
        
        $response->assertStatus(201);
        $this->assertDatabaseHas('item_categories', ['name' => 'Test Category']);
    }

    /**
     * 2.8 - Supprimer une catégorie
     * Route: DELETE /api/admin/setting/item-category/{id}
     */
    public function test_admin_can_delete_category()
    {
        [$branch, $admin] = $this->setupAdmin();
        $category = \Database\Factories\ItemCategoryFactory::new()->create();
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->deleteJson("/api/admin/setting/item-category/{$category->id}");
        
        $response->assertStatus(200);
    }

    // ==================== 2C. GESTION DES SUCCURSALES (Branches) ====================

    /**
     * 2.9 - Lister les branches
     * Route: GET /api/admin/setting/branch
     */
    public function test_admin_can_list_branches()
    {
        [$branch, $admin] = $this->setupAdmin();
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/admin/setting/branch');
        
        $response->assertStatus(200);
    }

    /**
     * 2.10 - Créer une branche
     * Route: POST /api/admin/setting/branch
     */
    public function test_admin_can_create_branch()
    {
        [$branch, $admin] = $this->setupAdmin();
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/admin/setting/branch', [
                'name' => 'Test Branch',
                'email' => 'branch@test.com',
                'phone' => '+33123456789',
                'address' => '123 Test Street',
                'status' => \App\Enums\Status::ACTIVE,
            ]);
        
        $response->assertStatus(201);
        $this->assertDatabaseHas('branches', ['name' => 'Test Branch']);
    }

    /**
     * 2.11 - Modifier une branche
     * Route: PUT /api/admin/setting/branch/{id}
     */
    public function test_admin_can_update_branch()
    {
        [$branch, $admin] = $this->setupAdmin();
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->putJson("/api/admin/setting/branch/{$branch->id}", [
                'name' => 'Updated Branch Name',
                'email' => $branch->email,
                'phone' => $branch->phone,
                'address' => $branch->address,
                'status' => $branch->status,
            ]);
        
        $response->assertStatus(200);
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'name' => 'Updated Branch Name']);
    }

    /**
     * 2.12 - Supprimer une branche
     * Route: DELETE /api/admin/setting/branch/{id}
     */
    public function test_admin_can_delete_branch()
    {
        [$branch, $admin] = $this->setupAdmin();
        $newBranch = \Database\Factories\BranchFactory::new()->create();
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->deleteJson("/api/admin/setting/branch/{$newBranch->id}");
        
        $response->assertStatus(200);
    }

    // ==================== 2D. GESTION DES COUPONS ====================

    /**
     * 2.13 - Créer un coupon
     * Route: POST /api/admin/coupon
     */
    public function test_admin_can_create_coupon()
    {
        [$branch, $admin] = $this->setupAdmin();

        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/admin/coupon', [
                'name' => 'TEST10',
                'code' => 'TEST10',
                'discount' => 10,
                'discount_type' => 1, // 1 = percentage
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('coupons', ['code' => 'TEST10']);
    }

    /**
     * 2.14 - Lister les coupons
     * Route: GET /api/admin/coupon
     */
    public function test_admin_can_list_coupons()
    {
        [$branch, $admin] = $this->setupAdmin();
        $coupon = \Database\Factories\CouponFactory::new()->create();
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/admin/coupon');
        
        $response->assertStatus(200);
    }

    /**
     * 2.15 - Supprimer un coupon
     * Route: DELETE /api/admin/coupon/{id}
     */
    public function test_admin_can_delete_coupon()
    {
        [$branch, $admin] = $this->setupAdmin();
        $coupon = \Database\Factories\CouponFactory::new()->create();
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->deleteJson("/api/admin/coupon/{$coupon->id}");
        
        $response->assertStatus(200);
    }

    // ==================== 2E. GESTION DES TABLES (Dine-In) ====================

    /**
     * 2.16 - Créer une table
     * Route: POST /api/admin/dining-table
     */
    public function test_admin_can_create_dining_table()
    {
        [$branch, $admin] = $this->setupAdmin();
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/admin/dining-table', [
                'name' => 'Table 1',
                'size' => 4,
                
                'status' => \App\Enums\Status::ACTIVE,
            ]);
        
        $response->assertStatus(201);
        $this->assertDatabaseHas('dining_tables', ['name' => 'Table 1']);
    }

    /**
     * 2.17 - Lister les tables
     * Route: GET /api/admin/dining-table
     */
    public function test_admin_can_list_dining_tables()
    {
        [$branch, $admin] = $this->setupAdmin();
        $table = \Database\Factories\DiningTableFactory::new()->create([]);
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/admin/dining-table');
        
        $response->assertStatus(200);
    }

    /**
     * 2.18 - Supprimer une table
     * Route: DELETE /api/admin/dining-table/{id}
     */
    public function test_admin_can_delete_dining_table()
    {
        [$branch, $admin] = $this->setupAdmin();
        $table = \Database\Factories\DiningTableFactory::new()->create([]);
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->deleteJson("/api/admin/dining-table/{$table->id}");
        
        $response->assertStatus(200);
    }

    // ==================== 2F. GESTION DES BORNES KIOSK ====================

    /**
     * 2.19 - Créer une borne
     * Route: POST /api/admin/setting/kiosk-machine
     */
    public function test_admin_can_create_kiosk_machine()
    {
        [$branch, $admin] = $this->setupAdmin();
        $kioskUser = \Database\Factories\UserFactory::new()->create([]);
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/admin/setting/kiosk-machine', [
                'name' => 'Kiosk Test',
                'username' => 'kioskx123',
                'password' => 'password123',
                
                'user_id' => $kioskUser->id,
                'status' => \App\Enums\Status::ACTIVE,
            ]);
        
        $response->assertStatus(201);
        $this->assertDatabaseHas('kiosk_machines', ['name' => 'Kiosk Test']);
    }

    /**
     * 2.20 - Désactiver une borne
     * Route: POST /api/admin/setting/kiosk-machine/change-status/{id}
     */
    public function test_admin_can_change_kiosk_machine_status()
    {
        [$branch, $admin] = $this->setupAdmin();
        $kioskUser = \Database\Factories\UserFactory::new()->create([]);
        $kiosk = \Database\Factories\KioskMachineFactory::new()->create([
            
            'user_id' => $kioskUser->id,
            'status' => \App\Enums\Status::ACTIVE,
        ]);
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson("/api/admin/setting/kiosk-machine/change-status/{$kiosk->id}", [
                'status' => \App\Enums\Status::INACTIVE,
            ]);
        
        $response->assertStatus(200);
        $this->assertDatabaseHas('kiosk_machines', [
            'id' => $kiosk->id,
            'status' => \App\Enums\Status::INACTIVE,
        ]);
    }
}
