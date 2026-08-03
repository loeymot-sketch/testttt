<?php

namespace Tests\Feature\Security;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * [NUIT-A 2026-07-03 — P2 twin-route authz parity] change-payment-status → REFUNDED doit exiger la même
 * permission `pos-refund` que la route sœur change-status → RETURNED. Sans ça, un POS Operator (qui a
 * `pos-orders` mais PAS `pos-refund`) pouvait marquer une commande REMBOURSÉE = vecteur de remboursement
 * non autorisé / vente off-book.
 */
class PosRefundAuthzParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        // pos-refund n'est pas dans la liste par défaut de seedSpatieRoles → le créer sous le guard
        // sanctum (comme RefundBypassTwinRoutesGuardTest) pour que le gate can('pos-refund') résolve.
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'pos-refund', 'guard_name' => 'sanctum']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'pos-orders', 'guard_name' => 'sanctum']);
    }

    private function order(Branch $branch): Order
    {
        return Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create(['branch_id' => $branch->id])->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::PREPARING,
            'source_surface' => 'pos',
            'total' => 9.00,
        ]);
    }

    private function refundCall(User $actor, int $orderId)
    {
        return $this->actingAs($actor, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson("/api/admin/pos-order/change-payment-status/{$orderId}", [
                'payment_status' => PaymentStatus::REFUNDED,
            ]);
    }

    /** @test */
    public function un_pos_operator_sans_pos_refund_ne_peut_pas_marquer_rembourse(): void
    {
        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator'); // a pos-orders, PAS pos-refund
        $this->assertFalse($operator->can('pos-refund'), 'pré-condition : POS Operator n\'a pas pos-refund');

        $order = $this->order($branch);
        $this->refundCall($operator, $order->id)->assertStatus(403);

        $this->assertNotSame(
            PaymentStatus::REFUNDED,
            (int) $order->fresh()->payment_status,
            'la commande ne doit PAS être passée REMBOURSÉE sans le droit'
        );
    }

    /** @test */
    public function un_porteur_de_pos_refund_n_est_pas_bloque_par_ce_gate(): void
    {
        Queue::fake();
        $branch = Branch::factory()->create();
        // Pattern éprouvé RefundBypassTwinRoutesGuardTest::newAdmin : assignRole('Admin') + grant explicite
        // des permissions de route (pos-orders) et de remboursement (pos-refund).
        $manager = User::factory()->create(['branch_id' => 0]);
        $manager->assignRole('Admin');
        $manager->givePermissionTo('pos-orders');
        $manager->givePermissionTo('pos-refund');

        $order = $this->order($branch);
        // Le gate pos-refund ne doit PAS renvoyer 403 pour un porteur du droit (le reste du flux peut
        // varier — 200/422 —, mais surtout pas un 403 dû à CE gate).
        $this->assertNotSame(403, $this->refundCall($manager, $order->id)->status());
    }
}
