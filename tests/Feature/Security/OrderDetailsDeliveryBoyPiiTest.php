<?php

namespace Tests\Feature\Security;

use App\Enums\OrderType;
use App\Http\Resources\OrderDetailsResource;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [SELF-AUDIT R7 P2 2026-07-05] OrderDetailsResource renvoyait le LIVREUR via OrderUserResource (email,
 * username, SOLDE portefeuille users.balance). Cette ressource part au CLIENT sur /api/frontend/order/show
 * (suivi de sa propre commande de livraison) → fuite des données staff du livreur assigné. Ce test
 * verrouille la minimisation : le client ne voit QUE nom + téléphone (masqué) du livreur.
 */
class OrderDetailsDeliveryBoyPiiTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_boy_payload_excludes_staff_pii(): void
    {
        $branch = Branch::factory()->create();
        $driver = User::factory()->create([
            'branch_id' => $branch->id,
            'email' => 'driver-secret@staff.local',
            'username' => 'driver_secret',
            'balance' => 250.00,
        ]);
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::DELIVERY,
            'delivery_boy_id' => $driver->id,
        ]);

        $arr = (new OrderDetailsResource($order->load('deliveryBoy', 'orderItems')))->toArray(request());

        $db = $arr['delivery_boy'];
        $this->assertIsArray($db, 'delivery_boy est un payload minimal (tableau), pas la ressource PII complète.');
        $this->assertArrayHasKey('name', $db, 'Le nom du livreur reste (coordination livraison).');
        $this->assertArrayHasKey('phone', $db, 'Le téléphone (masqué) reste.');
        $this->assertArrayNotHasKey('email', $db, 'Le client ne doit PAS voir l\'email du livreur.');
        $this->assertArrayNotHasKey('username', $db, 'Le client ne doit PAS voir le username du livreur.');
        $this->assertArrayNotHasKey('balance', $db, 'Le client ne doit PAS voir le SOLDE portefeuille du livreur.');
        $this->assertArrayNotHasKey('currency_balance', $db);
        // Le nom est bien celui du livreur, mais aucune valeur d'email/solde ne fuit.
        $this->assertSame($driver->name, $db['name']);
    }
}
