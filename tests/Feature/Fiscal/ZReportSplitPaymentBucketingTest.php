<?php

namespace Tests\Feature\Fiscal;

use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [GOAL-100-CLOUD / M6-002 — LOCK_ZREPORT_SPLIT_BUCKETING 2026-06-05]
 *
 * M6-002 defect: ZReportService::applyOrderToTotals buckets a split-payment
 * order's FULL total under order->pos_payment_method (the dominant tender),
 * ignoring the per-tranche order_payments. A 30€ cash + 20€ card order books
 * 50€ under cash in the signed Z total_by_method — the per-method fiscal
 * breakdown is wrong.
 *
 * Fix (forward-only, LOCK-gated): when order_payments exist, bucket each
 * tranche's NET amount (amount - change_amount) under its own mode, with the
 * last tranche absorbing the rounding residual so Σ total_by_method == total_ttc.
 * Legacy single-tender orders (no order_payments) keep the pos_payment_method
 * path byte-identical → historical Z verifyChain unaffected.
 */
class ZReportSplitPaymentBucketingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('fiscal.audit_secret',    'unit-test-secret-m6-002-split-x');
        Config::set('fiscal.z_report_secret', 'unit-test-secret-m6-002-split-z');
        Config::set('pricing.tax_inclusive_prices', true);
    }

    private function insertOrderItem(int $orderId, int $branchId, float $totalPrice): void
    {
        $itemId = Item::query()->value('id') ?? Item::factory()->create()->id;
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'branch_id' => $branchId, 'item_id' => $itemId,
            'quantity' => 1, 'discount' => 0, 'price' => $totalPrice,
            'tax_rate' => '10.00', 'tax_amount' => round($totalPrice - $totalPrice / 1.10, 2),
            'total_price' => $totalPrice, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertTranche(int $orderId, int $branchId, int $mode, float $amount, float $change = 0.0): void
    {
        DB::table('order_payments')->insert([
            'order_id' => $orderId, 'branch_id' => $branchId, 'mode' => $mode,
            'amount' => $amount, 'change_amount' => $change,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** M6-002 core: split 30 cash + 20 card → buckets 30/20, NOT 50 under the dominant method. */
    public function test_split_payment_is_bucketed_per_tranche_not_under_dominant_method(): void
    {
        $branch = Branch::factory()->create();
        $order = Order::factory()->create([
            'branch_id' => $branch->id, 'payment_status' => PaymentStatus::PAID,
            'subtotal' => 50.00, 'discount' => 0, 'total' => 50.00,
            'total_tax' => round(50 - 50 / 1.10, 2), 'pos_payment_method' => 1, 'fiscal_sequence_no' => 1,
        ]);
        $this->insertOrderItem($order->id, $branch->id, 50.00);
        $this->insertTranche($order->id, $branch->id, 1, 30.00); // cash
        $this->insertTranche($order->id, $branch->id, 2, 20.00); // card

        $totals = app(ZReportService::class)->aggregate($branch->id, null, now()->addMinute());
        $byMethod = $totals['total_by_method'];

        $this->assertEqualsWithDelta(30.00, (float) ($byMethod['1'] ?? 0), 0.01, 'cash bucket = the cash tranche (30), not the full 50');
        $this->assertEqualsWithDelta(20.00, (float) ($byMethod['2'] ?? 0), 0.01, 'card bucket = the card tranche (20)');
        $this->assertEqualsWithDelta(50.00, array_sum(array_map('floatval', $byMethod)), 0.01, 'Σ total_by_method == total_ttc (NF525 identity)');
        $this->assertEqualsWithDelta(50.00, (float) $totals['total_ttc'], 0.01);
    }

    /** Must-keep: legacy single-tender (no order_payments) stays byte-identical → historical Z safe. */
    public function test_legacy_single_tender_without_tranches_is_unchanged(): void
    {
        $branch = Branch::factory()->create();
        $order = Order::factory()->create([
            'branch_id' => $branch->id, 'payment_status' => PaymentStatus::PAID,
            'subtotal' => 12.00, 'discount' => 0, 'total' => 12.00,
            'total_tax' => round(12 - 12 / 1.10, 2), 'pos_payment_method' => 1, 'fiscal_sequence_no' => 1,
        ]);
        $this->insertOrderItem($order->id, $branch->id, 12.00);

        $totals = app(ZReportService::class)->aggregate($branch->id, null, now()->addMinute());
        $byMethod = $totals['total_by_method'];

        $this->assertEqualsWithDelta(12.00, (float) ($byMethod['1'] ?? 0), 0.01, 'legacy: full total under pos_payment_method');
        $this->assertEqualsWithDelta(12.00, array_sum(array_map('floatval', $byMethod)), 0.01);
    }

    /** Cash overpayment: net contribution (amount - change) is bucketed, Σ stays == total. */
    public function test_cash_overpayment_buckets_net_not_gross(): void
    {
        $branch = Branch::factory()->create();
        $order = Order::factory()->create([
            'branch_id' => $branch->id, 'payment_status' => PaymentStatus::PAID,
            'subtotal' => 10.00, 'discount' => 0, 'total' => 10.00,
            'total_tax' => round(10 - 10 / 1.10, 2), 'pos_payment_method' => 1, 'fiscal_sequence_no' => 1,
        ]);
        $this->insertOrderItem($order->id, $branch->id, 10.00);
        $this->insertTranche($order->id, $branch->id, 2, 4.00);        // card 4
        $this->insertTranche($order->id, $branch->id, 1, 10.00, 4.00); // cash tendered 10, change 4 → net 6

        $totals = app(ZReportService::class)->aggregate($branch->id, null, now()->addMinute());
        $byMethod = $totals['total_by_method'];

        $this->assertEqualsWithDelta(6.00, (float) ($byMethod['1'] ?? 0), 0.01, 'cash net = tendered - change = 6');
        $this->assertEqualsWithDelta(4.00, (float) ($byMethod['2'] ?? 0), 0.01, 'card = 4');
        $this->assertEqualsWithDelta(10.00, array_sum(array_map('floatval', $byMethod)), 0.01, 'Σ == total_ttc even with change');
    }
}
