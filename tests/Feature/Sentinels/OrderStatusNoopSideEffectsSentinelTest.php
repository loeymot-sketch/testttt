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
use Illuminate\Support\Str;
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
        Event::fake();

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
            'transaction_no' => 'FK-SENTINEL-PAYMENT',
            'amount' => 25.00,
            'payment_method' => 'cash',
            'type' => 'payment',
            'sign' => '+',
        ]);

        $payment = Mockery::mock(PaymentService::class);
        $payment->shouldReceive('cashBack')->once()->andReturnNull();
        $this->app->instance(PaymentService::class, $payment);

        // [prod-finale 2026-06-17] change-status/* is idempotency-guarded. DISTINCT X-Idempotency-Key per POST:
        // the SECOND cancel MUST reach the controller so the controller-level no-op dedup (not the middleware
        // replay cache) proves cashBack fires exactly ONCE. Reusing one key would make the frozen middleware
        // replay the first 2xx and never exercise the controller — masking the very invariant under test.
        $this->actingAs($cashier, 'sanctum')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos-order/change-status/' . $order->id, [
                'status' => OrderStatus::CANCELED,
                'reason' => 'sentinel duplicate cancel',
            ]);

        $this->actingAs($cashier, 'sanctum')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos-order/change-status/' . $order->id, [
                'status' => OrderStatus::CANCELED,
                'reason' => 'sentinel duplicate cancel again',
            ]);

        $payment->shouldHaveReceived('cashBack')->once();
    }
}
