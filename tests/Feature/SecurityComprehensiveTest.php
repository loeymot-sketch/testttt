<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Module 8: Sécurité & Isolation (10 tests)
 * 
 * Tests transversaux de sécurité critique
 * Priorité: 🔴 Critique
 */
class SecurityComprehensiveTest extends TestCase
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
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $admin->assignRole('Admin');
        return [$branch, $admin];
    }

    private function setupKiosk()
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $user = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $kiosk = \Database\Factories\KioskMachineFactory::new()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'username' => 'kiosk123',
            'password' => bcrypt('password123'),
            'status' => \App\Enums\Status::ACTIVE,
            'is_login' => \App\Enums\Ask::NO,
        ]);
        return [$branch, $user, $kiosk];
    }

    private function setupCustomer()
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $customer = \Database\Factories\UserFactory::new()->create([
            'branch_id' => $branch->id,
        ]);
        $customer->assignRole('Customer');
        return [$branch, $customer];
    }

    private function apiKey(): string
    {
        return config('app.api_key', env('MIX_API_KEY', 'test-api-key'));
    }

    /**
     * 8.1 - Kiosk → Admin Dashboard (bloqué)
     * Token kiosk ne doit pas accéder aux routes admin
     * Note: Déjà testé dans AntiGravityTest (T05)
     */
    public function test_kiosk_cannot_access_admin_dashboard()
    {
        [$branch, $user, $kiosk] = $this->setupKiosk();
        
        $response = $this->actingAs($user)
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/admin/dashboard');
        
        $this->assertTrue(in_array($response->status(), [401, 403]));
    }

    /**
     * 8.2 - Kiosk → Delete branch (bloqué)
     */
    public function test_kiosk_cannot_delete_branch()
    {
        [$branch, $user, $kiosk] = $this->setupKiosk();
        $targetBranch = \Database\Factories\BranchFactory::new()->create();
        
        $response = $this->actingAs($user)
            ->withHeader('x-api-key', $this->apiKey())
            ->deleteJson("/api/admin/setting/branch/{$targetBranch->id}");
        
        $this->assertTrue(in_array($response->status(), [401, 403]));
    }

    /**
     * 8.3 - Kiosk → Create item (bloqué)
     */
    public function test_kiosk_cannot_create_item()
    {
        [$branch, $user, $kiosk] = $this->setupKiosk();
        $category = \Database\Factories\ItemCategoryFactory::new()->create();
        
        $response = $this->actingAs($user)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/admin/setting/item', [
                'name' => 'Hacked Item',
                'price' => 0.01,
                'item_category_id' => $category->id,
            ]);
        
        $this->assertTrue(in_array($response->status(), [401, 403]));
    }

    /**
     * 8.4 - Kiosk → Edit price (bloqué)
     */
    public function test_kiosk_cannot_edit_item_price()
    {
        [$branch, $user, $kiosk] = $this->setupKiosk();
        $category = \Database\Factories\ItemCategoryFactory::new()->create();
        $item = \Database\Factories\ItemFactory::new()->create([
            'item_category_id' => $category->id,
            'price' => 10.00,
        ]);
        
        $response = $this->actingAs($user)
            ->withHeader('x-api-key', $this->apiKey())
            ->putJson("/api/admin/setting/item/{$item->id}", [
                'name' => $item->name,
                'price' => 0.01, // Prix falsifié
                'item_category_id' => $item->item_category_id,
            ]);
        
        $this->assertTrue(in_array($response->status(), [401, 403]));
    }

    /**
     * 8.5 - Client → Admin routes (bloqué)
     */
    public function test_customer_cannot_access_admin_routes()
    {
        [$branch, $customer] = $this->setupCustomer();
        
        $response = $this->actingAs($customer)
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/admin/dashboard');
        
        $this->assertTrue(in_array($response->status(), [401, 403]));
    }

    /**
     * 8.6 - Branche A ne voit pas branche B
     * Chef de branche A ne doit pas voir les commandes de branche B
     * Note: Déjà testé dans AntiGravityTest (T18)
     */
    public function test_chef_cannot_see_other_branch_orders()
    {
        $branch1 = \Database\Factories\BranchFactory::new()->create();
        $branch2 = \Database\Factories\BranchFactory::new()->create();
        
        $chef1 = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch1->id]);
        $chef1->assignRole('Chef');
        
        \Database\Factories\OrderFactory::new()->create([
            'branch_id' => $branch1->id,
            'status' => \App\Enums\OrderStatus::PREPARING,
        ]);
        \Database\Factories\OrderFactory::new()->create([
            'branch_id' => $branch2->id,
            'status' => \App\Enums\OrderStatus::PREPARING,
        ]);
        
        $response = $this->actingAs($chef1)
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/admin/kds-order');
        
        if ($response->status() == 200) {
            $data = $response->json('data') ?? [];
            // Si des données sont retournées, elles ne doivent contenir que la branche 1
            foreach ($data as $order) {
                $this->assertEquals($branch1->id, $order['branch_id']);
            }
        }
        
        $this->assertTrue(in_array($response->status(), [200, 403]));
    }

    /**
     * 8.7 - POS recalcul prix (sécurité prix)
     * Même si le client envoie un prix falsifié, le serveur doit recalculer
     */
    public function test_pos_recalculates_prices_server_side()
    {
        [$branch, $admin] = $this->setupAdmin();
        $category = \Database\Factories\ItemCategoryFactory::new()->create();
        $item = \Database\Factories\ItemFactory::new()->create([
            'item_category_id' => $category->id,
            'price' => 10.00, // Prix réel en DB
        ]);
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/admin/pos', [
                'order_type' => \App\Enums\OrderType::POS,
                'subtotal' => 0.01, // Prix falsifié
                'total' => 0.01,    // Prix falsifié
                'source' => \App\Enums\Source::POS,
                'customer_id' => $admin->id,
                'branch_id' => $branch->id,
                'is_advance_order' => 0,
                'pos_payment_method' => \App\Enums\PosPaymentMethod::CASH,
                'pos_received_amount' => 0.01,
                'items' => json_encode([[
                    'item_id' => $item->id,
                    'price' => 0.01, // Prix falsifié
                    'quantity' => 1,
                ]]),
            ]);
        
        // L'ordre doit être créé avec le prix correct de la DB
        if (in_array($response->status(), [200, 201])) {
            $order = Order::first();
            $this->assertNotNull($order);
            // Le total doit refléter le prix réel de la DB (10.00), pas le prix falsifié
            $this->assertGreaterThan(0.01, $order->total);
        }
    }

    /**
     * 8.8 - Transition statut invalide (rejetée)
     * PENDING → DELIVERED directement doit être rejeté
     * Note: Déjà testé dans AntiGravityTest (T14)
     */
    public function test_invalid_status_transition_is_rejected()
    {
        [$branch, $admin] = $this->setupAdmin();
        $order = \Database\Factories\OrderFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => \App\Enums\OrderStatus::PENDING,
        ]);
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson("/api/admin/pos-order/change-status/{$order->id}", [
                'status' => \App\Enums\OrderStatus::DELIVERED, // Transition invalide
            ]);
        
        $this->assertTrue(in_array($response->status(), [400, 403, 422]));
    }

    /**
     * 8.9 - Sans API Key (bloqué)
     * Routes /api/admin/* doivent rejeter les requêtes sans x-api-key
     */
    public function test_admin_routes_require_api_key()
    {
        [$branch, $admin] = $this->setupAdmin();
        
        $response = $this->actingAs($admin)
            ->getJson('/api/admin/dashboard'); // Pas de x-api-key
        
        $this->assertTrue(in_array($response->status(), [401, 403]));
    }

    /**
     * 8.10 - Token expiré/révoqué (bloqué)
     * Requête avec token supprimé doit être rejetée
     */
    public function test_revoked_token_is_rejected()
    {
        [$branch, $admin] = $this->setupAdmin();
        
        // Créer et révoquer le token
        $token = $admin->createToken('test-token')->plainTextToken;
        $admin->tokens()->delete(); // Révoquer tous les tokens
        
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/admin/dashboard');
        
        $this->assertTrue(in_array($response->status(), [401, 403]));
    }
}
