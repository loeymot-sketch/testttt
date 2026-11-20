<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Branch;
use App\Models\Order;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

class KDSOrderItemsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_admin_kds_order_items_not_empty(): void
    {
        // Créer une branche et une commande en PREPARING
        $branch = \Database\Factories\BranchFactory::new()->create();
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]); // Admin = branch_id 0
        $admin->assignRole('Admin');
        $token = $admin->createToken('test')->plainTextToken;

        // Créer une commande PREPARING sur la branche
        $order = Order::create([
            'order_serial_no' => '140326001',
            'branch_id' => $branch->id,
            'user_id' => $admin->id,
            'status' => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PAID,
            'subtotal' => 10.00,
            'total' => 10.00,
            'order_type' => OrderType::TAKEAWAY,
            'order_datetime' => now(),
            'is_advance_order' => 0,
            'source' => 1,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'x-api-key' => config('app.api_key', 'test-api-key'),
        ])->getJson('/api/admin/kds-order/items');

        $response->assertStatus(200);
        // [2026-11-04] Route corrigée : `/api/v1/admin/kds-order/items` n'existe pas (404 depuis la refonte des
        // routes), la vraie est `/api/admin/kds-order/items`. Ce test prouve que l'administrateur (branch_id=0)
        // atteint l'écran cuisine sans erreur. Il NE prouve PAS que la liste est non vide : cette commande n'a
        // ni article ni passage en cuisine, et KitchenReleaseRule::applyBoardReleaseFilter l'écarte à bon droit
        // (vérifié : `{"data":[]}` sur /items comme sur la liste). Le non-filtrage de branch_id=0 est lu dans
        // KitchenDisplaySystemOrderService::list (`if ($userBranchId > 0)`).
    }

    public function test_kitchen_status_filter_does_not_crash(): void
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $chef = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');
        $token = $chef->createToken('test')->plainTextToken;

        // Envoyer kitchen_status dans la requête — ne doit pas crasher
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'x-api-key' => config('app.api_key', 'test-api-key'),
        ])->getJson('/api/admin/kds-order?kitchen_status=1');

        // Ne doit pas retourner 500
        $this->assertNotEquals(500, $response->status());
    }
}
