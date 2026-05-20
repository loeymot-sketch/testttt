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

    /**
     * [P-K11-FZH / KR1] M-08 Option B SUPERSEDED — kiosk direct TPE orders
     * now auto-allocate fiscal_sequence_no on payment-confirm finalization
     * so they enter Z aggregation immediately. Without this auto-allocation
     * a kiosk-paid card order could remain unsealed indefinitely (NF525 gap).
     *
     * Two test paths:
     *  - Default (flag true) : fiscal_sequence_no allocated, order in Z
     *  - Legacy fallback (flag false) : fiscal_sequence_no null, order skipped
     */
    public function test_kiosk_payment_confirm_auto_allocates_fiscal_sequence_under_p_k11(): void
    {
        Event::fake([OrderCreated::class, OrderStatusChanged::class]);
        config(['fiscal.kiosk_auto_allocate_sequence' => true]);

        [$branch, $kioskOrder] = $this->seedKioskScenario('K11-AUTO-001', 'kiosk-k11-auto');

        $promoted = app(FrontendOrderService::class)->finalizePaidKioskOrder($kioskOrder);
        $this->assertTrue($promoted);

        // [K11] fiscal_sequence_no must be allocated (NOT null) under default flag
        $persisted = Order::withoutGlobalScopes()->findOrFail($kioskOrder->id);
        // [Wave S-1 — 2026-05-20] Owner P-OWNER Wave S-1: kiosk paid TPE
        // auto-prepares to PREPARING after finalize. Z-aggregation still
        // gates on payment_status=PAID + fiscal_sequence_no, both invariants
        // preserved here.
        $this->assertSame(OrderStatus::PREPARING, (int) $persisted->status);
        $this->assertSame(PaymentStatus::PAID, (int) $persisted->payment_status);
        $this->assertNotNull($persisted->fiscal_sequence_no, 'KR1: kiosk direct TPE must auto-allocate fiscal_sequence_no');
        $this->assertGreaterThan(0, (int) $persisted->fiscal_sequence_no);

        // Z aggregate now includes the kiosk order (delta vs M-08 Option B)
        $aggregate = app(ZReportService::class)->aggregate($branch->id, now()->subHour(), now()->addHour());
        $this->assertSame(1, (int) $aggregate['order_count']);
        $this->assertEqualsWithDelta(25.00, (float) $aggregate['total_ttc'], 0.01);
    }

    /**
     * Legacy M-08 Option B preserved when feature flag explicitly disabled
     * (emergency rollback path).
     */
    public function test_kiosk_payment_confirm_does_not_fiscalize_when_flag_disabled(): void
    {
        Event::fake([OrderCreated::class, OrderStatusChanged::class]);
        config(['fiscal.kiosk_auto_allocate_sequence' => false]);

        [$branch, $kioskOrder] = $this->seedKioskScenario('K11-OFF-001', 'kiosk-k11-off');

        $promoted = app(FrontendOrderService::class)->finalizePaidKioskOrder($kioskOrder);
        $this->assertTrue($promoted);

        $this->assertDatabaseHas('orders', [
            'id'                 => $kioskOrder->id,
            // [Wave S-1 — 2026-05-20] Auto-prepare still applies even when
            // the fiscal flag is off — the two policies are independent
            // (fiscal allocation gated by `fiscal.kiosk_auto_allocate_sequence`
            // vs status promotion gated by `pos.auto_prepare_on_paid`).
            'status'             => OrderStatus::PREPARING,
            'payment_status'     => PaymentStatus::PAID,
            'fiscal_sequence_no' => null,
        ]);

        // Aggregate excludes the kiosk order (legacy gap preserved when flag off)
        Order::factory()->create([
            'branch_id'          => $branch->id,
            'status'             => OrderStatus::DELIVERED,
            'payment_status'     => PaymentStatus::PAID,
            'total'              => 12.00,
            'total_tax'          => 0,
            'fiscal_sequence_no' => 1,
            'created_at'         => now(),
        ]);

        $aggregate = app(ZReportService::class)->aggregate($branch->id, now()->subHour(), now()->addHour());
        $this->assertSame(1, (int) $aggregate['order_count']);
        $this->assertEqualsWithDelta(12.00, (float) $aggregate['total_ttc'], 0.01);
    }

    private function seedKioskScenario(string $serial, string $machine): array
    {
        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::forceCreate([
            'user_id'    => $kioskUser->id,
            'branch_id'  => $branch->id,
            'machine_id' => $machine . '-001',
            'username'   => $machine,
            'password'   => bcrypt('secret'),
            'is_login'   => Ask::NO,
            'status'     => Status::ACTIVE,
        ]);

        $kioskOrder = FrontendOrder::forceCreate([
            'order_serial_no'  => $serial,
            'user_id'          => $kioskUser->id,
            'branch_id'        => $branch->id,
            'status'           => OrderStatus::PENDING,
            'payment_status'   => PaymentStatus::PAID,
            'payment_method'   => PaymentGateway::CARD,
            'order_type'       => OrderType::KIOSK,
            'source'           => Source::APP,
            'source_surface'   => 'kiosk',
            'subtotal'         => 25.00,
            'total'            => 25.00,
            'total_tax'        => 0,
            'discount'         => 0,
            'delivery_charge'  => 0,
            'order_datetime'   => now(),
            'preparation_time' => 30,
            'queue_number'     => 'A0801',
        ]);

        return [$branch, $kioskOrder];
    }
}
