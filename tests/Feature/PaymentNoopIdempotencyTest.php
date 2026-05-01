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
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentNoopIdempotencyTest extends TestCase
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
    }

    public function test_cashback_is_idempotent_for_same_order(): void
    {
        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::CANCELED,
            'total' => 18.50,
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'transaction_no' => 'FK-M06-PAID',
            'amount' => 18.50,
            'payment_method' => 'cash',
            'type' => 'payment',
            'sign' => '+',
        ]);

        $service = app(PaymentService::class);
        $service->cashBack($order, 'cash', 'FK-M06-CB-1');
        $service->cashBack($order, 'cash', 'FK-M06-CB-2');

        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('type', 'cash_back')->count());
    }

    public function test_payment_transaction_reference_cannot_be_reused_for_another_order(): void
    {
        config([
            'payment.pilot_restrict.enabled' => true,
            'payment.pilot_restrict.allowed_methods' => ['credit'],
        ]);

        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id]);
        $orderA = $this->orderForPayment($customer, $branch);
        $orderB = $this->orderForPayment($customer, $branch);

        $service = app(PaymentService::class);
        $service->payment($orderA, 'credit', 'GATEWAY-DUP-1');

        try {
            $service->payment($orderB, 'credit', 'GATEWAY-DUP-1');
            $this->fail('Expected duplicate transaction reference to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('transaction_no', $exception->errors());
        }

        $this->assertSame(
            1,
            Transaction::where('transaction_no', 'GATEWAY-DUP-1')->where('type', 'payment')->count()
        );
        $this->assertDatabaseMissing('transactions', [
            'order_id' => $orderB->id,
            'transaction_no' => 'GATEWAY-DUP-1',
            'type' => 'payment',
        ]);
    }

    private function orderForPayment(User $customer, Branch $branch): Order
    {
        return Order::factory()->create([
            'user_id' => $customer->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'total' => 18.50,
        ]);
    }
}
