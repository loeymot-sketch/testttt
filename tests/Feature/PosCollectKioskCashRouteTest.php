<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Events\OrderPaidAtCounter;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosCollectKioskCashRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_pos_collect_kiosk_cash_route_accepts_pending_cash_order(): void
    {
        Event::fake([OrderPaidAtCounter::class]);

        $this->assertTrue(Route::has('admin.pos.collect-kiosk-cash'));

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');

        $order = Order::factory()->create([
            'user_id' => $operator->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'status' => OrderStatus::ACCEPT,
            'source_surface' => 'kiosk',
            'total' => 12.00,
            'fiscal_sequence_no' => null,
        ]);

        // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
        // DISTINCT keys per POST: the second collect must REACH the controller so the assertion below (event dispatched
        // exactly once + fiscal_sequence_no=1) proves the CONTROLLER's own no-op-on-already-PAID guard. A shared key would
        // short-circuit the second POST as a cached 2xx replay in the middleware and never exercise that guard.
        $this->actingAs($operator, 'sanctum')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/collect-kiosk-cash/'.$order->id)
            ->assertSuccessful();

        $this->actingAs($operator, 'sanctum')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/collect-kiosk-cash/'.$order->id)
            ->assertSuccessful();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::ACCEPT,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'fiscal_sequence_no' => 1,
        ]);

        Event::assertDispatchedTimes(OrderPaidAtCounter::class, 1);
    }
}
