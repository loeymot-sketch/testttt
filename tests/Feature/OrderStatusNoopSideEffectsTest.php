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
use Illuminate\Support\Str;
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

        // [prod-finale 2026-06-17] DISTINCT idempotency keys per POST: re-using one key would make the
        // FROZEN idempotency middleware return a cached 2xx replay and the SECOND POST would never reach
        // the controller — masking the controller-level duplicate-cancel dedup this test proves (cashBack
        // invoked ONCE). Distinct keys let both requests hit the controller, where the 2nd no-ops.
        $this->actingAs($cashier, 'sanctum')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos-order/change-status/'.$order->id, [
                'status' => OrderStatus::CANCELED,
                'reason' => 'duplicate cancel guard',
            ])->assertSuccessful();

        $this->actingAs($cashier, 'sanctum')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos-order/change-status/'.$order->id, [
                'status' => OrderStatus::CANCELED,
                'reason' => 'duplicate cancel guard again',
            ])->assertSuccessful();

        $payment->shouldHaveReceived('cashBack')->once();
    }
}
