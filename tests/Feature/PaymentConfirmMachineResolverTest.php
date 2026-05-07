<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PaymentConfirmMachineResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_payment_confirm_uses_kiosk_machine_branch_when_user_branch_is_global(): void
    {
        Event::fake();

        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => 0]);
        KioskMachine::factory()->create(['user_id' => $kioskUser->id, 'branch_id' => $branch->id]);

        $order = Order::factory()->create([
            'user_id' => $kioskUser->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CARD,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'source_surface' => 'kiosk',
        ]);

        $token = $kioskUser->createToken('kiosk', ['kiosk:order'])->plainTextToken;

        $this->withToken($token)->postJson('/api/frontend/order/'.$order->id.'/payment-confirm', [
            'transaction_id' => 'FK-M06-MACHINE-BRANCH',
            'card_type' => 'visa',
            'payment_method' => PaymentGateway::CARD,
            'amount_cents' => (int) round($order->fresh()->total * 100),
        ])->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'branch_id' => $branch->id,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::ACCEPT,
            'transaction_id' => 'FK-M06-MACHINE-BRANCH',
        ]);
    }
}
