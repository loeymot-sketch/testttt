<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [C33 / LOCK_ZREPORT_FISCAL_C33_DELIVERY_VAT — 2026-07-07] Delivery-fee VAT.
 *
 * `delivery_charge` is embedded in order->total (OrderService total formula: subtotal
 * [+tax] + delivery_charge − discount), so it already flows into total_ttc. But the
 * per-rate SSOT (total_by_tax_rate) is built from order_items.tax_amount only, and the
 * delivery fee is not an order_item → historically the fee was declared at an implicit
 * 0 % VAT. Owner decision (2026-07-07): the delivery fee carries the food VAT rate
 * (config menu.settings.tax_rate = 10 %), treated as TTC (the customer pays the same).
 *
 * The VAT share round(delivery_charge × rate/(100+rate), 2) is added to the food-rate
 * bucket; total_ttc is UNCHANGED and the NF525 identities (total_tva == Σ byTaxRate,
 * total_ttc == total_ht + total_tva) hold by construction. Reference: a 4,40 delivery
 * fee at 10 % → 0,40 VAT / 4,00 HT.
 */
class ZReportDeliveryVatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('fiscal.audit_secret',    'unit-test-secret-delivery-x');
        Config::set('fiscal.z_report_secret', 'unit-test-secret-delivery-z');
        Config::set('pricing.tax_inclusive_prices', true);
        // The delivery VAT rate is read from config (menu.settings.tax_rate = 10.00);
        // pin it explicitly so the test does not depend on a mutated global config.
        Config::set('menu.settings.tax_rate', 10.00);
    }

    private function insertOrderItem(int $orderId, int $branchId, string $taxRate, float $taxAmount, float $totalPrice): void
    {
        $itemId = Item::query()->value('id') ?? Item::factory()->create()->id;
        DB::table('order_items')->insert([
            'order_id'    => $orderId,
            'branch_id'   => $branchId,
            'item_id'     => $itemId,
            'quantity'    => 1,
            'discount'    => 0,
            'price'       => abs($totalPrice),
            'tax_rate'    => $taxRate,
            'tax_amount'  => $taxAmount,
            'total_price' => $totalPrice,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /**
     * A delivery order (item 10,00 TTC @10 % → tax 0,91 ; delivery_charge 4,40) adds the
     * delivery VAT (0,40) to the food-rate bucket. total_ttc unchanged (14,40), identities hold.
     */
    public function test_delivery_charge_adds_food_rate_vat_to_bucket(): void
    {
        $branch = Branch::factory()->create();

        $order = Order::factory()->create([
            'branch_id'          => $branch->id,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'subtotal'           => 10.00,
            'discount'           => 0,
            'delivery_charge'    => 4.40,
            'total'              => 14.40,   // items 10,00 TTC + delivery 4,40
            'total_tax'          => 0.91,
            'fiscal_sequence_no' => 1,
        ]);
        $this->insertOrderItem($order->id, $branch->id, '10.00', 0.91, 10.00);

        $totals = app(ZReportService::class)->aggregate($branch->id, null, now()->addMinute());

        $deliveryVat = round(4.40 * 10 / 110, 2); // 0,40
        $this->assertSame(0.40, $deliveryVat, 'Reference: 4,40 delivery @10 % → 0,40 VAT.');

        $this->assertArrayHasKey('10', $totals['total_by_tax_rate']);
        $this->assertEqualsWithDelta(
            0.91 + $deliveryVat,
            (float) $totals['total_by_tax_rate']['10'],
            0.001,
            'Food bucket must include item TVA (0,91) + delivery TVA (0,40) = 1,31.'
        );

        // total_ttc is UNCHANGED (delivery already in total).
        $this->assertEqualsWithDelta(14.40, (float) $totals['total_ttc'], 0.001);
        $this->assertEqualsWithDelta(1.31, (float) $totals['total_tva'], 0.001);
        $this->assertEqualsWithDelta(13.09, (float) $totals['total_ht'], 0.001);

        // NF525 identities (EXACT).
        $this->assertSame(
            round((float) array_sum($totals['total_by_tax_rate']), 2),
            (float) $totals['total_tva'],
            'total_tva == Σ total_by_tax_rate (includes delivery VAT).'
        );
        $this->assertSame(
            round((float) $totals['total_ht'] + (float) $totals['total_tva'], 2),
            (float) $totals['total_ttc'],
            'total_ttc == total_ht + total_tva.'
        );
    }

    /**
     * Delivery-only order isolates the exact delivery VAT: delivery_charge 4,40, no items
     * → bucket['10'] = 0,40, total_ttc 4,40, total_ht 4,00.
     */
    public function test_delivery_only_order_isolates_the_exact_delivery_vat(): void
    {
        $branch = Branch::factory()->create();

        Order::factory()->create([
            'branch_id'          => $branch->id,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'subtotal'           => 0,
            'discount'           => 0,
            'delivery_charge'    => 4.40,
            'total'              => 4.40,
            'total_tax'          => 0,
            'fiscal_sequence_no' => 1,
        ]);

        $totals = app(ZReportService::class)->aggregate($branch->id, null, now()->addMinute());

        $this->assertEqualsWithDelta(0.40, (float) ($totals['total_by_tax_rate']['10'] ?? 0), 0.001,
            'Delivery-only order: bucket[10] must equal round(4,40×10/110,2) = 0,40.');
        $this->assertEqualsWithDelta(4.40, (float) $totals['total_ttc'], 0.001);
        $this->assertEqualsWithDelta(0.40, (float) $totals['total_tva'], 0.001);
        $this->assertEqualsWithDelta(4.00, (float) $totals['total_ht'], 0.001);
    }

    /**
     * Delivery + discount compose correctly: the discount nets the ITEM VAT (F1), the
     * delivery fee adds its OWN full VAT (not discount-scaled). item 10,00 TTC tax 0,91,
     * discount 2,00 (ratio 0,8) → net item TVA 0,73 ; delivery 4,40 → 0,40 ; bucket 1,13.
     */
    public function test_delivery_with_discount_composes_without_double_count(): void
    {
        $branch = Branch::factory()->create();

        $order = Order::factory()->create([
            'branch_id'          => $branch->id,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'subtotal'           => 10.00,
            'discount'           => 2.00,
            'delivery_charge'    => 4.40,
            'total'              => 12.40,   // 10,00 − 2,00 + 4,40
            'total_tax'          => 0.91,
            'fiscal_sequence_no' => 1,
        ]);
        $this->insertOrderItem($order->id, $branch->id, '10.00', 0.91, 10.00);

        $totals = app(ZReportService::class)->aggregate($branch->id, null, now()->addMinute());

        // Net item TVA (F1) = 0,91 × 0,8 = 0,728 → rounds to 0,73 alone.
        // Delivery TVA = 0,40 (FULL, not scaled by the discount ratio).
        // Bucket = round(0,728 + 0,40, 2) = round(1,128, 2) = 1,13.
        $this->assertEqualsWithDelta(1.13, (float) $totals['total_by_tax_rate']['10'], 0.001,
            'Bucket = netted item TVA (0,73) + full delivery TVA (0,40).');

        // Delivery VAT must NOT be discount-scaled: bucket − netItemTva == 0,40 (not 0,32).
        $this->assertEqualsWithDelta(0.40, (float) $totals['total_by_tax_rate']['10'] - 0.73, 0.001,
            'Delivery VAT is added at full rate — the discount nets item VAT only, not the fee.');

        $this->assertEqualsWithDelta(12.40, (float) $totals['total_ttc'], 0.001, 'total_ttc unchanged.');
        $this->assertSame(
            round((float) array_sum($totals['total_by_tax_rate']), 2),
            (float) $totals['total_tva'],
            'total_tva == Σ byTaxRate (item netting + delivery compose).'
        );
        $this->assertSame(
            round((float) $totals['total_ht'] + (float) $totals['total_tva'], 2),
            (float) $totals['total_ttc'],
            'total_ttc == total_ht + total_tva.'
        );
    }

    /**
     * Refund-mirror symmetry: a counter-entry refund of a delivery order must REVERSE the
     * delivery VAT too. The real mirror negates parent.total (delivery embedded) but stores
     * NO delivery_charge of its own → the aggregator reads the parent's delivery_charge to
     * subtract the matching VAT. Without this, a refunded delivery order would leave a
     * residual +0,40 delivery VAT that never nets → over-declared TVA.
     */
    public function test_delivery_refund_mirror_reverses_delivery_vat(): void
    {
        $branch = Branch::factory()->create();
        $from   = Carbon::parse('2026-06-05 08:00:00');
        $to     = Carbon::parse('2026-06-05 20:00:00');

        // Parent: sold + sealed in a PRIOR window (delivery order 14,40 = item 10 + delivery 4,40).
        $parent = Order::factory()->create([
            'branch_id'          => $branch->id,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'subtotal'           => 10.00,
            'discount'           => 0,
            'delivery_charge'    => 4.40,
            'total'              => 14.40,
            'total_tax'          => 0.91,
            'fiscal_sequence_no' => 1,
            'created_at'         => $from->copy()->subDay(),
            'updated_at'         => $from->copy()->subDay(),
        ]);
        $this->insertOrderItem($parent->id, $branch->id, '10.00', 0.91, 10.00);

        // Mirror created IN the current window: parent_order_id set, total negated,
        // delivery_charge NOT copied (faithful to RefundWithCounterEntryService), items negated.
        $mirror = Order::factory()->create([
            'branch_id'          => $branch->id,
            'parent_order_id'    => $parent->id,
            'payment_status'     => PaymentStatus::REFUNDED,
            'status'             => OrderStatus::RETURNED,
            'subtotal'           => -10.00,
            'discount'           => 0,
            'delivery_charge'    => 0,   // mirror carries no delivery_charge (real service behaviour)
            'total'              => -14.40,
            'total_tax'          => -0.91,
            'fiscal_sequence_no' => 2,
            'created_at'         => $from->copy()->addHour(),
            'updated_at'         => $from->copy()->addHour(),
        ]);
        $this->insertOrderItem($mirror->id, $branch->id, '10.00', -0.91, -10.00);

        $totals = app(ZReportService::class)->aggregate($branch->id, $from, $to);

        // The refund window nets the FULL delivery order: total_ttc = -14,40 and the food
        // bucket = -(item 0,91 + delivery 0,40) = -1,31 (delivery VAT reversed via parent).
        $this->assertEqualsWithDelta(-14.40, (float) $totals['total_ttc'], 0.01,
            'Refund mirror nets the full delivery TTC (item + fee).');
        $this->assertEqualsWithDelta(-1.31, (float) $totals['total_by_tax_rate']['10'], 0.001,
            'Refund reverses BOTH item VAT (0,91) AND delivery VAT (0,40) — delivery VAT read from parent.');
        $this->assertEqualsWithDelta(-1.31, (float) $totals['total_tva'], 0.001);
        $this->assertSame(1, (int) $totals['refund_count']);

        // Identity still holds on the negative window.
        $this->assertSame(
            round((float) $totals['total_ht'] + (float) $totals['total_tva'], 2),
            (float) $totals['total_ttc'],
            'total_ttc == total_ht + total_tva on the refund window.'
        );
    }

    /**
     * Regression guard: a NON-delivery order (delivery_charge 0) is byte-identical to the
     * pre-C33 breakdown — the food bucket carries only the item VAT.
     */
    public function test_non_delivery_order_breakdown_is_unchanged(): void
    {
        $branch = Branch::factory()->create();

        $order = Order::factory()->create([
            'branch_id'          => $branch->id,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'subtotal'           => 11.00,
            'discount'           => 0,
            'delivery_charge'    => 0,
            'total'              => 11.00,
            'total_tax'          => 1.00,
            'fiscal_sequence_no' => 1,
        ]);
        $this->insertOrderItem($order->id, $branch->id, '10.00', 1.00, 11.00);

        $totals = app(ZReportService::class)->aggregate($branch->id, null, now()->addMinute());

        $this->assertEqualsWithDelta(1.00, (float) $totals['total_by_tax_rate']['10'], 0.001,
            'No delivery → bucket unchanged (item VAT only).');
        $this->assertEqualsWithDelta(1.00, (float) $totals['total_tva'], 0.001);
        $this->assertEqualsWithDelta(11.00, (float) $totals['total_ttc'], 0.001);
    }
}
