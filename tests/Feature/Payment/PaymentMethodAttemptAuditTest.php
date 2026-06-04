<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentMethodAttemptAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('payment.pilot_restrict.enabled', true);
        Config::set('payment.pilot_restrict.allowed_methods', ['credit']);
        Config::set('payment.pilot_restrict.audit_action', 'payment.method_restricted');
        Config::set('fiscal.audit_secret', 'unit-test-audit-secret');
    }

    public function test_blocked_payment_method_attempt_is_audited(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['branch_id' => $branch->id]);
        $this->actingAs($actor, 'sanctum');
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $actor->id,
            'order_type' => OrderType::POS,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::UNPAID,
            'total' => 15.00,
        ]);

        try {
            // [P0-POS-02 GOAL round-2] gateway-context opt-in: this test
            // exercises the pilot-restrict block, so we let the call reach
            // the pilot check (the gateway-context guard runs first).
            app()->instance('payment.service.allow_direct_call', true);
            app(PaymentService::class)->payment($order, 'stripe', 'tx-blocked-audit');
            $this->fail('Blocked payment method should throw a validation exception.');
        } catch (ValidationException) {
        }

        $this->assertDatabaseHas('audit_logs', [
            'branch_id' => $branch->id,
            'user_id' => $actor->id,
            'action' => 'payment.method_restricted',
            'resource' => 'order',
            'resource_id' => $order->id,
        ]);
        $this->assertDatabaseMissing('transactions', [
            'order_id' => $order->id,
            'payment_method' => 'stripe',
        ]);
        $this->assertSame(PaymentStatus::UNPAID, (int) $order->refresh()->payment_status);
    }
}
