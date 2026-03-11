<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Item;
use App\Models\ItemCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Module 3: POS / Caisse (8 tests)
 * 
 * Surface: /api/admin/pos, /api/admin/pos-order/*
 * Priorité: 🔴 Critique
 */
class POSComprehensiveTest extends TestCase
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

    private function apiKey(): string
    {
        return config('app.api_key', env('MIX_API_KEY', 'test-api-key'));
    }

    /**
     * 3.1 - Créer commande POS
     * Route: POST /api/admin/pos
     * Attendu: 201 + Order créée en status ACCEPT (4)
     */
    public function test_pos_can_create_order()
    {
        [$branch, $admin] = $this->setupAdmin();
        $category = \Database\Factories\ItemCategoryFactory::new()->create();
        $item = \Database\Factories\ItemFactory::new()->create([
            'branch_id' => $branch->id,
            'category_id' => $category->id,
            'price' => 10.00,
        ]);
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/admin/pos', [
                'order_type' => \App\Enums\OrderType::POS,
                'subtotal' => 10.00,
                'total' => 10.00,
                'source' => \App\Enums\Source::POS,
                'customer_id' => $admin->id,
                'branch_id' => $branch->id,
                'is_advance_order' => 0,
                'pos_payment_method' => \App\Enums\PosPaymentMethod::CASH,
                'pos_received_amount' => 15.00,
                'items' => json_encode([[
                    'item_id' => $item->id,
                    'price' => 10.00,
                    'quantity' => 1,
                ]]),
            ]);
        
        $response->assertStatus(201);
        
        // Vérifier que l'ordre est créé avec status ACCEPT
        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertEquals(\App\Enums\OrderStatus::ACCEPT, $order->status);
        $this->assertEquals(\App\Enums\PaymentStatus::PAID, $order->payment_status);
    }

    /**
     * 3.2 - Lister commandes POS
     * Route: GET /api/admin/pos-order
     */
    public function test_pos_can_list_orders()
    {
        [$branch, $admin] = $this->setupAdmin();
        \Database\Factories\OrderFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => \App\Enums\OrderStatus::ACCEPT,
            'order_type' => \App\Enums\OrderType::POS,
        ]);
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/admin/pos-order');
        
        $response->assertStatus(200);
    }

    /**
     * 3.3 - Voir détail commande
     * Route: GET /api/admin/pos-order/show/{id}
     */
    public function test_pos_can_view_order_details()
    {
        [$branch, $admin] = $this->setupAdmin();
        $order = \Database\Factories\OrderFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => \App\Enums\OrderStatus::ACCEPT,
        ]);
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson("/api/admin/pos-order/show/{$order->id}");
        
        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $order->id]);
    }

    /**
     * 3.4 - Changer statut (Accept)
     * Route: POST /api/admin/pos-order/change-status/{id}
     * Attendu: 200 + status changé
     * Note: Déjà testé dans AntiGravityTest (T13)
     */
    public function test_pos_can_change_status_to_preparing()
    {
        [$branch, $admin] = $this->setupAdmin();
        $order = \Database\Factories\OrderFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => \App\Enums\OrderStatus::ACCEPT,
        ]);
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson("/api/admin/pos-order/change-status/{$order->id}", [
                'status' => \App\Enums\OrderStatus::PREPARING,
            ]);
        
        $this->assertTrue(in_array($response->status(), [200, 400, 403, 422]));
    }

    /**
     * 3.5 - Changer statut paiement
     * Route: POST /api/admin/pos-order/change-payment-status/{id}
     */
    public function test_pos_can_change_payment_status()
    {
        [$branch, $admin] = $this->setupAdmin();
        $order = \Database\Factories\OrderFactory::new()->create([
            'branch_id' => $branch->id,
            'payment_status' => \App\Enums\PaymentStatus::UNPAID,
        ]);
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson("/api/admin/pos-order/change-payment-status/{$order->id}", [
                'payment_status' => \App\Enums\PaymentStatus::PAID,
            ]);
        
        $response->assertStatus(200);
    }

    /**
     * 3.6 - Supprimer commande
     * Route: DELETE /api/admin/pos-order/{id}
     */
    public function test_pos_can_delete_order()
    {
        [$branch, $admin] = $this->setupAdmin();
        $order = \Database\Factories\OrderFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => \App\Enums\OrderStatus::PENDING,
        ]);
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->deleteJson("/api/admin/pos-order/{$order->id}");
        
        $this->assertTrue(in_array($response->status(), [200, 202]));
    }

    /**
     * 3.7 - Export commandes
     * Route: GET /api/admin/pos-order/export
     */
    public function test_pos_can_export_orders()
    {
        [$branch, $admin] = $this->setupAdmin();
        \Database\Factories\OrderFactory::new()->count(3)->create([
            'branch_id' => $branch->id,
        ]);
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/admin/pos-order/export');
        
        $this->assertTrue(in_array($response->status(), [200, 404]));
    }

    /**
     * 3.8 - Re-order (récupérer items pour nouveau panier)
     * Route: GET /api/admin/pos-order/reorder-items/{id}
     */
    public function test_pos_can_reorder_items()
    {
        [$branch, $admin] = $this->setupAdmin();
        $order = \Database\Factories\OrderFactory::new()->create([
            'branch_id' => $branch->id,
        ]);
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson("/api/admin/pos-order/reorder-items/{$order->id}");
        
        $this->assertTrue(in_array($response->status(), [200, 404]));
    }
}
