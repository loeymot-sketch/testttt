<?php

namespace Tests\Feature\Authz;

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [SELF-AUDIT R3 P2 2026-07-05] POST /api/admin/table-order/token-create/{order} n'était PAS dans la
 * liste `permission:table-orders` du constructeur (toutes ses sœurs mutantes le sont), et sa FormRequest
 * authorize()=true → un Chef/POS Operator (sans `table-orders`) pouvait écraser orders.token de n'importe
 * quelle commande de sa branche. Ce test verrouille la garde.
 */
class TableOrderTokenCreateAuthzTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_pos_operator_without_table_orders_permission_is_forbidden(): void
    {
        $branch = Branch::factory()->create();
        $order = Order::factory()->create(['branch_id' => $branch->id]);

        // POS Operator : a `pos`/`pos-orders` mais PAS `table-orders`.
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');

        $this->actingAs($operator, 'sanctum');
        $res = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson("/api/admin/table-order/token-create/{$order->id}", ['token' => 'attacker-token']);

        $res->assertStatus(403);
        $this->assertNotSame('attacker-token', (string) $order->fresh()->token, 'Le token ne doit pas avoir été écrasé.');
    }

    public function test_user_with_table_orders_permission_is_allowed_through_the_gate(): void
    {
        $branch = Branch::factory()->create();
        $order = Order::factory()->create(['branch_id' => $branch->id]);

        $user = User::factory()->create(['branch_id' => $branch->id]);
        Permission::findOrCreate('table-orders', 'sanctum');
        $user->givePermissionTo('table-orders');

        $this->actingAs($user, 'sanctum');
        $res = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson("/api/admin/table-order/token-create/{$order->id}", ['token' => 'legit-token']);

        // La garde permission laisse passer (le résultat n'est PAS un 403 d'autorisation).
        $this->assertNotSame(403, $res->getStatusCode(), 'Un porteur de `table-orders` ne doit pas être bloqué par la garde.');
    }
}
