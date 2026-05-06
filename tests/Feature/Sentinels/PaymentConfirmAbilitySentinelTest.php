<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @FK-ID FK-009/FK-029 | @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md | @fix-mission CV1-M06-POS-REVENUE-GUARDS | @reason payment-confirm checks user_id only and does not require the kiosk:order Sanctum ability.
 */
class PaymentConfirmAbilitySentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_non_kiosk_staff_token_cannot_confirm_kiosk_card_payment(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->assignRole('POS Operator');

        $order = Order::factory()->create([
            'user_id' => $cashier->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CARD,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'source_surface' => 'kiosk',
        ]);

        $response = $this->actingAs($cashier, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'sentinel-pca-' . uniqid()])
            ->postJson('/api/frontend/order/' . $order->id . '/payment-confirm', [
                'transaction_id' => 'FK-SENTINEL-NON-KIOSK',
                'card_type' => 'visa',
                'payment_method' => PaymentGateway::CARD,
            ]);

        $response->assertStatus(403);
        $this->assertNotSame(
            PaymentStatus::PAID,
            (int) Order::withoutGlobalScopes()->findOrFail($order->id)->payment_status,
            'A non-kiosk token must not mutate payment_status to PAID.'
        );
    }
}
