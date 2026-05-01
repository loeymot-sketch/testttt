<?php

namespace Tests\Feature\Fiscal;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Enums\Status;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\User;
use App\Services\Fiscal\ZReportService;
use App\Services\FrontendOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ZAggregationKioskRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_payment_confirm_does_not_directly_fiscalize_z_under_option_b(): void
    {
        Event::fake([OrderCreated::class, OrderStatusChanged::class]);

        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::forceCreate([
            'user_id' => $kioskUser->id,
            'branch_id' => $branch->id,
            'machine_id' => 'kiosk-m08-001',
            'username' => 'kiosk-m08',
            'password' => bcrypt('secret'),
            'is_login' => Ask::NO,
            'status' => Status::ACTIVE,
        ]);

        $kioskOrder = FrontendOrder::forceCreate([
            'order_serial_no' => 'M08-KIOSK-001',
            'user_id' => $kioskUser->id,
            'branch_id' => $branch->id,
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::PAID,
            'payment_method' => PaymentGateway::CARD,
            'order_type' => OrderType::KIOSK,
            'source' => Source::APP,
            'source_surface' => 'kiosk',
            'subtotal' => 25.00,
            'total' => 25.00,
            'total_tax' => 0,
            'discount' => 0,
            'delivery_charge' => 0,
            'order_datetime' => now(),
            'preparation_time' => 30,
            'queue_number' => 'A0801',
        ]);

        $promoted = app(FrontendOrderService::class)->finalizePaidKioskOrder($kioskOrder);

        $this->assertTrue($promoted);
        $this->assertDatabaseHas('orders', [
            'id' => $kioskOrder->id,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'fiscal_sequence_no' => null,
        ]);

        Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'total' => 12.00,
            'total_tax' => 0,
            'fiscal_sequence_no' => 1,
            'created_at' => now(),
        ]);

        $aggregate = app(ZReportService::class)->aggregate($branch->id, now()->subHour(), now()->addHour());

        $this->assertSame(1, (int) $aggregate['order_count']);
        $this->assertEqualsWithDelta(12.00, (float) $aggregate['total_ttc'], 0.01);
    }
}
