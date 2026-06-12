<?php

namespace Tests\Feature\Refund;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Models\ZReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [HEAL dispute-r3 B-R1-06 2026-06-12] Probe lecture-seule du mode de refund.
 *
 * R1/R2 adversarial : le modal PosRefundModal promettait TOUJOURS « génère une
 * commande miroir NF525 » alors que la voie pre-Z (commande dans le Z encore
 * ouvert) ne produit AUCUN miroir (PosOrderController::refundPreZ —
 * changeStatus RETURNED + cashBack). Copy mensongère au moment où le caissier
 * confirme une opération irréversible.
 *
 * Le « sealed? » est un prédicat SERVEUR (SealedOrderGuard, jamais dupliqué
 * client) → ce probe GET expose le mode AVANT confirmation pour que le modal
 * affiche la copy honnête correspondante :
 *   - pre_z         → marquée remboursée dans la journée en cours, pas de miroir
 *   - counter_entry → ticket miroir NF525 généré
 */
class RefundModeProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Permission::firstOrCreate(['name' => 'pos-refund', 'guard_name' => 'sanctum']);
        Permission::firstOrCreate(['name' => 'pos-orders', 'guard_name' => 'sanctum']);
    }

    public function test_pre_z_order_probes_mode_pre_z(): void
    {
        $branch = Branch::factory()->create();
        $admin = $this->newAdmin($branch);
        $order = $this->makeOrder($branch, $admin, Carbon::now()->subHour());

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/pos-order/{$order->id}/refund-mode")
            ->assertOk()
            ->assertJson(['mode' => 'pre_z']);
    }

    public function test_sealed_order_probes_mode_counter_entry(): void
    {
        $branch = Branch::factory()->create();
        $admin = $this->newAdmin($branch);
        $order = $this->makeOrder($branch, $admin, Carbon::now()->subDays(2));

        ZReport::create([
            'branch_id'   => $branch->id,
            'sequence_no' => 1,
            'opened_at'   => Carbon::now()->subDays(3),
            'closed_at'   => Carbon::now()->subDay(),
            'status'      => ZReport::STATUS_CLOSED,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/pos-order/{$order->id}/refund-mode")
            ->assertOk()
            ->assertJson(['mode' => 'counter_entry']);
    }

    public function test_probe_requires_pos_refund_permission(): void
    {
        $branch = Branch::factory()->create();
        $operator = User::factory()->create([
            'branch_id' => $branch->id,
            'password' => Hash::make('password'),
        ]);
        $operator->assignRole('POS Operator');
        $operator->givePermissionTo('pos-orders');
        $order = $this->makeOrder($branch, $operator, Carbon::now()->subHour());

        $this->actingAs($operator, 'sanctum')
            ->getJson("/api/admin/pos-order/{$order->id}/refund-mode")
            ->assertForbidden();
    }

    private function makeOrder(Branch $branch, User $customer, Carbon $createdAt): Order
    {
        $order = Order::factory()->create([
            'user_id'            => $customer->id,
            'branch_id'          => $branch->id,
            'order_type'         => OrderType::POS,
            'status'             => OrderStatus::DELIVERED,
            'payment_status'     => PaymentStatus::PAID,
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'subtotal'           => 10.00,
            'total'              => 10.00,
            'total_tax'          => 0,
            'discount'           => 0,
            'created_at'         => $createdAt,
        ]);
        $order->fiscal_sequence_no = 600;
        $order->save();

        return $order->fresh();
    }

    private function newAdmin(Branch $branch): User
    {
        $user = User::factory()->create([
            'branch_id' => $branch->id,
            'password'  => Hash::make('password'),
        ]);
        $user->assignRole('Admin');
        $user->givePermissionTo('pos-orders');
        $user->givePermissionTo('pos-refund');
        $user->givePermissionTo('pos');

        return $user;
    }
}
