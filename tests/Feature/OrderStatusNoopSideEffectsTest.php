<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use App\Services\LoyaltyService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class OrderStatusNoopSideEffectsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->app->instance(AuditLogService::class, new class {
            public function write(array $payload): void {}
        });
        $this->app->instance(LoyaltyService::class, new class {
            public function refundPoints($order, string $source): void {}
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_repeated_cancel_invokes_cashback_once_only(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->assignRole('POS Operator');
        // [F-CANCEL-REFUND-PARITY 2026-07-15] Annuler une commande PAYÉE = opération de
        // remboursement → exige désormais `pos-refund`. On l'accorde pour que ce test se
        // concentre sur son vrai objet (idempotence du cashback), pas sur l'authz.
        $cashier->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('pos-refund', 'sanctum'));

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
            'transaction_no' => 'FK-M06-PAYMENT',
            'amount' => 25.00,
            'payment_method' => 'cash',
            'type' => 'payment',
            'sign' => '+',
        ]);

        $payment = Mockery::mock(PaymentService::class);
        $payment->shouldReceive('cashBack')->once()->andReturnNull();
        $this->app->instance(PaymentService::class, $payment);

        $this->actingAs($cashier, 'sanctum')->postJson('/api/admin/pos-order/change-status/'.$order->id, [
            'status' => OrderStatus::CANCELED,
            'reason' => 'duplicate cancel guard',
        ])->assertSuccessful();

        $this->actingAs($cashier, 'sanctum')->postJson('/api/admin/pos-order/change-status/'.$order->id, [
            'status' => OrderStatus::CANCELED,
            'reason' => 'duplicate cancel guard again',
        ])->assertSuccessful();

        $payment->shouldHaveReceived('cashBack')->once();
    }
}
