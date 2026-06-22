<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundPreZTest extends TestCase
{
    use RefreshDatabase;

    public function test_returned_order_before_z_close_is_not_counted_as_positive_revenue(): void
    {
        $branch = Branch::factory()->create();

        Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => OrderStatus::RETURNED,
            'payment_status' => PaymentStatus::PAID,
            'total' => 40.00,
            'subtotal' => 40.00,
            'total_tax' => 0,
            'fiscal_sequence_no' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $aggregate = app(ZReportService::class)->aggregate($branch->id, now()->subHour(), now()->addHour());

        $this->assertSame(0, (int) $aggregate['order_count']);
        $this->assertSame(1, (int) $aggregate['refund_count']);
        $this->assertEqualsWithDelta(0.00, (float) $aggregate['total_ttc'], 0.01);
    }
}
