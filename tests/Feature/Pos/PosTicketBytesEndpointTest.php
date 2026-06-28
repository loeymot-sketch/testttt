<?php

namespace Tests\Feature\Pos;

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [CAISSE-BRIDGE 2026-06-28] L'endpoint renvoie les octets ESC/POS (base64) rendus
 * serveur, pour impression silencieuse via le pont local caisse.
 */
class PosTicketBytesEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function posUser(Branch $branch): User
    {
        $this->seedSpatieRoles();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $user->assignRole('POS Operator');
        return $user;
    }

    public function test_returns_client_escpos_bytes_base64(): void
    {
        $branch = Branch::factory()->create();
        $user = $this->posUser($branch);
        $order = Order::factory()->create([
            'branch_id' => $branch->id, 'user_id' => $user->id,
            'queue_number' => 'A0010', 'fiscal_sequence_no' => 2560, 'total' => 9.90,
        ]);

        $res = $this->actingAs($user, 'sanctum')
            ->getJson("/api/admin/pos/orders/{$order->id}/escpos?ticket=client");

        $res->assertOk()->assertJsonStructure(['order_id', 'ticket', 'escpos_b64']);
        $this->assertSame('client', $res->json('ticket'));
        $bytes = base64_decode($res->json('escpos_b64'));
        $this->assertStringContainsString("\x1B\x40", $bytes, 'ESC @ init manquant (octets ESC/POS valides)');
        $this->assertStringContainsString("\x1D\x56", $bytes, 'GS V cut manquant');
    }

    public function test_kitchen_ticket_bytes(): void
    {
        $branch = Branch::factory()->create();
        $user = $this->posUser($branch);
        $order = Order::factory()->create(['branch_id' => $branch->id, 'user_id' => $user->id, 'queue_number' => 'A0011']);

        $res = $this->actingAs($user, 'sanctum')
            ->getJson("/api/admin/pos/orders/{$order->id}/escpos?ticket=kitchen");

        $res->assertOk();
        $this->assertSame('kitchen', $res->json('ticket'));
        $this->assertNotEmpty($res->json('escpos_b64'));
    }

    public function test_requires_pos_permission(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]); // sans permission pos
        $order = Order::factory()->create(['branch_id' => $branch->id, 'user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/admin/pos/orders/{$order->id}/escpos?ticket=client")
            ->assertForbidden();
    }
}
