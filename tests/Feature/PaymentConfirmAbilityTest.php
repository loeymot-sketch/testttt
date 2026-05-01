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
use Tests\TestCase;

class PaymentConfirmAbilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_kiosk_token_without_kiosk_order_ability_cannot_confirm_payment(): void
    {
        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
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

        $token = $kioskUser->createToken('kiosk-without-order-ability', ['kiosk:read'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/frontend/order/'.$order->id.'/payment-confirm', [
            'transaction_id' => 'FK-M06-NO-ABILITY',
            'card_type' => 'visa',
            'payment_method' => PaymentGateway::CARD,
        ]);

        $response->assertStatus(403);
        $this->assertSame(PaymentStatus::UNPAID, (int) Order::withoutGlobalScopes()->findOrFail($order->id)->payment_status);
    }
}
