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
 * [POS-9-H.2.8 / F-B6]
 *
 * ZReportService::aggregate() used to return `total_by_tax_rate => []`
 * with a "reserved" comment. But fiscal_sequence_no was designed to
 * be the evidence of an NF525 close — signing an empty tax breakdown
 * made the Z useless for any VAT reconciliation downstream.
 *
 * Fix: aggregate `order_items.tax_rate` / `tax_amount` (server-
 * recomputed pricing from POS-9.1.8) grouped per rate, with a stable
 * canonical key format ("10" and "5.5", not "10.00" and "5.50").
 */
class ZReportTaxBreakdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('fiscal.audit_secret',    'unit-test-secret-tax-break-x');
        Config::set('fiscal.z_report_secret', 'unit-test-secret-tax-break-z');
    }

    private function insertOrderItem(int $orderId, int $branchId, string $taxRate, float $taxAmount): void
    {
        $itemId = Item::query()->value('id') ?? Item::factory()->create()->id;

        DB::table('order_items')->insert([
            'order_id'   => $orderId,
            'branch_id'  => $branchId,
            'item_id'    => $itemId,
            'quantity'   => 1,
            'discount'   => 0,
            'price'      => 1.00,
            'tax_rate'   => $taxRate,
            'tax_amount' => $taxAmount,
            'total_price'=> 1.00 + $taxAmount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_tax_breakdown_groups_per_rate(): void
    {
        $branch = Branch::factory()->create();
        $to     = now()->addMinute();

        // Order A: one line @ 10% TVA (tax_amount = 1.00).
        $a = Order::factory()->create([
            'branch_id'          => $branch->id,
            'payment_status'     => PaymentStatus::PAID,
            'total'              => 11.00,
            'total_tax'          => 1.00,
            'fiscal_sequence_no' => 1,
        ]);
        $this->insertOrderItem($a->id, $branch->id, '10.00', 1.00);

        // Order B: one line @ 10% (2.00) and one @ 5.5% (0.55).
        $b = Order::factory()->create([
            'branch_id'          => $branch->id,
            'payment_status'     => PaymentStatus::PAID,
            'total'              => 25.55,
            'total_tax'          => 2.55,
            'fiscal_sequence_no' => 2,
        ]);
        $this->insertOrderItem($b->id, $branch->id, '10',  2.00);
        $this->insertOrderItem($b->id, $branch->id, '5.5', 0.55);

        // Unpaid order: its items must NOT appear in the breakdown
        // because ZReportService only aggregates PAID orders.
        $u = Order::factory()->create([
            'branch_id'          => $branch->id,
            'payment_status'     => PaymentStatus::UNPAID,
            'total'              => 50.00,
            'fiscal_sequence_no' => 3,
        ]);
        $this->insertOrderItem($u->id, $branch->id, '20', 10.00);

        $totals = app(ZReportService::class)->aggregate($branch->id, null, $to);

        $this->assertIsArray($totals['total_by_tax_rate']);
        // 10% bucket: A(1.00) + B(2.00) = 3.00 ; 5.5% bucket: B(0.55).
        // The 20% line of the unpaid order is excluded.
        $this->assertArrayHasKey('10', $totals['total_by_tax_rate']);
        $this->assertEqualsWithDelta(3.00, (float) $totals['total_by_tax_rate']['10'], 0.01);

        $this->assertArrayHasKey('5.5', $totals['total_by_tax_rate']);
        $this->assertEqualsWithDelta(0.55, (float) $totals['total_by_tax_rate']['5.5'], 0.01);

        $this->assertArrayNotHasKey('20', $totals['total_by_tax_rate']);
    }

    public function test_empty_when_no_orders(): void
    {
        $branch = Branch::factory()->create();
        $totals = app(ZReportService::class)->aggregate($branch->id, null, now()->addMinute());

        $this->assertSame([], $totals['total_by_tax_rate']);
    }

    public function test_tax_rate_keys_are_canonicalised(): void
    {
        // tax_rate stored as "10.00" and "10" must collapse to the same
        // "10" bucket — otherwise the signed JSON would fragment
        // buckets that are reconciled as identical rates.
        $branch = Branch::factory()->create();

        $o = Order::factory()->create([
            'branch_id'          => $branch->id,
            'payment_status'     => PaymentStatus::PAID,
            'total'              => 20.00,
            'fiscal_sequence_no' => 1,
        ]);
        $this->insertOrderItem($o->id, $branch->id, '10.00', 1.00);
        $this->insertOrderItem($o->id, $branch->id, '10',    1.00);

        $totals = app(ZReportService::class)->aggregate($branch->id, null, now()->addMinute());

        $this->assertArrayHasKey('10', $totals['total_by_tax_rate']);
        $this->assertCount(1, $totals['total_by_tax_rate'],
            'Tax-rate keys must canonicalise — two keys for the same rate would fragment the signed JSON.');
        $this->assertEqualsWithDelta(2.00, (float) $totals['total_by_tax_rate']['10'], 0.01);
    }
}
