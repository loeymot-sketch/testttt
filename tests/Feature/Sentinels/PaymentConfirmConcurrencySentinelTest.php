<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Events\OrderStatusChanged;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * @FK-ID FK-028/FK-029 | @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md | @fix-mission CV1-M04A-PAYMENT-LEDGER-FULL | @reason duplicate TPE callbacks must be idempotent and emit one status transition only.
 */
class PaymentConfirmConcurrencySentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_duplicate_tpe_transaction_reference_cannot_pay_two_orders(): void
    {
        Event::fake();

        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create(['user_id' => $kioskUser->id, 'branch_id' => $branch->id]);

        $firstOrder = Order::factory()->create([
            'user_id' => $kioskUser->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CARD,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'source_surface' => 'kiosk',
        ]);
        $secondOrder = Order::factory()->create([
            'user_id' => $kioskUser->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CARD,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'source_surface' => 'kiosk',
        ]);

        Sanctum::actingAs($kioskUser, ['kiosk:order']);
        $payload = [
            'transaction_id' => 'FK-SENTINEL-DUPLICATE-TPE',
            'card_type' => 'visa',
            'payment_method' => PaymentGateway::CARD,
        ];

        $this->withHeaders(['X-Idempotency-Key' => 'sentinel-pcc-1-' . uniqid()])
            ->postJson('/api/frontend/order/' . $firstOrder->id . '/payment-confirm', $payload)->assertOk();
        $secondResponse = $this->withHeaders(['X-Idempotency-Key' => 'sentinel-pcc-2-' . uniqid()])
            ->postJson('/api/frontend/order/' . $secondOrder->id . '/payment-confirm', $payload);

        Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
        $this->assertContains($secondResponse->status(), [409, 422], 'The same TPE transaction_id must not pay two orders.');
        $this->assertSame(
            PaymentStatus::UNPAID,
            (int) Order::withoutGlobalScopes()->findOrFail($secondOrder->id)->payment_status
        );
    }
}
