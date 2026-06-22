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
            'total' => 50.00,
            'subtotal' => 50.00,
        ]);
        $secondOrder = Order::factory()->create([
            'user_id' => $kioskUser->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CARD,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'source_surface' => 'kiosk',
            'total' => 50.00,
            'subtotal' => 50.00,
        ]);

        Sanctum::actingAs($kioskUser, ['kiosk:order']);
        $payload = [
            'transaction_id' => 'FK-SENTINEL-DUPLICATE-TPE',
            'card_type' => 'visa',
            'payment_method' => PaymentGateway::CARD,
            'amount_cents' => 5000, // [AUDIT-F-002] matches firstOrder/secondOrder total=50.00
        ];

        $this->withHeaders(['X-Idempotency-Key' => 'sentinel-pcc-1-' . uniqid()])
            ->postJson('/api/frontend/order/' . $firstOrder->id . '/payment-confirm', $payload)->assertOk();
        $secondResponse = $this->withHeaders(['X-Idempotency-Key' => 'sentinel-pcc-2-' . uniqid()])
            ->postJson('/api/frontend/order/' . $secondOrder->id . '/payment-confirm', $payload);

        // [Wave S-1 — 2026-05-20] Owner P-OWNER Wave S-1: finalizePaidKioskOrder
        // now dispatches the OrderStatusChanged transition in two legs —
        // PENDING → ACCEPT (canonical "order accepted" broadcast) and
        // ACCEPT → PREPARING (the auto-prepare-on-paid hook). The second
        // duplicate TPE call must STILL be rejected so the total stays at
        // 2 events for one successful payment, not 4 for two successful
        // payments. The original intent of this sentinel (one TPE
        // transaction_id pays one and only one order) is preserved by the
        // `expected=2 actual=2` invariant below — any future drift that
        // re-introduces a duplicate-allowing race would push this to 4.
        Event::assertDispatchedTimes(OrderStatusChanged::class, 2);
        $this->assertContains($secondResponse->status(), [409, 422], 'The same TPE transaction_id must not pay two orders.');
        $this->assertSame(
            PaymentStatus::UNPAID,
            (int) Order::withoutGlobalScopes()->findOrFail($secondOrder->id)->payment_status
        );
    }
}
