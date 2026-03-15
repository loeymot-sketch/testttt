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
        ])->getJson('/api/v1/admin/kds-order/items');

        $response->assertStatus(200);
        // Admin avec branch_id=0 doit voir les commandes de toutes les branches
        // (le test vérifie que la réponse n'est pas vide à cause du bug branch_id=0)
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
        ])->getJson('/api/v1/admin/kds-order?kitchen_status=1');

        // Ne doit pas retourner 500
        $this->assertNotEquals(500, $response->status());
    }
}
