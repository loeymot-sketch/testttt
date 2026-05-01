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

class WebPaymentDisabledTest extends TestCase
{
    use RefreshDatabase;

    private bool $createdInstalledFlag = false;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.web_payment_v1.enabled', false);
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

    public function test_public_web_payment_entrypoint_is_disabled_for_v1(): void
    {
        $order = $this->order();

        $this->get(route('payment.index', ['order' => $order]))->assertNotFound();
        $this->post(route('payment.store', ['order' => $order]), ['paymentMethod' => 'credit'])->assertNotFound();

        $this->assertDatabaseMissing('transactions', [
            'order_id' => $order->id,
        ]);
    }

    public function test_public_web_payment_callbacks_are_disabled_for_v1(): void
    {
        $order = $this->order();

        $this->get(route('payment.successful', ['order' => $order]))->assertNotFound();
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
