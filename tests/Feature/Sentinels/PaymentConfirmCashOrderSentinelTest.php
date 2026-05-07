<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * @FK-ID FK-029/FK-058 | @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md | @fix-mission CV1-M06-POS-REVENUE-GUARDS | @reason payment-confirm trusts the posted payment_method and can convert a cash kiosk order into a paid card order.
 */
class PaymentConfirmCashOrderSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_cash_kiosk_order_cannot_be_confirmed_as_card_payment(): void
    {
        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);

        KioskMachine::factory()->create([
            'user_id' => $kioskUser->id,
            'branch_id' => $branch->id,
        ]);

        $order = Order::factory()->create([
            'user_id' => $kioskUser->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'source_surface' => 'kiosk',
        ]);

        Sanctum::actingAs($kioskUser, ['kiosk:order']);

        $response = $this->postJson('/api/frontend/order/' . $order->id . '/payment-confirm', [
            'transaction_id' => 'FK-SENTINEL-CASH-AS-CARD',
            'card_type' => 'visa',
            'payment_method' => PaymentGateway::CARD,
            'amount_cents' => 5000,
        ]);

        $response->assertStatus(422);
        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertSame(PaymentStatus::UNPAID, (int) $fresh->payment_status);
        $this->assertSame(PaymentGateway::CASH_ON_DELIVERY, (int) $fresh->payment_method);
    }
}
