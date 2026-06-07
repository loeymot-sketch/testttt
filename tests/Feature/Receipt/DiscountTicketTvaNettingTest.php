<?php

namespace Tests\Feature\Receipt;

use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Http\Resources\OrderDetailsResource;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\User;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [HEAL-H7 / CP-1 2026-06-07 — printed-ticket TVA discount netting]
 *
 * Defect (CLUSTER-PAY CP-1): the printed NF525 ticket is built from
 * OrderDetailsResource. On a discounted order, OrderService stores the
 * PRE-discount per-line tax_amount (the coupon/loyalty discount is applied
 * at order level only — OrderService.php:504-562), so the header
 * total_tax = Σ gross per-line tax (e.g. 0,91 on a 10,00 TTC order at 10%).
 * The signed Z and the refund counter-entry both NET this by
 * orderDiscountRatio = (subtotal − discount)/subtotal (proportional
 * allocation across rate buckets, recomputing TVA on the post-discount base
 * → 0,73). The printed ticket is ITSELF an NF525 fiscal document, so its
 * per-rate TVA/HT and header total_tax MUST equal the collected/Z TVA.
 *
 * Pre-fix buildTaxLines() summed the raw per-line tax with NO ratio → the
 * ticket showed gross 0,91 / 9,09 while the paid 8,00 contains TVA 0,73 on
 * HT 7,27. This test locks the netting and EQUALS it to the Z's
 * total_by_tax_rate (the locked ZReportDiscountNettingTest SSOT).
 *
 * Coverage matters: the resource is asserted via its public toArray()
 * (header total_tax + subtotal_without_tax + per-rate tax_lines), NOT just
 * the private buildTaxLines — the header is the value the advisor flagged as
 * the production-correctness crux (must derive from the netted buckets,
 * never from order->total_tax × ratio).
 */
class DiscountTicketTvaNettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('fiscal.audit_secret',    'unit-test-secret-h7-ticket-x');
        Config::set('fiscal.z_report_secret', 'unit-test-secret-h7-ticket-z');
        Config::set('pricing.tax_inclusive_prices', true);
    }

    /**
     * Insert an order_item with the REAL production shape: tax_amount is the
     * GROSS pre-discount line tax (mirrors OrderService + ZReportDiscountNettingTest).
     */
    private function insertOrderItem(Order $order, string $taxRate, float $grossTaxAmount, float $totalPrice): void
    {
        $itemId = Item::query()->value('id') ?? Item::factory()->create()->id;
        DB::table('order_items')->insert([
            'order_id'    => $order->id,
            'branch_id'   => $order->branch_id,
            'item_id'     => $itemId,
            'quantity'    => 1,
            'discount'    => 0,
            'tax_name'    => 'VAT',
            'tax_rate'    => $taxRate,
            'tax_type'    => 0,
            'tax_amount'  => $grossTaxAmount,
            'price'       => $totalPrice,
            'total_price' => $totalPrice,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function makeOrder(Branch $branch, float $subtotal, float $discount, float $total, float $grossTotalTax): Order
    {
        $user = User::factory()->create(['branch_id' => $branch->id]);

        // Do NOT eager-load orderItems here: the caller inserts the lines AFTER
        // this returns, and a relation loaded while the table is empty caches an
        // empty collection that buildTaxLines would then read. resourceArray()
        // re-resolves a FRESH model so the relation reflects the inserted rows.
        return Order::factory()->create([
            'branch_id'          => $branch->id,
            'user_id'            => $user->id,
            'order_type'         => OrderType::POS,
            'payment_status'     => PaymentStatus::PAID,
            'subtotal'           => $subtotal,
            'discount'           => $discount,
            'total'              => $total,
            // GROSS header tax (the real production value — OrderService.php:562).
            'total_tax'          => $grossTotalTax,
            'fiscal_sequence_no' => 1,
        ]);
    }

    private function resourceArray(Order $order): array
    {
        // Re-resolve a fresh model and load relations AFTER items were inserted,
        // mirroring how the controller hands the resource a fully-loaded order.
        $fresh = Order::query()->where('id', $order->id)->first();
        $fresh->load(['branch', 'user', 'orderItems']);

        return (new OrderDetailsResource($fresh))->toArray(request());
    }

    private function taxLineForRate(array $taxLines, string $rate): ?array
    {
        foreach ($taxLines as $line) {
            if ((string) $line['tax_rate'] === $rate) {
                return $line;
            }
        }

        return null;
    }

    /**
     * The documented F1/CP-1 example, REAL shape: 10,00 TTC gross, 2,00
     * discount → 8,00 net. Gross line/header TVA 0,91. Correct printed ticket:
     * per-rate TVA 0,73, base_HT 7,27, header total_tax 0,73, HT 7,27.
     * EQUALS the signed Z's total_by_tax_rate for the same order.
     */
    public function test_discounted_ticket_per_rate_tva_is_netted_and_equals_z(): void
    {
        $branch = Branch::factory()->create();
        $order = $this->makeOrder($branch, 10.00, 2.00, 8.00, 0.91);
        $this->insertOrderItem($order, '10.00', 0.91, 10.00);

        $data = $this->resourceArray($order);

        // --- per-rate tax_lines (the printed ticket lines) ---
        $line = $this->taxLineForRate($data['tax_lines'], '10');
        $this->assertNotNull($line, 'A 10% tax line MUST be present on the ticket.');
        $this->assertEqualsWithDelta(0.73, (float) $line['tax'], 0.01, 'Per-rate TVA must be netted to 0,73 (NOT pre-discount 0,91).');
        $this->assertEqualsWithDelta(7.27, (float) $line['base_ht'], 0.01, 'Per-rate base HT must be netted to 7,27 (NOT pre-discount 9,09).');

        // --- header fields exposed by toArray (the advisor crux) ---
        $this->assertEqualsWithDelta(0.73, (float) $data['total_tax'], 0.01, 'Header total_tax must be the netted Σ-bucket TVA (0,73), not gross 0,91.');

        // --- header total_tax == Σ tax_lines (internal consistency of the ticket) ---
        $sumLines = round(array_sum(array_map(static fn ($l) => (float) $l['tax'], $data['tax_lines'])), 2);
        $this->assertSame($sumLines, round((float) $data['total_tax'], 2), 'Header total_tax MUST equal Σ per-rate tax_lines.');

        // --- EQUALS the signed Z for the same order (the fiscal proof) ---
        $z = app(ZReportService::class)->aggregate($branch->id, null, now()->addMinute());
        $this->assertEqualsWithDelta(
            (float) $z['total_by_tax_rate']['10'],
            (float) $line['tax'],
            0.01,
            'Printed-ticket per-rate TVA MUST equal the signed Z total_by_tax_rate for the same order.'
        );
        $this->assertEqualsWithDelta(
            (float) $z['total_tva'],
            (float) $data['total_tax'],
            0.01,
            'Printed-ticket header total_tax MUST equal the signed Z total_tva.'
        );
    }

    /**
     * Multi-rate discounted order — proves proportional allocation per bucket
     * (each scaled by the same net/gross ratio), mirroring the Z multi-rate
     * case. subtotal 30,00 / discount 3,00 → ratio 0,9 → 10% 1,64 + 5,5% 0,47;
     * header total_tax 2,11.
     */
    public function test_multi_rate_discount_ticket_allocates_proportionally(): void
    {
        $branch = Branch::factory()->create();
        $order = $this->makeOrder($branch, 30.00, 3.00, 27.00, 2.34);
        $this->insertOrderItem($order, '10',  1.82, 20.00);
        $this->insertOrderItem($order, '5.5', 0.52, 10.00);

        $data = $this->resourceArray($order);

        $line10 = $this->taxLineForRate($data['tax_lines'], '10');
        $line55 = $this->taxLineForRate($data['tax_lines'], '5.5');
        $this->assertNotNull($line10);
        $this->assertNotNull($line55);
        $this->assertEqualsWithDelta(1.64, (float) $line10['tax'], 0.02, '10% bucket scaled by 0,9.');
        $this->assertEqualsWithDelta(0.47, (float) $line55['tax'], 0.02, '5,5% bucket scaled by 0,9.');

        $sumLines = round((float) $line10['tax'] + (float) $line55['tax'], 2);
        $this->assertSame($sumLines, round((float) $data['total_tax'], 2), 'Header total_tax = Σ per-rate-then-sum (rounding matches the Z).');

        $z = app(ZReportService::class)->aggregate($branch->id, null, now()->addMinute());
        $this->assertEqualsWithDelta((float) $z['total_by_tax_rate']['10'], (float) $line10['tax'], 0.02);
        $this->assertEqualsWithDelta((float) $z['total_by_tax_rate']['5.5'], (float) $line55['tax'], 0.02);
    }

    /**
     * Regression guard: a NON-discounted order is byte-identical (ratio = 1).
     * Mirrors the live control order #4160 (1,50 TTC at 10% → 0,14 / HT 1,36).
     */
    public function test_non_discounted_ticket_is_unchanged(): void
    {
        $branch = Branch::factory()->create();
        $order = $this->makeOrder($branch, 1.50, 0.00, 1.50, 0.14);
        $this->insertOrderItem($order, '10.00', 0.14, 1.50);

        $data = $this->resourceArray($order);

        $line = $this->taxLineForRate($data['tax_lines'], '10');
        $this->assertNotNull($line);
        $this->assertEqualsWithDelta(0.14, (float) $line['tax'], 0.001, 'No discount → gross == net (0,14).');
        $this->assertEqualsWithDelta(1.36, (float) $line['base_ht'], 0.001, 'No discount → HT base 1,36 unchanged.');
        $this->assertEqualsWithDelta(0.14, (float) $data['total_tax'], 0.001, 'Header total_tax unchanged on non-discount order.');
    }

    /**
     * subtotal_without_tax must mirror the Z's total_ht = total_ttc − total_tva
     * (= total − netted TVA), NOT the pre-fix subtotal − gross TVA which would
     * produce the spurious 9,27 on the discounted order.
     */
    public function test_subtotal_without_tax_currency_uses_post_discount_ht(): void
    {
        $branch = Branch::factory()->create();
        $order = $this->makeOrder($branch, 10.00, 2.00, 8.00, 0.91);
        $this->insertOrderItem($order, '10.00', 0.91, 10.00);

        $data = $this->resourceArray($order);

        // total − netted TVA = 8.00 − 0.73 = 7.27 ; must NOT be 9,27 (subtotal − gross 0,73-misread)
        // and must NOT be 9,09 (gross HT). currencyAmountFormat renders FR.
        $this->assertStringContainsString('7,27', (string) $data['subtotal_without_tax_currency_price'], 'HT base on the ticket must be the post-discount 7,27.');
        $this->assertStringNotContainsString('9,27', (string) $data['subtotal_without_tax_currency_price'], 'Must not be the spurious subtotal − netted-tax (9,27).');
        $this->assertStringNotContainsString('9,09', (string) $data['subtotal_without_tax_currency_price'], 'Must not be the gross pre-discount HT (9,09).');
    }
}
