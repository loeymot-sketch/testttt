<?php

namespace Tests\Feature\Receipt;

use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Fiscal\ZReportService;
use App\Services\Receipt\PosReceiptEscPosRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [HEAL-PRINT-SAGA / G5 2026-06-07 — printed ESC/POS ticket TVA discount netting]
 *
 * Defect (G5 print-saga validation): on a DISCOUNTED order the server-side
 * ESC/POS renderer ({@see PosReceiptEscPosRenderer::taxLines()}) summed the
 * GROSS pre-discount per-line tax_amount with NO discount ratio, so the printed
 * thermal ticket showed the per-rate TVA pre-discount (e.g. 1,82 EUR) instead of
 * the collected / Z-signed netted value (~1,36 EUR). The renderer's taxLines() is
 * the ONLY per-rate ventilation on the printed paper (no per-line tax, no
 * separate header total_tax line), so this was the H7 fiscal defect reincarnated
 * on the physical ticket. The 18 existing print tests all use discount = 0 (so
 * ratio = 1.0 -> gross == netted) and could not catch it.
 *
 * The heal nets BOTH the per-rate tax and HT base by
 * orderDiscountRatio = (subtotal - discount)/subtotal (the EXACT frozen
 * ZReportService::orderDiscountRatio formula), mirroring HEAL-H7 on
 * OrderDetailsResource. This test locks the netted printed value and EQUALS it
 * to the signed Z's total_by_tax_rate; a non-discount control proves the
 * ratio = 1.0 path is unchanged.
 *
 * Sim only: sqlite :memory: (phpunit.xml), RefreshDatabase. No physical printer
 * and no operating-DB access. `vendor/bin/phpunit` only -- never `php artisan test`.
 */
class EscPosDiscountTvaNettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // The Z aggregate is HMAC-signed; pin deterministic test secrets.
        Config::set('fiscal.audit_secret', 'unit-test-secret-g5-escpos-x');
        Config::set('fiscal.z_report_secret', 'unit-test-secret-g5-escpos-z');
        Config::set('pricing.tax_inclusive_prices', true);

        // Admin (branch_id=0) bypasses BranchScope so we can freely build /
        // read the order_items the renderer + Z aggregate consume.
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);
    }

    private function branch(): Branch
    {
        return Branch::factory()->create([
            'name' => 'LE CAYENNE',
            'siret' => '10417050100019',
            'vat_intra' => 'FR19104170501',
            'register_id' => 'CAISSE-01',
            'legal_footer' => 'TVA acquittee sur les debits',
        ]);
    }

    /**
     * Builds a PAID POS order with the REAL production shape: each order_item
     * carries the GROSS pre-discount per-line tax_amount; the coupon/loyalty
     * discount lives at order level only (subtotal/discount/total + gross
     * header total_tax) -- exactly how OrderService stores it.
     */
    private function makeOrder(Branch $branch, float $subtotal, float $discount, float $total, float $grossTotalTax): Order
    {
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'order_serial_no' => 'ORD-G5-DISC',
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $total,
        ]);
        $order->forceFill([
            'fiscal_sequence_no' => 1,
            // GROSS header tax (real production value -- OrderService.php:562).
            'total_tax' => $grossTotalTax,
            'pos_payment_method' => (string) \App\Enums\PosPaymentMethod::CARD,
            'pos_received_amount' => $total,
        ])->save();

        return $order;
    }

    private function addItem(Order $order, string $taxRate, float $grossTaxAmount, float $totalPrice, string $name = 'Article'): void
    {
        $item = Item::factory()->create(['name' => $name]);
        OrderItem::create([
            'order_id' => $order->id,
            'branch_id' => $order->branch_id,
            'item_id' => $item->id,
            'quantity' => 1,
            'discount' => 0,
            'price' => $totalPrice,
            'total_price' => $totalPrice,
            'tax_name' => 'TVA',
            'tax_rate' => $taxRate,
            'tax_type' => 0,
            'tax_amount' => $grossTaxAmount,
        ]);
    }

    private function freshLoaded(Order $order): Order
    {
        return Order::query()->where('id', $order->id)->first()
            ->load(['orderItems.orderItem', 'branch', 'user']);
    }

    /** Pull the renderer's private netted per-rate buckets for exact assertions. */
    private function renderedTaxLines(Order $order): array
    {
        $renderer = app(PosReceiptEscPosRenderer::class);
        $ref = new \ReflectionMethod($renderer, 'taxLines');
        $ref->setAccessible(true);

        return $ref->invoke($renderer, $this->freshLoaded($order));
    }

    private function lineForRate(array $taxLines, string $rate): ?array
    {
        foreach ($taxLines as $tl) {
            if ((string) $tl['rate'] === $rate) {
                return $tl;
            }
        }

        return null;
    }

    /**
     * The validation agent's documented example, REAL shape:
     * subtotal 20,00 . discount 5,00 -> total 15,00 @ 10%. Gross line/header
     * TVA 1,82 (= 20 - 20/1.1). The printed ticket MUST net to 1,37 -- the EXACT
     * value the signed Z produces (gross 1,82 x ratio 0,75 = 1,365, rounded per
     * bucket; the renderer and the Z scale the gross tax_amount identically, so
     * ticket == Z by construction). It MUST NOT be the gross pre-discount 1,82.
     * (The naive recompute 15 - 15/1.1 = 1,36 is a DIFFERENT rounding path the Z
     * does not use; the fiscally-binding oracle is the Z, asserted below.)
     */
    public function test_discounted_ticket_per_rate_tva_is_netted_and_equals_z(): void
    {
        $branch = $this->branch();
        $order = $this->makeOrder($branch, 20.00, 5.00, 15.00, 1.82);
        $this->addItem($order, '10', 1.82, 20.00, 'Tacos Poulet');

        // --- renderer's netted per-rate bucket (the printed TVA line) ---
        $line = $this->lineForRate($this->renderedTaxLines($order), '10');
        $this->assertNotNull($line, 'A 10% tax line MUST be present on the printed ticket.');

        // Netted value = gross 1,82 x ratio (15/20) rounded per bucket = 1,37,
        // and HT base ~13,64 -- NOT the gross pre-discount 1,82 / 18,18. The
        // primary oracle is the Z (asserted last); these lock the printed number.
        $this->assertEqualsWithDelta(1.37, (float) $line['tax'], 0.01, 'Printed per-rate TVA must net to 1,37 (NOT pre-discount 1,82).');
        $this->assertEqualsWithDelta(13.64, (float) $line['base_ht'], 0.01, 'Printed per-rate base HT must net to ~13,64 (NOT pre-discount 18,18).');

        // --- internal consistency, independent of the ratio formula: the netted
        //     buckets' HT + TVA must reconstitute the PAID total (15,00), not the
        //     gross subtotal (20,00). ---
        $reconstituted = round((float) $line['base_ht'] + (float) $line['tax'], 2);
        $this->assertEqualsWithDelta(15.00, $reconstituted, 0.01, 'Sum(HT + TVA) on the ticket must equal the paid total (15,00), not the gross subtotal (20,00).');

        // --- the fiscal gold standard: EQUALS the signed Z for the same order ---
        $z = app(ZReportService::class)->aggregate($branch->id, null, now()->addMinute());
        // EXACT equality (not just within a cent): the renderer and the Z apply
        // the SAME gross-tax x ratio scaling, so the printed TVA is byte-identical
        // to the signed Z bucket. This is the fiscal binding the heal restores.
        $this->assertSame(
            round((float) $z['total_by_tax_rate']['10'], 2),
            round((float) $line['tax'], 2),
            'Printed per-rate TVA MUST EQUAL the signed Z total_by_tax_rate (exact) for the same order.'
        );
    }

    /**
     * The discounted ticket's RENDERED BYTES must show the netted figure and
     * MUST NOT show the gross pre-discount one (decode step -- what comes off the
     * thermal head). ASCII-only assertions (the " EUR" suffix is CP858-transcoded;
     * the numeric "1,36" / "1,82" survive).
     */
    public function test_discounted_rendered_bytes_show_netted_not_gross_tva(): void
    {
        $branch = $this->branch();
        $order = $this->makeOrder($branch, 20.00, 5.00, 15.00, 1.82);
        $this->addItem($order, '10', 1.82, 20.00, 'Tacos Poulet');

        $bytes = app(PosReceiptEscPosRenderer::class)->render($this->freshLoaded($order));

        $this->assertStringContainsString('TVA', $bytes, 'per-rate VAT block present');
        $this->assertStringContainsString('10%', $bytes, 'per-rate VAT line');
        $this->assertStringContainsString('1,37', $bytes, 'printed TVA must be the netted 1,37 (= signed Z)');
        $this->assertStringContainsString('13,64', $bytes, 'printed HT base must be the netted 13,64');
        $this->assertStringNotContainsString('1,82', $bytes, 'printed TVA must NOT be the gross pre-discount 1,82');
        $this->assertStringNotContainsString('18,18', $bytes, 'printed HT base must NOT be the gross pre-discount 18,18');
        // The net TOTAL A PAYER (15,00) was already correct and stays.
        $this->assertStringContainsString('15,00', $bytes, 'paid total 15,00 present');
        $this->assertStringContainsString('Remise', $bytes, 'discount line present');
    }

    /**
     * Multi-rate discounted order -- proves proportional allocation per bucket
     * (each scaled by the same net/gross ratio). subtotal 30,00 / discount 3,00
     * -> ratio 0,9 ; 10% gross 1,82 -> 1,64 ; 5,5% gross 0,52 -> 0,47. EQUALS the Z.
     */
    public function test_multi_rate_discount_ticket_allocates_proportionally(): void
    {
        $branch = $this->branch();
        $order = $this->makeOrder($branch, 30.00, 3.00, 27.00, 2.34);
        $this->addItem($order, '10', 1.82, 20.00, 'Tacos Poulet');
        $this->addItem($order, '5.5', 0.52, 10.00, 'Boisson');

        $taxLines = $this->renderedTaxLines($order);
        $line10 = $this->lineForRate($taxLines, '10');
        $line55 = $this->lineForRate($taxLines, '5.5');
        $this->assertNotNull($line10);
        $this->assertNotNull($line55);
        $this->assertEqualsWithDelta(1.64, (float) $line10['tax'], 0.02, '10% bucket scaled by 0,9.');
        $this->assertEqualsWithDelta(0.47, (float) $line55['tax'], 0.02, '5,5% bucket scaled by 0,9.');

        $z = app(ZReportService::class)->aggregate($branch->id, null, now()->addMinute());
        $this->assertEqualsWithDelta((float) $z['total_by_tax_rate']['10'], (float) $line10['tax'], 0.02);
        $this->assertEqualsWithDelta((float) $z['total_by_tax_rate']['5.5'], (float) $line55['tax'], 0.02);
    }

    /**
     * Regression control -- a NON-discounted order is byte-identical (ratio = 1).
     * 17,00 TTC @ 10% -> gross == net == 1,55 / HT 15,45. Mirrors the existing
     * 18-test fixtures (all discount = 0) so the heal is proven non-regressive.
     */
    public function test_non_discounted_ticket_is_unchanged(): void
    {
        $branch = $this->branch();
        $order = $this->makeOrder($branch, 17.00, 0.00, 17.00, 1.55);
        $this->addItem($order, '10', 1.55, 17.00, 'Tacos Poulet');

        $line = $this->lineForRate($this->renderedTaxLines($order), '10');
        $this->assertNotNull($line);
        $this->assertEqualsWithDelta(1.55, (float) $line['tax'], 0.001, 'No discount -> gross == net (1,55).');
        $this->assertEqualsWithDelta(15.45, (float) $line['base_ht'], 0.001, 'No discount -> HT base 15,45 unchanged.');

        $bytes = app(PosReceiptEscPosRenderer::class)->render($this->freshLoaded($order));
        $this->assertStringContainsString('1,55', $bytes, 'non-discount TVA unchanged on the printed ticket');
        $this->assertStringContainsString('15,45', $bytes, 'non-discount HT unchanged on the printed ticket');
    }
}
