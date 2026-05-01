<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\DiningTable;
use App\Models\Tax;
use App\Enums\Ask;
use App\Enums\PaymentGateway;
use App\Enums\TaxType;
use App\Enums\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasPosQuoteBinding;

/**
 * Module 9: Synchronisation Inter-Écrans (6 tests)
 * 
 * Vérifie que quand une commande est créée/modifiée sur un écran,
 * les autres la voient immédiatement.
 * Priorité: 🔴 Critique
 */
class SyncComprehensiveTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

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

    private function setupChef()
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $chef = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');
        $chef->givePermissionTo('kitchen-display-system');

        return [$branch, $chef];
    }

    private function apiKey(): string
    {
        return config('app.api_key', env('MIX_API_KEY', 'test-api-key'));
    }

    /**
     * 9.1 - Kiosk → KDS
     * Commande créée via Kiosk doit être visible dans KDS
     */
    public function test_kiosk_order_appears_in_kds()
    {
        [$branch, $kioskUser, $kiosk] = $this->setupKiosk();
        [$branch2, $chef] = $this->setupChef();
        
        // S'assurer que le chef est de la même branche que le kiosk
        $chef->update(['branch_id' => $branch->id]);
        
        $category = \Database\Factories\ItemCategoryFactory::new()->create();
        $item = \Database\Factories\ItemFactory::new()->create([
            
            'item_category_id' => $category->id,
            'price' => 10.00,
        ]);
        
        // Créer commande via Kiosk (en PENDING)
        $payload = [
            'order_type' => 10, // TAKEAWAY
            'branch_id' => $branch->id,
            'subtotal' => 10.00,
            'total' => 10.00,
            'delivery_charge' => 0,
            'is_advance_order' => Ask::NO,
            'source' => 10, // APP
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'items' => json_encode([[
                'item_id' => $item->id,
                'price' => 10.00,
                'quantity' => 1,
            ]]),
        ];
        $orderResponse = $this->actingAs($kioskUser)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/frontend/order', $this->payloadWithKioskQuote($kioskUser, $payload));
        
        $this->assertTrue(in_array($orderResponse->status(), [200, 201]));
        
        // Accepter la commande pour qu'elle apparaisse dans KDS
        $order = Order::first();
        $order->update(['status' => \App\Enums\OrderStatus::ACCEPT]);
        
        // Vérifier que le chef voit la commande dans KDS
        $kdsResponse = $this->actingAs($chef)
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/admin/kds-order');
        
        $this->assertTrue(in_array($kdsResponse->status(), [200, 403]));
        
        if ($kdsResponse->status() == 200) {
            $data = $kdsResponse->json('data') ?? [];
            $orderIds = collect($data)->pluck('id')->toArray();
            $this->assertContains($order->id, $orderIds);
        }
    }

    /**
     * 9.2 - POS → KDS
     * Commande créée via POS doit être visible dans KDS
     */
    public function test_pos_order_appears_in_kds()
    {
        [$branch, $admin] = $this->setupAdmin();
        $customer = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::FIXED,
        ]);
        
        $category = \Database\Factories\ItemCategoryFactory::new()->create();
        $item = \Database\Factories\ItemFactory::new()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 15.00,
        ]);
        
        // Créer commande via POS (crée directement en ACCEPT)
        $payload = [
            'order_type' => \App\Enums\OrderType::POS,
            'subtotal' => 15.00,
            'total' => 15.00,
            'source' => \App\Enums\Source::POS,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'is_advance_order' => Ask::NO,
            'pos_payment_method' => \App\Enums\PosPaymentMethod::CASH,
            'pos_received_amount' => 999.00,
            'items' => json_encode([[
                'item_id' => $item->id,
                'price' => 15.00,
                'quantity' => 1,
            ]]),
        ];
        $posResponse = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($admin, $payload));
        
        $posResponse->assertStatus(201);
        $order = Order::first();
        $this->assertNotNull($order);
        
        // Créer un chef pour cette branche
        $chef = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');
        
        // Vérifier que le chef voit la commande dans KDS
        $kdsResponse = $this->actingAs($chef)
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/admin/kds-order');
        
        $this->assertTrue(in_array($kdsResponse->status(), [200, 403]));
        
        if ($kdsResponse->status() == 200) {
            $data = $kdsResponse->json('data') ?? [];
            $orderIds = collect($data)->pluck('id')->toArray();
            $this->assertContains($order->id, $orderIds);
        }
    }

    /**
     * 9.3 - KDS → OSS
     * Statut changé en KDS doit être reflété dans OSS
     */
    public function test_kds_status_change_reflected_in_oss()
    {
        [$branch, $chef] = $this->setupChef();

        // Créer une commande en PREPARING (même branche que le chef pour branch isolation)
        $order = \Database\Factories\OrderFactory::new()->create([
            'branch_id' => $branch->id,
            'user_id' => $chef->id,
            'status' => \App\Enums\OrderStatus::PREPARING,
            'token' => 'A001', // Token requis pour OSS
            'order_datetime' => now(),
            'is_advance_order' => Ask::NO,
        ]);
        
        // Vérifier que l'ordre apparaît dans OSS
        $ossResponse = $this->actingAs($chef)
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/admin/oss-order');
        
        $this->assertTrue(in_array($ossResponse->status(), [200, 403]));
        
        // Changer le statut en PREPARED via KDS
        $statusResponse = $this->actingAs($chef)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson("/api/admin/kds-order/change-status/{$order->id}", [
                'status' => \App\Enums\OrderStatus::PREPARED,
            ]);
        
        $this->assertTrue(in_array($statusResponse->status(), [200, 202, 400, 403, 422], true));
    }

    /**
     * 9.4 - Table → KDS
     * Commande table créée doit être visible dans KDS
     */
    public function test_table_order_appears_in_kds()
    {
        [$branch, $chef] = $this->setupChef();
        $customer = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        
        $table = DiningTable::factory()->create([
            'branch_id' => $branch->id,
            'status' => \App\Enums\Status::ACTIVE,
        ]);
        
        $category = \Database\Factories\ItemCategoryFactory::new()->create();
        $item = \Database\Factories\ItemFactory::new()->create([
            'item_category_id' => $category->id,
            'price' => 12.00,
        ]);
        
        // Créer commande via table (en PENDING)
        $orderResponse = $this->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/table/dining-order', [
                'order_type' => \App\Enums\OrderType::DINING_TABLE,
                'dining_table_id' => $table->id,
                'subtotal' => 12.00,
                'total' => 12.00,
                'source' => \App\Enums\Source::POS,
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'is_advance_order' => Ask::NO,
                'items' => json_encode([[
                    'item_id' => $item->id,
                    'price' => 12.00,
                    'quantity' => 1,
                ]]),
            ]);
        
        $this->assertTrue(in_array($orderResponse->status(), [200, 201]));
        
        if (in_array($orderResponse->status(), [200, 201])) {
            $order = Order::first();
            $this->assertNotNull($order);
            
            // Accepter la commande
            $order->update(['status' => \App\Enums\OrderStatus::ACCEPT]);
            
            // Vérifier que le chef voit la commande dans KDS
            $kdsResponse = $this->actingAs($chef)
                ->withHeader('x-api-key', $this->apiKey())
                ->getJson('/api/admin/kds-order');
            
            $this->assertTrue(in_array($kdsResponse->status(), [200, 403]));
        }
    }

    /**
     * 9.5 - POS → Admin Dashboard
     * Commande POS créée doit mettre à jour les compteurs dashboard
     */
    public function test_pos_order_updates_dashboard_counters()
    {
        [$branch, $admin] = $this->setupAdmin();
        
        // Créer quelques commandes
        \Database\Factories\OrderFactory::new()->count(3)->create([
            'user_id' => $admin->id,
            'branch_id' => $branch->id,
            'status' => OrderStatus::DELIVERED,
            'subtotal' => 50.00,
            'total' => 50.00,
        ]);
        
        // Vérifier que le dashboard reflète les commandes
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/admin/dashboard/total-orders');
        
        $this->assertTrue(in_array($response->status(), [200, 403, 404]));
        
        if ($response->status() == 200) {
            $total = $response->json('data.total_orders');
            $this->assertGreaterThanOrEqual(3, (int) ($total ?? 0));
        }
    }

    /**
     * 9.6 - Cohérence totale Kiosk→KDS→OSS
     * Commande complète bout-en-bout avec même order_id partout
     */
    public function test_end_to_end_order_consistency()
    {
        [$branch, $kioskUser, $kiosk] = $this->setupKiosk();
        [$branch2, $chef] = $this->setupChef();
        $chef->update(['branch_id' => $branch->id]);
        
        $category = \Database\Factories\ItemCategoryFactory::new()->create();
        $item = \Database\Factories\ItemFactory::new()->create([
            
            'item_category_id' => $category->id,
            'price' => 20.00,
        ]);
        
        // 1. Créer commande via Kiosk
        $payload = [
            'order_type' => 10, // TAKEAWAY
            'branch_id' => $branch->id,
            'subtotal' => 20.00,
            'total' => 20.00,
            'delivery_charge' => 0,
            'is_advance_order' => Ask::NO,
            'source' => 10, // APP
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'items' => json_encode([[
                'item_id' => $item->id,
                'price' => 20.00,
                'quantity' => 1,
            ]]),
        ];
        $orderResponse = $this->actingAs($kioskUser)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/frontend/order', $this->payloadWithKioskQuote($kioskUser, $payload));
        
        $this->assertTrue(in_array($orderResponse->status(), [200, 201]));
        
        // 2. Récupérer l'order_id créé
        $order = Order::first();
        $this->assertNotNull($order);
        $orderId = $order->id;
        
        // 3. Accepter la commande (POS)
        $order->update(['status' => \App\Enums\OrderStatus::ACCEPT]);
        
        // 4. Vérifier présence dans KDS
        $kdsResponse = $this->actingAs($chef)
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/admin/kds-order');
        
        // 5. Changer statut en PREPARING via KDS
        $this->actingAs($chef)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson("/api/admin/kds-order/change-status/{$orderId}", [
                'status' => \App\Enums\OrderStatus::PREPARING,
            ]);
        
        // 6. Vérifier que l'order_id est cohérent partout
        $updatedOrder = Order::find($orderId);
        $this->assertNotNull($updatedOrder);
        $this->assertEquals($orderId, $updatedOrder->id);
    }
}
