<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RefundPostZTest extends TestCase
{
    use RefreshDatabase;

    public function test_returned_order_after_previous_z_is_counted_as_negative_adjustment(): void
    {
        $branch = Branch::factory()->create();
        $from = Carbon::parse('2026-04-25 08:00:00');
        $to = Carbon::parse('2026-04-25 20:00:00');

        Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => OrderStatus::RETURNED,
            'payment_status' => PaymentStatus::PAID,
            'total' => 50.00,
            'subtotal' => 50.00,
            'total_tax' => 0,
            'fiscal_sequence_no' => 1,
            'created_at' => $from->copy()->subDay(),
            'updated_at' => $from->copy()->addHour(),
        ]);

        Order::factory()->create([
            'branch_id' => Branch::factory()->create()->id,
            'status' => OrderStatus::RETURNED,
            'payment_status' => PaymentStatus::PAID,
            'total' => 900.00,
            'subtotal' => 900.00,
            'total_tax' => 0,
            'fiscal_sequence_no' => 1,
            'created_at' => $from->copy()->subDay(),
            'updated_at' => $from->copy()->addHour(),
        ]);

        $aggregate = app(ZReportService::class)->aggregate($branch->id, $from, $to);

        $this->assertSame(0, (int) $aggregate['order_count']);
        $this->assertSame(1, (int) $aggregate['refund_count']);
        $this->assertEqualsWithDelta(-50.00, (float) $aggregate['total_ttc'], 0.01);
    }
}
