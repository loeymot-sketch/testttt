<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * @FK-ID FK-016/FK-028 | @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md | @fix-mission CV1-M06-POS-REVENUE-GUARDS | @reason repeating the same cancellation must not run cashback/refund side effects twice.
 */
class OrderStatusNoopSideEffectsSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_repeated_cancel_invokes_cashback_once_only(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->assignRole('POS Operator');
        // [F-CANCEL-REFUND-PARITY 2026-07-15] Annuler une commande PAYÉE exige `pos-refund`.
        // NB : le grant AVANT Event::fake() — la réinitialisation du cache de permissions Spatie
        // s'appuie sur des events ; Event::fake() posé avant avalait l'invalidation → can() faux → 403.
        $cashier->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('pos-refund', 'sanctum'));

        Event::fake();

        $order = Order::factory()->create([
            'user_id' => $cashier->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::ACCEPT,
            'total' => 25.00,
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'transaction_no' => 'FK-SENTINEL-PAYMENT',
            'amount' => 25.00,
            'payment_method' => 'cash',
            'type' => 'payment',
            'sign' => '+',
        ]);

        $payment = Mockery::mock(PaymentService::class);
        $payment->shouldReceive('cashBack')->once()->andReturnNull();
        $this->app->instance(PaymentService::class, $payment);

        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/admin/pos-order/change-status/' . $order->id, [
                'status' => OrderStatus::CANCELED,
                'reason' => 'sentinel duplicate cancel',
            ]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/admin/pos-order/change-status/' . $order->id, [
                'status' => OrderStatus::CANCELED,
                'reason' => 'sentinel duplicate cancel again',
            ]);

        $payment->shouldHaveReceived('cashBack')->once();
    }
}
