<?php

namespace Tests\Feature;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Ask;
use App\Enums\Status;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\User;
use App\Services\FrontendOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

class KioskPaymentStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $kioskUser;
    protected User $chefUser;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        config(['app.api_key' => '123456']);

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_schedule_order_slot_duration' => 30,
            'order_setup_delivery' => 5,
            'order_setup_takeaway' => 5,
        ]);

        $this->branch = Branch::forceCreate([
            'name' => 'Kiosk State Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '10 rue borne',
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Burgers',
            'slug' => 'burgers',
            'status' => 5,
        ]);

        $this->item = Item::forceCreate([
            'name' => 'State Burger',
            'slug' => 'state-burger',
            'price' => 12.50,
            'status' => 5,
            'item_category_id' => $category->id,
        ]);

        $this->kioskUser = User::forceCreate([
            'name' => 'Kiosk Machine User',
            'email' => 'kiosk-state@test.local',
            'username' => 'kiosk_state',
            'phone' => '0600000001',
            'password' => bcrypt('password123'),
            'branch_id' => $this->branch->id,
        ]);

        $this->chefUser = User::forceCreate([
            'name' => 'Chef User',
            'email' => 'chef-state@test.local',
            'username' => 'chef_state',
            'phone' => '0600000002',
            'password' => bcrypt('password123'),
            'branch_id' => $this->branch->id,
        ]);
        $this->chefUser->assignRole('Chef');

        KioskMachine::forceCreate([
            'user_id' => $this->kioskUser->id,
            'branch_id' => $this->branch->id,
            'machine_id' => 'machine-state-01',
            'username' => 'kiosk-state',
            'password' => bcrypt('kiosk123'),
            'is_login' => Ask::NO,
            'status' => Status::ACTIVE,
        ]);
    }

    private function kioskPayload(int $paymentMethod): array
    {
        return [
            'branch_id' => $this->branch->id,
            'subtotal' => 12.50,
            'discount' => 0,
            'delivery_charge' => 0,
            'total' => 12.50,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::APP,
            'payment_method' => $paymentMethod,
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ];
    }

    private function kioskPayloadWithQuote(int $paymentMethod): array
    {
        $payload = $this->kioskPayload($paymentMethod);
        $quote = $this
            ->actingAs($this->kioskUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()
            ->json('data');

        return $payload + [
            'quote_token' => $quote['quote_token'],
            'quote_signature' => $quote['signature'],
        ];
    }

    public function test_card_order_stays_pending_until_payment_confirm(): void
    {
        Event::fake([OrderCreated::class, OrderStatusChanged::class]);

        $response = $this
            ->actingAs($this->kioskUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->kioskPayloadWithQuote(PaymentGateway::CARD));

        $this->assertContains($response->status(), [200, 201]);
        $orderId = $response->json('data.id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
            'payment_method' => PaymentGateway::CARD,
        ]);

        Event::assertNotDispatched(OrderCreated::class);
        Event::assertNotDispatched(OrderStatusChanged::class);

        $kdsBefore = $this
            ->actingAs($this->chefUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->getJson('/api/admin/kds-order');

        $kdsBefore->assertOk();
        $beforeRows = $kdsBefore->json('data') ?? $kdsBefore->json() ?? [];
        $this->assertNotContains($orderId, collect($beforeRows)->pluck('id')->all());

        $confirm = $this
            ->actingAs($this->kioskUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson("/api/frontend/order/{$orderId}/payment-confirm", [
                'transaction_id' => 'TXN-STATE-001',
                'card_type' => 'VISA',
                'payment_method' => PaymentGateway::CARD,
            ]);

        $confirm->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'transaction_id' => 'TXN-STATE-001',
        ]);

        Event::assertDispatched(OrderCreated::class);
        Event::assertDispatched(OrderStatusChanged::class);

        $kdsAfter = $this
            ->actingAs($this->chefUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->getJson('/api/admin/kds-order');

        $kdsAfter->assertOk();
        $afterRows = $kdsAfter->json('data') ?? $kdsAfter->json() ?? [];
        $this->assertContains($orderId, collect($afterRows)->pluck('id')->all());
    }

    public function test_cash_order_is_immediately_sent_to_kds_pending_counter_payment(): void
    {
        Event::fake([OrderCreated::class, OrderStatusChanged::class]);

        $response = $this
            ->actingAs($this->kioskUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->kioskPayloadWithQuote(PaymentGateway::CASH_ON_DELIVERY));

        $this->assertContains($response->status(), [200, 201]);
        $orderId = $response->json('data.id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'fiscal_sequence_no' => null,
        ]);

        Event::assertDispatched(OrderCreated::class);
        Event::assertDispatched(OrderStatusChanged::class);

        $kdsAfter = $this
            ->actingAs($this->chefUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->getJson('/api/admin/kds-order');

        $kdsAfter->assertOk();
        $row = collect($kdsAfter->json('data') ?? [])->firstWhere('id', $orderId);

        $this->assertNotNull($row, 'Pending counter kiosk cash order must appear on KDS immediately.');
        $this->assertTrue((bool) ($row['payment_pending_counter'] ?? false));
    }

    public function test_payment_confirm_can_finalize_an_already_paid_but_pending_kiosk_order(): void
    {
        Event::fake([OrderCreated::class, OrderStatusChanged::class]);

        $order = FrontendOrder::forceCreate([
            'order_serial_no' => '310326999',
            'user_id' => $this->kioskUser->id,
            'branch_id' => $this->branch->id,
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::PAID,
            'payment_method' => PaymentGateway::CARD,
            'order_type' => OrderType::TAKEAWAY,
            'source' => Source::APP,
            'subtotal' => 12.50,
            'total' => 12.50,
            'discount' => 0,
            'delivery_charge' => 0,
            'order_datetime' => now(),
            'preparation_time' => 30,
            'queue_number' => 'A0001',
        ]);

        $response = $this
            ->actingAs($this->kioskUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson("/api/frontend/order/{$order->id}/payment-confirm", [
                'transaction_id' => 'TXN-STATE-ALREADY',
                'card_type' => 'VISA',
                'payment_method' => PaymentGateway::CARD,
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
        ]);

        Event::assertDispatched(OrderCreated::class);
        Event::assertDispatched(OrderStatusChanged::class);
    }

    /**
     * [F-21] Defense in depth — finalizePaidKioskOrder must REJECT a non-paid order
     * even if called directly (bypassing the controller pre-check). Caller paths like
     * job retries, future refactors, or any service-to-service call must NOT be able
     * to advance an unpaid kiosk order to ACCEPT.
     *
     * @see app/Services/FrontendOrderService.php finalizePaidKioskOrder
     * @see tasks/gates/GATE_FROZEN_F21_FINALIZE_PAID_KIOSK_2026-04-23.md
     * @see plans/PLAN_POS_KIOSK_KDS_SYNC_REPAIR_v2_2026-04-23.md lot 1.G
     */
    public function test_finalize_paid_kiosk_order_rejects_unpaid_order(): void
    {
        Event::fake([OrderCreated::class, OrderStatusChanged::class]);

        $unpaidOrder = FrontendOrder::forceCreate([
            'order_serial_no' => '310326F21A',
            'user_id' => $this->kioskUser->id,
            'branch_id' => $this->branch->id,
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
            'payment_method' => PaymentGateway::CARD,
            'order_type' => OrderType::TAKEAWAY,
            'source' => Source::APP,
            'subtotal' => 12.50,
            'total' => 12.50,
            'discount' => 0,
            'delivery_charge' => 0,
            'order_datetime' => now(),
            'preparation_time' => 30,
            'queue_number' => 'A0F21',
        ]);

        $service = app(FrontendOrderService::class);
        $promoted = $service->finalizePaidKioskOrder($unpaidOrder);

        $this->assertFalse($promoted, 'Service must return false when payment is not confirmed.');

        $this->assertDatabaseHas('orders', [
            'id' => $unpaidOrder->id,
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
        ]);

        Event::assertNotDispatched(OrderCreated::class);
        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    /**
     * [F-21] Counterpart positive path — calling the service directly on a paid order
     * still succeeds (no regression introduced by the defense-in-depth check).
     */
    public function test_finalize_paid_kiosk_order_promotes_paid_order_when_called_directly(): void
    {
        Event::fake([OrderCreated::class, OrderStatusChanged::class]);

        $paidOrder = FrontendOrder::forceCreate([
            'order_serial_no' => '310326F21B',
            'user_id' => $this->kioskUser->id,
            'branch_id' => $this->branch->id,
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::PAID,
            'payment_method' => PaymentGateway::CARD,
            'order_type' => OrderType::TAKEAWAY,
            'source' => Source::APP,
            'subtotal' => 12.50,
            'total' => 12.50,
            'discount' => 0,
            'delivery_charge' => 0,
            'order_datetime' => now(),
            'preparation_time' => 30,
            'queue_number' => 'A0F21B',
        ]);

        $service = app(FrontendOrderService::class);
        $promoted = $service->finalizePaidKioskOrder($paidOrder);

        $this->assertTrue($promoted, 'Service must promote a paid kiosk order to ACCEPT.');

        $this->assertDatabaseHas('orders', [
            'id' => $paidOrder->id,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
        ]);

        Event::assertDispatched(OrderCreated::class);
        Event::assertDispatched(OrderStatusChanged::class);
    }
}
