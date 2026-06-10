<?php

namespace Tests\Feature\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @FK-ID CDASH-02 + RED-DASH-01 (P1/P2, ultra-audit 2026-06-10) | @plan GOAL_ULTRA_AUDIT_SYSTEMES LOT B
 *
 * EOD `by_payment` mis-bucketing: counter-encashed orders whose order_type is
 * NOT POS (takeaway counter-collect, kiosk Plan B) carry their real tender in
 * `pos_payment_method` (written by PaymentService::confirmCounterPayment:338),
 * but resolvePaymentBucketKey only honoured it when order_type===POS — every
 * non-POS-typed counter order fell back to payment_method (=1 placeholder
 * CASH_ON_DELIVERY) and was counted as Espèces. Live evidence: 13 card +
 * 12 TR takeaway orders bucketed as cash on 2026-06-08.
 */
class EodPaymentBucketTenderTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->branch = Branch::factory()->create();
    }

    private function makePaidCounterOrder(int $orderType, int $posMethod, float $total): Order
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);

        return Order::factory()->create([
            'user_id'            => $user->id,
            'branch_id'          => $this->branch->id,
            'order_type'         => $orderType,
            'source'             => Source::POS,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'pos_payment_method' => $posMethod,
            'payment_method'     => 1, // CASH_ON_DELIVERY placeholder (the trap)
            'total'              => $total,
            'order_datetime'     => now(),
        ]);
    }

    private function buckets(): array
    {
        $synthesis = app(DashboardService::class)->eodSynthesis(now()->toDateString());
        $out = [];
        foreach ($synthesis['by_payment'] as $bucket) {
            $out[$bucket['label']] = $bucket;
        }
        return $out;
    }

    public function test_takeaway_counter_order_paid_by_ticket_restaurant_is_not_counted_as_cash(): void
    {
        $this->makePaidCounterOrder(OrderType::TAKEAWAY, PosPaymentMethod::TICKET_RESTAURANT, 12.50);

        $buckets = $this->buckets();

        $this->assertArrayHasKey('Titre-restaurant', $buckets, 'TR tender must land in the TR bucket.');
        $this->assertSame(1, $buckets['Titre-restaurant']['count']);
        $this->assertArrayNotHasKey('Espèces', $buckets, 'No cash bucket should exist for a TR-only day.');
    }

    public function test_takeaway_counter_order_paid_by_card_is_not_counted_as_cash(): void
    {
        $this->makePaidCounterOrder(OrderType::TAKEAWAY, PosPaymentMethod::CARD, 20.00);

        $buckets = $this->buckets();

        $this->assertArrayHasKey('Carte bancaire', $buckets);
        $this->assertSame(1, $buckets['Carte bancaire']['count']);
        $this->assertArrayNotHasKey('Espèces', $buckets);
    }

    public function test_pos_typed_cash_order_still_counts_as_cash(): void
    {
        $this->makePaidCounterOrder(OrderType::POS, PosPaymentMethod::CASH, 9.00);

        $buckets = $this->buckets();

        $this->assertArrayHasKey('Espèces', $buckets);
        $this->assertSame(1, $buckets['Espèces']['count']);
    }
}
