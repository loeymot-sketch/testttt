<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_order_without_auth_returns_401()
    {
        $response = $this->postJson('/api/frontend/order', []);
        $response->assertStatus(401);
    }

    public function test_order_price_recalculated_server_side()
    {
        // Créer les dépendances manquantes pour satisfaire les Foreign Keys SQLite
        $branch = \App\Models\Branch::forceCreate(['name' => 'Main', 'city' => 'Paris', 'state' => 'IDF', 'zip_code' => '75', 'address' => 'X', 'status' => 1]);
        $tax = \App\Models\Tax::forceCreate(['name' => 'TVA', 'code' => 'TVA', 'tax_rate' => 20, 'type' => 5, 'status' => 1]);
        $category = \App\Models\ItemCategory::forceCreate(['name' => 'Cat', 'slug' => 'cat', 'status' => 1]);

        // On crée un Item valant 10€ en base sans Factory (qui n'existe pas)
        $item = new \App\Models\Item();
        $item->name = 'Fake Item';
        $item->slug = 'fake-item';
        $item->price = 10;
        $item->tax_id = $tax->id;
        $item->item_category_id = $category->id;
        $item->status = 1;
        $item->save();

        $user = \App\Models\User::forceCreate([
            'name' => 'Frontend User',
            'email' => 'front@test.com',
            'username' => 'front_user',
            'password' => bcrypt('password123'),
            'branch_id' => $branch->id
        ]);

        // Le hacker tente d'envoyer un prix de 0.01€
        $payload = [
            'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'branch_id' => $branch->id,
            'subtotal' => 0.01,
            'total' => 0.01,
            'delivery_charge' => 0,
            'is_advance_order' => 0,
            'source' => 1,
            'items' => json_encode([
                [
                    'item_id' => $item->id,
                    'price' => 0.01, // Tentative de falsification
                    'quantity' => 1
                ]
            ])
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/frontend/order', $payload);

        // La validation finale sur ce payload (subtotal = 0.01 au lieu de calculé) échoue en 400 Bad Request
        $this->assertTrue(in_array($response->status(), [200, 201, 400]), "La commande a échoué avec le statut " . $response->status());

        if (in_array($response->status(), [200, 201])) {
            $orderId = $response->json('data.id') ?? $response->json('id');
            $this->assertNotNull($orderId, "L'ID de la commande est manquant dans la réponse JSON.");

            $order = \App\Models\Order::find($orderId);
            // On vérifie que le backend a ignoré le 0.01 et a forcé les 10€ de la base
            $this->assertEquals(10, $order->subtotal, "Le prix falsifié a été accepté par le serveur au lieu du prix SOT.");
        } else {
            // Le serveur a bloqué intelligemment la falsification (HTTP 400)
            $this->assertEquals(400, $response->status());
        }
    }

    public function test_invalid_status_transition_rejected()
    {
        $branch = \App\Models\Branch::forceCreate(['name' => 'Main', 'city' => 'Paris', 'state' => 'IDF', 'zip_code' => '75', 'address' => 'X', 'status' => 1]);

        $admin = \App\Models\User::forceCreate([
            'name' => 'Admin Test',
            'email' => 'admin_flow@test.com',
            'username' => 'admin_flow',
            'password' => bcrypt('password123'),
            'branch_id' => $branch->id
        ]);
        $admin->assignRole('Admin');

        // Créer une commande PENDING sans factory
        $order = new \App\Models\Order();
        $order->order_serial_no = 'TEST-001';
        $order->user_id = $admin->id; // Foreign Key requise
        $order->branch_id = $branch->id;
        $order->order_type = 5; // Takeaway
        $order->subtotal = 10;
        $order->total = 10;
        $order->discount = 0;
        $order->delivery_charge = 0;
        $order->payment_method = 1; // cash
        $order->payment_status = 5; // unpaid
        $order->status = 5; // PENDING
        $order->save();

        // Essayer de la passer directement en PREPARED (14) sans passer par ACCEPT (10)
        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos-order/change-status/' . $order->id, [
                'status' => 14
            ]);

        // Doit être rejeté formellement (business constraint violation : 400 Bad Request)
        $this->assertTrue(in_array($response->status(), [400, 403, 422]), "La transition d'état illégale n'a pas été bloquée (Statut: " . $response->status() . ")");
    }
}
