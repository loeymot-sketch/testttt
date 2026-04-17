<?php

namespace Tests\Feature\Services\Pricing;

use App\Enums\DiscountType;
use App\Enums\OrderType;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\Tax;
use App\Services\CouponService;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end coverage of the Pricing Single Source of Truth.
 *
 * Goal: freeze the contract of PricingService::calculateOrder() across every
 * surface (web/pos/table/kiosk) and every payload shape the frontends emit.
 * Any drift — rounding, discount stacking, cross-item guard, etc. — fails a
 * named test with a directly actionable error.
 *
 * @see app/Services/Pricing/PricingService.php
 * @see docs/PRICING_SSOT.md
 */
class PricingServiceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Tax $tax10;
    private Tax $tax20;
    private ItemCategory $category;
    private PricingService $service;
    private CouponService $couponService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
        $this->tax10 = Tax::factory()->create([
            'name' => 'TVA 10%',
            'code' => 'TVA10',
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 10.0,
            'status' => Status::ACTIVE,
        ]);
        $this->tax20 = Tax::factory()->create([
            'name' => 'TVA 20%',
            'code' => 'TVA20',
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 20.0,
            'status' => Status::ACTIVE,
        ]);
        $this->category = ItemCategory::factory()->create([
            'name' => 'Test Category',
            'wizard_template' => 'tacos',
            'has_menu' => true,
        ]);

        $this->service = new PricingService();
        $this->couponService = app(CouponService::class);
    }

    /* -----------------------------------------------------------------
     * Single-line basics
     * ---------------------------------------------------------------*/

    public function test_empty_cart_produces_zero_everywhere(): void
    {
        $req = PricingRequest::forPos(1, $this->branch->id, [], 0, 0, 0.0, 0.0);
        $out = $this->service->calculateOrder($req, $this->couponService);

        $this->assertSame([], $out->orderItemInsertRows);
        $this->assertSame([], $out->lines);
        $this->assertSame(0.0, $out->accumulatedSubtotal);
        $this->assertSame(0.0, $out->subtotal);
        $this->assertSame(0.0, $out->totalTax);
        $this->assertSame(0.0, $out->discount);
        $this->assertSame(0.0, $out->total);
    }

    public function test_single_line_pos_rounds_totals_and_tax(): void
    {
        $item = $this->makeItem(12.345, $this->tax10);

        $req = PricingRequest::forPos(
            1,
            $this->branch->id,
            [$this->line($item->id, 2)],
            0,
            0,
            0.0,
            0.0
        );
        $out = $this->service->calculateOrder($req, $this->couponService);

        // Line subtotal = 12.345 * 2 = 24.69 (rounded from 24.69)
        $this->assertCount(1, $out->lines);
        $this->assertSame(24.69, $out->lines[0]->lineSubtotalExTax);
        $this->assertSame(2.47, $out->totalTax); // 10% of 24.69 rounded
        $this->assertSame(27.16, $out->total);
    }

    public function test_single_line_web_does_not_round(): void
    {
        $item = $this->makeItem(12.345, $this->tax10);

        $req = PricingRequest::forWeb(
            1,
            $this->branch->id,
            [$this->line($item->id, 2)],
            0,
            0,
            0.0
        );
        $out = $this->service->calculateOrder($req, $this->couponService);

        // Unrounded: 12.345 * 2 = 24.69, tax 2.469
        $this->assertEqualsWithDelta(24.69, $out->lines[0]->lineSubtotalExTax, 0.0001);
        $this->assertEqualsWithDelta(2.469, $out->totalTax, 0.0001);
    }

    public function test_zero_priced_item_is_valid(): void
    {
        $item = $this->makeItem(0.0, $this->tax10);

        $req = PricingRequest::forPos(1, $this->branch->id, [$this->line($item->id, 3)], 0, 0, 0.0, 0.0);
        $out = $this->service->calculateOrder($req, $this->couponService);

        $this->assertSame(0.0, $out->lines[0]->lineSubtotalExTax);
        $this->assertSame(0.0, $out->totalTax);
        $this->assertSame(0.0, $out->total);
    }

    public function test_quantity_is_clamped_to_minimum_one(): void
    {
        $item = $this->makeItem(5.00, $this->tax10);

        // Quantity 0 must behave like quantity 1 (pricing never produces empty rows from bad input).
        $req = PricingRequest::forPos(1, $this->branch->id, [$this->line($item->id, 0)], 0, 0, 0.0, 0.0);
        $out = $this->service->calculateOrder($req, $this->couponService);

        $this->assertSame(1, $out->lines[0]->quantity);
        $this->assertSame(5.00, $out->lines[0]->lineSubtotalExTax);
    }

    /* -----------------------------------------------------------------
     * Multi-line, mixed tax
     * ---------------------------------------------------------------*/

    public function test_multi_line_same_tax_sums_correctly(): void
    {
        $a = $this->makeItem(10.00, $this->tax10);
        $b = $this->makeItem(4.50, $this->tax10);

        $req = PricingRequest::forPos(1, $this->branch->id, [
            $this->line($a->id, 1),
            $this->line($b->id, 2),
        ], 0, 0, 0.0, 0.0);
        $out = $this->service->calculateOrder($req, $this->couponService);

        // 10 + (4.5 * 2) = 19.00, tax 10% = 1.90, total 20.90
        $this->assertSame(19.00, $out->subtotal);
        $this->assertSame(1.90, $out->totalTax);
        $this->assertSame(20.90, $out->total);
    }

    public function test_multi_line_mixed_tax_accumulates_per_line(): void
    {
        $food = $this->makeItem(10.00, $this->tax10);
        $drink = $this->makeItem(5.00, $this->tax20);

        $req = PricingRequest::forPos(1, $this->branch->id, [
            $this->line($food->id, 1),
            $this->line($drink->id, 1),
        ], 0, 0, 0.0, 0.0);
        $out = $this->service->calculateOrder($req, $this->couponService);

        $this->assertSame(15.00, $out->subtotal);
        $this->assertSame(2.00, $out->totalTax); // 1.00 + 1.00
        $this->assertSame(17.00, $out->total);
    }

    /* -----------------------------------------------------------------
     * Variations & extras
     * ---------------------------------------------------------------*/

    public function test_variation_price_adds_to_unit(): void
    {
        $item = $this->makeItem(10.00, $this->tax10);
        $variation = $this->makeVariation($item, 2.50);

        $req = PricingRequest::forPos(1, $this->branch->id, [
            $this->line($item->id, 1, [['id' => $variation->id]]),
        ], 0, 0, 0.0, 0.0);
        $out = $this->service->calculateOrder($req, $this->couponService);

        // (10 + 2.5) * 1 = 12.50, tax 1.25, total 13.75
        $this->assertSame(12.50, $out->lines[0]->lineSubtotalExTax);
        $this->assertSame(1.25, $out->totalTax);
        $this->assertSame(13.75, $out->total);
    }

    public function test_extra_price_adds_to_unit(): void
    {
        $item = $this->makeItem(10.00, $this->tax10);
        $extra = $this->makeExtra($item, 1.20);

        $req = PricingRequest::forPos(1, $this->branch->id, [
            $this->line($item->id, 2, [], [['id' => $extra->id]]),
        ], 0, 0, 0.0, 0.0);
        $out = $this->service->calculateOrder($req, $this->couponService);

        // (10 + 1.2) * 2 = 22.40, tax 2.24, total 24.64
        $this->assertSame(22.40, $out->lines[0]->lineSubtotalExTax);
        $this->assertSame(2.24, $out->totalTax);
        $this->assertSame(24.64, $out->total);
    }

    public function test_cross_item_variation_rejected_when_guards_on(): void
    {
        $foreign = $this->makeItem(10.00, $this->tax10);
        $target = $this->makeItem(8.00, $this->tax10);
        $foreignVariation = $this->makeVariation($foreign, 5.00);

        $req = PricingRequest::forPos(1, $this->branch->id, [
            $this->line($target->id, 1, [['id' => $foreignVariation->id]]),
        ], 0, 0, 0.0, 0.0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/n'appartient pas/");
        $this->service->calculateOrder($req, $this->couponService);
    }

    public function test_cross_item_extra_rejected_when_guards_on(): void
    {
        $foreign = $this->makeItem(10.00, $this->tax10);
        $target = $this->makeItem(8.00, $this->tax10);
        $foreignExtra = $this->makeExtra($foreign, 1.50);

        $req = PricingRequest::forPos(1, $this->branch->id, [
            $this->line($target->id, 1, [], [['id' => $foreignExtra->id]]),
        ], 0, 0, 0.0, 0.0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/n'appartient pas/");
        $this->service->calculateOrder($req, $this->couponService);
    }

    public function test_cross_item_variation_allowed_when_guards_off(): void
    {
        $foreign = $this->makeItem(10.00, $this->tax10);
        $target = $this->makeItem(8.00, $this->tax10);
        $foreignVariation = $this->makeVariation($foreign, 5.00);

        $req = PricingRequest::forWeb(
            1,
            $this->branch->id,
            [$this->line($target->id, 1, [['id' => $foreignVariation->id]])],
            0,
            0,
            0.0
        );
        $out = $this->service->calculateOrder($req, $this->couponService);

        $this->assertEqualsWithDelta(13.00, $out->lines[0]->lineSubtotalExTax, 0.0001);
    }

    public function test_unknown_item_id_is_rejected(): void
    {
        $req = PricingRequest::forPos(1, $this->branch->id, [
            $this->line(999999, 1),
        ], 0, 0, 0.0, 0.0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/introuvable/');
        $this->service->calculateOrder($req, $this->couponService);
    }

    public function test_unknown_variation_is_rejected(): void
    {
        $item = $this->makeItem(10.00, $this->tax10);

        $req = PricingRequest::forPos(1, $this->branch->id, [
            $this->line($item->id, 1, [['id' => 999999]]),
        ], 0, 0, 0.0, 0.0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Variation ID.*introuvable/');
        $this->service->calculateOrder($req, $this->couponService);
    }

    /* -----------------------------------------------------------------
     * Discount stacking rules
     * ---------------------------------------------------------------*/

    public function test_manual_discount_applied_in_pos_context(): void
    {
        $item = $this->makeItem(10.00, $this->tax10);

        $req = PricingRequest::forPos(
            1,
            $this->branch->id,
            [$this->line($item->id, 1)],
            0,
            0,
            2.00,
            0.0
        );
        $out = $this->service->calculateOrder($req, $this->couponService);

        // 10 + 1 (tax) - 2 (discount) = 9.00
        $this->assertSame(2.00, $out->discount);
        $this->assertSame(9.00, $out->total);
    }

    public function test_manual_discount_ignored_in_web_context(): void
    {
        $item = $this->makeItem(10.00, $this->tax10);

        // forWeb() hardcodes manualDiscountRequest = 0 anyway. This test verifies
        // that even if a payload somehow smuggles one, context='web' declines it.
        $reqWebWithManual = new PricingRequest(
            orderId: 1,
            branchId: $this->branch->id,
            requestItems: [$this->line($item->id, 1)],
            context: 'web',
            couponId: 0,
            couponCustomerUserId: 0,
            manualDiscountRequest: 5.00,
            deliveryCharge: 0.0,
            enforceCrossItemGuards: false,
            roundLineTotals: false,
            roundLineTax: false,
            roundOrderTotalTax: false,
            roundFinalOrderTotal: false,
            roundSubtotal: false,
        );
        $out = $this->service->calculateOrder($reqWebWithManual, $this->couponService);

        $this->assertSame(0.0, $out->discount);
    }

    public function test_manual_discount_greater_than_subtotal_becomes_zero(): void
    {
        $item = $this->makeItem(10.00, $this->tax10);

        $req = PricingRequest::forPos(1, $this->branch->id, [$this->line($item->id, 1)], 0, 0, 50.0, 0.0);
        $out = $this->service->calculateOrder($req, $this->couponService);

        $this->assertSame(0.0, $out->discount);
    }

    public function test_coupon_discount_takes_precedence_over_manual(): void
    {
        $item = $this->makeItem(50.00, $this->tax10);

        $coupon = Coupon::factory()->create([
            'code' => 'TEST10',
            'discount' => 10.0,
            'discount_type' => DiscountType::PERCENTAGE,
            'minimum_order' => 0,
            'maximum_discount' => 0,
            'start_date' => null,
            'end_date' => null,
        ]);

        $req = PricingRequest::forPos(
            1,
            $this->branch->id,
            [$this->line($item->id, 1)],
            $coupon->id,
            0,
            25.00,
            0.0
        );
        $out = $this->service->calculateOrder($req, $this->couponService);

        // Coupon 10% of 50 = 5.00. Manual discount 25 ignored because coupon has precedence.
        $this->assertSame(5.00, $out->discount);
    }

    public function test_delivery_charge_added_to_total_after_tax(): void
    {
        $item = $this->makeItem(10.00, $this->tax10);

        $req = PricingRequest::forPos(1, $this->branch->id, [$this->line($item->id, 1)], 0, 0, 0.0, 3.50);
        $out = $this->service->calculateOrder($req, $this->couponService);

        // 10 + 1 tax + 3.50 delivery = 14.50
        $this->assertSame(3.50, $out->deliveryCharge);
        $this->assertSame(14.50, $out->total);
    }

    public function test_total_never_goes_negative_even_if_discount_exceeds_subtotal_plus_tax(): void
    {
        $item = $this->makeItem(5.00, $this->tax10);

        $coupon = Coupon::factory()->create([
            'code' => 'BIG99',
            'discount' => 99.0,
            'discount_type' => DiscountType::PERCENTAGE,
            'minimum_order' => 0,
            'maximum_discount' => 100.00,
            'start_date' => null,
            'end_date' => null,
        ]);

        $req = PricingRequest::forPos(1, $this->branch->id, [$this->line($item->id, 1)], $coupon->id, 0, 0.0, 0.0);
        $out = $this->service->calculateOrder($req, $this->couponService);

        $this->assertGreaterThanOrEqual(0.0, $out->total);
    }

    /* -----------------------------------------------------------------
     * Insert-row contract
     * ---------------------------------------------------------------*/

    public function test_insert_rows_contain_branch_id_and_order_id(): void
    {
        $item = $this->makeItem(10.00, $this->tax10);

        $req = PricingRequest::forPos(42, $this->branch->id, [$this->line($item->id, 1)], 0, 0, 0.0, 0.0);
        $out = $this->service->calculateOrder($req, $this->couponService);

        $this->assertCount(1, $out->orderItemInsertRows);
        $row = $out->orderItemInsertRows[0];
        $this->assertSame(42, $row['order_id']);
        $this->assertSame($this->branch->id, $row['branch_id']);
        $this->assertSame($item->id, $row['item_id']);
        $this->assertSame(1, $row['quantity']);
        $this->assertSame('TVA 10%', $row['tax_name']);
        $this->assertSame(10.0, $row['tax_rate']);
        $this->assertSame(TaxType::PERCENTAGE, $row['tax_type']);
        $this->assertSame(1.0, $row['tax_amount']);
        $this->assertIsString($row['item_variations']); // serialized JSON
        $this->assertIsString($row['item_extras']);
    }

    /* -----------------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------------*/

    private function makeItem(float $price, Tax $tax): Item
    {
        return Item::factory()->create([
            'item_category_id' => $this->category->id,
            'tax_id' => $tax->id,
            'price' => $price,
            'status' => Status::ACTIVE,
        ]);
    }

    private function makeVariation(Item $item, float $price): ItemVariation
    {
        $attribute = ItemAttribute::query()->firstOrCreate(
            ['name' => 'Variation Test ' . uniqid()],
            [
                'item_category_id' => $this->category->id,
                'is_required' => 0,
                'max_selection' => 1,
            ]
        );

        return ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Variation ' . uniqid(),
            'price' => $price,
            'status' => Status::ACTIVE,
        ]);
    }

    private function makeExtra(Item $item, float $price): ItemExtra
    {
        return ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Extra ' . uniqid(),
            'price' => $price,
            'status' => Status::ACTIVE,
        ]);
    }

    /**
     * @param  array<int, array{id:int}>  $variations
     * @param  array<int, array{id:int}>  $extras
     */
    private function line(int $itemId, int $quantity, array $variations = [], array $extras = []): object
    {
        return (object) [
            'item_id' => $itemId,
            'quantity' => $quantity,
            'item_variations' => array_map(fn ($v) => (object) $v, $variations),
            'item_extras' => array_map(fn ($e) => (object) $e, $extras),
            'instruction' => null,
        ];
    }
}
