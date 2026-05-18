<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentMethodRestrictedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('payment.pilot_restrict.enabled', true);
        Config::set('payment.pilot_restrict.allowed_methods', ['credit']);
        Config::set('fiscal.audit_secret', 'unit-test-audit-secret');
    }

    public function test_unsupported_payment_method_is_rejected_server_side(): void
    {
        $order = $this->order();

        $this->expectException(ValidationException::class);

        // [P0-POS-02 GOAL round-2] payment() requires a PaymentAbstract
        // calling frame OR the opt-in flag. We test the pilot-restrict
        // rejection here (not the gateway-context guard), so the flag is
        // set to let the call reach the pilot check.
        app()->instance('payment.service.allow_direct_call', true);
        app(PaymentService::class)->payment($order, 'stripe', 'tx-blocked-1');
    }

    public function test_allowed_pilot_method_can_capture_payment(): void
    {
        $order = $this->order();

        // [P0-POS-02 GOAL round-2] gateway-context opt-in flag.
        app()->instance('payment.service.allow_direct_call', true);
        $transaction = app(PaymentService::class)->payment($order, 'credit', 'tx-credit-1');

        $this->assertInstanceOf(Transaction::class, $transaction);
        $this->assertSame(PaymentStatus::PAID, (int) $order->refresh()->payment_status);
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'payment_method' => 'credit',
            'transaction_no' => 'tx-credit-1',
            'type' => 'payment',
            'sign' => '+',
        ]);
    }

    public function test_env_cannot_silently_enable_non_code_allowlisted_method(): void
    {
        putenv('PAYMENT_LEDGER_PILOT_METHODS=stripe');
        $_ENV['PAYMENT_LEDGER_PILOT_METHODS'] = 'stripe';
        $_SERVER['PAYMENT_LEDGER_PILOT_METHODS'] = 'stripe';

        $this->assertFalse(app(PaymentService::class)->isPilotPaymentMethodAllowed('stripe'));
    }

    private function order(): Order
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $this->actingAs($user, 'sanctum');

        return Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'order_type' => OrderType::POS,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::UNPAID,
            'total' => 42.50,
        ]);
    }
}
