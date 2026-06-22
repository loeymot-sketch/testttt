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
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentConfirmCrossBranchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_kiosk_machine_cannot_confirm_order_from_another_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => 0]);
        KioskMachine::factory()->create(['user_id' => $kioskUser->id, 'branch_id' => $branchA->id]);

        $foreignOrder = Order::factory()->create([
            'user_id' => $kioskUser->id,
            'branch_id' => $branchB->id,
            'order_type' => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CARD,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'source_surface' => 'kiosk',
        ]);

        $token = $kioskUser->createToken('kiosk', ['kiosk:order'])->plainTextToken;

        // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
        $this->withToken($token)
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order/'.$foreignOrder->id.'/payment-confirm', [
                'transaction_id' => 'FK-M06-CROSS-BRANCH',
                'card_type' => 'visa',
                'payment_method' => PaymentGateway::CARD,
                'amount_cents' => (int) round($foreignOrder->fresh()->total * 100),
            ])->assertStatus(403);

        $this->assertSame(PaymentStatus::UNPAID, (int) Order::withoutGlobalScopes()->findOrFail($foreignOrder->id)->payment_status);
    }

    public function test_cash_kiosk_order_cannot_be_confirmed_as_card_payment(): void
    {
        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create(['user_id' => $kioskUser->id, 'branch_id' => $branch->id]);

        $order = Order::factory()->create([
            'user_id' => $kioskUser->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'source_surface' => 'kiosk',
        ]);

        $token = $kioskUser->createToken('kiosk', ['kiosk:order'])->plainTextToken;

        $this->withToken($token)
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order/'.$order->id.'/payment-confirm', [
                'transaction_id' => 'FK-M06-CASH-AS-CARD',
                'card_type' => 'visa',
                'payment_method' => PaymentGateway::CARD,
                'amount_cents' => (int) round($order->fresh()->total * 100),
            ])->assertStatus(422);

        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertSame(PaymentStatus::UNPAID, (int) $fresh->payment_status);
        $this->assertSame(PaymentGateway::CASH_ON_DELIVERY, (int) $fresh->payment_method);
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

        $token = $kioskUser->createToken('kiosk', ['kiosk:order'])->plainTextToken;
        $payload = [
            'transaction_id' => 'FK-M06-DUPLICATE-TPE',
            'card_type' => 'visa',
            'payment_method' => PaymentGateway::CARD,
            'amount_cents' => 5000, // [AUDIT-F-002] matches order.total=50.00
        ];

        // [prod-finale 2026-06-17] DISTINCT idempotency keys per POST: a shared key would return a cached 2xx
        // replay from the FROZEN middleware and MASK the duplicate-TPE-reference 409 this test asserts. Distinct
        // keys let both POSTs reach the controller, where the SECOND hits the transaction-reuse guard → 409.
        $this->withToken($token)
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order/'.$firstOrder->id.'/payment-confirm', $payload)->assertOk();
        $this->withToken($token)
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order/'.$secondOrder->id.'/payment-confirm', $payload)->assertStatus(409);

        $this->assertSame(PaymentStatus::UNPAID, (int) Order::withoutGlobalScopes()->findOrFail($secondOrder->id)->payment_status);
    }

    public function test_payment_confirm_rejects_unpaid_non_pending_order(): void
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
            'status' => OrderStatus::ACCEPT,
            'source_surface' => 'kiosk',
        ]);

        $token = $kioskUser->createToken('kiosk', ['kiosk:order'])->plainTextToken;

        $this->withToken($token)
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order/'.$order->id.'/payment-confirm', [
                'transaction_id' => 'FK-M06-NON-PENDING',
                'card_type' => 'visa',
                'payment_method' => PaymentGateway::CARD,
                'amount_cents' => (int) round($order->fresh()->total * 100),
            ])->assertStatus(422);

        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertSame(PaymentStatus::UNPAID, (int) $fresh->payment_status);
        $this->assertNull($fresh->transaction_id);
    }

    public function test_already_paid_order_requires_same_tpe_transaction_for_idempotence(): void
    {
        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create(['user_id' => $kioskUser->id, 'branch_id' => $branch->id]);

        $order = Order::factory()->create([
            'user_id' => $kioskUser->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CARD,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::ACCEPT,
            'source_surface' => 'kiosk',
        ]);
        Order::withoutGlobalScopes()->whereKey($order->id)->update(['transaction_id' => 'FK-M06-PAID-ORIGINAL']);

        $token = $kioskUser->createToken('kiosk', ['kiosk:order'])->plainTextToken;

        // [prod-finale 2026-06-17] DISTINCT idempotency keys per POST: the test re-confirms the SAME order with
        // a DIFFERENT transaction reference and asserts a NON-replay 409. A shared key would return the cached
        // 2xx replay from the FROZEN middleware and MASK the already-paid/different-TPE 409 under test.
        $this->withToken($token)
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order/'.$order->id.'/payment-confirm', [
                'transaction_id' => 'FK-M06-PAID-ORIGINAL',
                'card_type' => 'visa',
                'payment_method' => PaymentGateway::CARD,
                'amount_cents' => (int) round($order->fresh()->total * 100),
            ])->assertOk();

        $this->withToken($token)
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order/'.$order->id.'/payment-confirm', [
                'transaction_id' => 'FK-M06-PAID-DIFFERENT',
                'card_type' => 'visa',
                'payment_method' => PaymentGateway::CARD,
                'amount_cents' => (int) round($order->fresh()->total * 100),
            ])->assertStatus(409);
    }
}
