<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class StripeActivationGuardTest extends TestCase
{
    use RefreshDatabase;

    private bool $createdInstalledFlag = false;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.web_payment_v1.enabled', true);
        Config::set('payment.pilot_restrict.enabled', false);
        Config::set('payment.stripe.activation_guard.enabled', true);
        Config::set('payment.stripe.activation_guard.activation_gate_cleared', false);

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->ensureInstalledFlag();
    }

    protected function tearDown(): void
    {
        if ($this->createdInstalledFlag && file_exists(storage_path('installed'))) {
            unlink(storage_path('installed'));
        }

        parent::tearDown();
    }

    public function test_stripe_public_payment_activation_is_blocked_without_gate_clearance(): void
    {
        $order = $this->order();

        $this->post(route('payment.store', ['order' => $order]), ['paymentMethod' => 'stripe'])->assertNotFound();

        $this->assertDatabaseMissing('transactions', [
            'order_id' => $order->id,
            'payment_method' => 'stripe',
        ]);
        $this->assertSame(PaymentStatus::UNPAID, (int) $order->refresh()->payment_status);
        $this->assertFalse(config('payment.stripe.activation_guard.activation_gate_cleared'));
        $this->assertSame('GATE_STRIPE_CENTS_ACTIVE_2026-04-25', config('payment.stripe.activation_guard.gate'));
    }

    private function order(): Order
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        return Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'order_type' => OrderType::POS,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::UNPAID,
            'total' => 42.50,
        ]);
    }

    private function ensureInstalledFlag(): void
    {
        if (file_exists(storage_path('installed'))) {
            return;
        }

        touch(storage_path('installed'));
        $this->createdInstalledFlag = true;
    }
}
