<?php

namespace Tests\Feature\Services\Pricing;

use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
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
 * [T05] Multi-quantity SSOT pricing — additive coverage.
 *
 * Ground truth for the "tacos 4 viandes" use case: PricingService MUST honour
 * an optional `quantity` field on each item_variations / item_extras entry,
 * defaulting to 1 (legacy single-select payloads remain bit-identical), and
 * MUST enforce per-attribute constraints (min_select / max_select / allow_repeat)
 * read from the `item_attributes` columns added in T01.
 *
 * Anti-regression: the Pricing SSOT contract for legacy payloads MUST NOT shift.
 */
class PricingServiceMultiQtyTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Tax $tax10;
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
        $this->category = ItemCategory::factory()->create([
            'name' => 'Tacos Test',
            'wizard_template' => 'tacos',
            'has_menu' => true,
        ]);

        $this->service = new PricingService();
        $this->couponService = app(CouponService::class);
    }

    /* -----------------------------------------------------------------
     * Backward compatibility (legacy single-select payloads)
     * ---------------------------------------------------------------*/

    public function test_legacy_single_variation_no_quantity_yields_baseline_price(): void
    {
        $item = $this->makeItem(10.0);
        $attr = $this->makeAttribute('Sauce', minSelect: 0, maxSelect: 1, allowRepeat: false);
        $variation = $this->makeVariation($item, $attr, 'Algerienne', 1.50);

        $req = PricingRequest::forPos(
            1,
            $this->branch->id,
            [$this->line($item->id, 1, [['id' => $variation->id]])],
            0,
            0,
            0.0,
            0.0
        );
        $out = $this->service->calculateOrder($req, $this->couponService);

        // 10 + 1.50 = 11.50 base ; tax 10% = 1.15
        $this->assertSame(11.50, $out->lines[0]->lineSubtotalExTax);
        $this->assertSame(1.15, $out->totalTax);
    }

    public function test_legacy_single_variation_with_explicit_quantity_one_is_identical(): void
    {
        $item = $this->makeItem(10.0);
        $attr = $this->makeAttribute('Sauce', minSelect: 0, maxSelect: 1, allowRepeat: false);
        $variation = $this->makeVariation($item, $attr, 'Algerienne', 1.50);

        $reqLegacy = PricingRequest::forPos(
            1,
            $this->branch->id,
            [$this->line($item->id, 1, [['id' => $variation->id]])],
            0, 0, 0.0, 0.0
        );
        $reqExplicit = PricingRequest::forPos(
            1,
            $this->branch->id,
            [$this->line($item->id, 1, [['id' => $variation->id, 'quantity' => 1]])],
            0, 0, 0.0, 0.0
        );

        $outLegacy = $this->service->calculateOrder($reqLegacy, $this->couponService);
        $outExplicit = $this->service->calculateOrder($reqExplicit, $this->couponService);

        $this->assertSame($outLegacy->total, $outExplicit->total, 'quantity:1 must be identical to legacy payload');
        $this->assertSame($outLegacy->totalTax, $outExplicit->totalTax);
    }

    /* -----------------------------------------------------------------
     * Multi-quantity (the "tacos 4 viandes" use case)
     * ---------------------------------------------------------------*/

    public function test_four_same_meats_allow_repeat_true(): void
    {
        $item = $this->makeItem(10.0);
        $attr = $this->makeAttribute('Viande', minSelect: 1, maxSelect: 4, allowRepeat: true);
        $kebab = $this->makeVariation($item, $attr, 'Kebab', 2.00);

        $req = PricingRequest::forPos(
            1,
            $this->branch->id,
            [$this->line($item->id, 1, [['id' => $kebab->id, 'quantity' => 4]])],
            0, 0, 0.0, 0.0
        );
        $out = $this->service->calculateOrder($req, $this->couponService);

        // 10 + (2.00 * 4) = 18.00
        $this->assertSame(18.0, $out->lines[0]->lineSubtotalExTax);
    }

    public function test_two_plus_two_mixed_meats(): void
    {
        $item = $this->makeItem(10.0);
        $attr = $this->makeAttribute('Viande', minSelect: 1, maxSelect: 4, allowRepeat: true);
        $kebab = $this->makeVariation($item, $attr, 'Kebab', 2.00);
        $merguez = $this->makeVariation($item, $attr, 'Merguez', 2.50);

        $req = PricingRequest::forPos(
            1,
            $this->branch->id,
            [$this->line($item->id, 1, [
                ['id' => $kebab->id, 'quantity' => 2],
                ['id' => $merguez->id, 'quantity' => 2],
            ])],
            0, 0, 0.0, 0.0
        );
        $out = $this->service->calculateOrder($req, $this->couponService);

        // 10 + (2.00 * 2) + (2.50 * 2) = 19.00
        $this->assertSame(19.0, $out->lines[0]->lineSubtotalExTax);
    }

    public function test_three_plus_one_mixed(): void
    {
        $item = $this->makeItem(10.0);
        $attr = $this->makeAttribute('Viande', minSelect: 1, maxSelect: 4, allowRepeat: true);
        $kebab = $this->makeVariation($item, $attr, 'Kebab', 2.00);
        $poulet = $this->makeVariation($item, $attr, 'Poulet', 1.80);

        $req = PricingRequest::forPos(
            1,
            $this->branch->id,
            [$this->line($item->id, 1, [
                ['id' => $kebab->id, 'quantity' => 3],
                ['id' => $poulet->id, 'quantity' => 1],
            ])],
            0, 0, 0.0, 0.0
        );
        $out = $this->service->calculateOrder($req, $this->couponService);

        // 10 + (2.00 * 3) + (1.80 * 1) = 17.80
        $this->assertSame(17.80, $out->lines[0]->lineSubtotalExTax);
    }

    /* -----------------------------------------------------------------
     * Constraints enforcement (422 violations)
     * ---------------------------------------------------------------*/

    public function test_violation_max_select_returns_422(): void
    {
        $item = $this->makeItem(10.0);
        $attr = $this->makeAttribute('Viande', minSelect: 1, maxSelect: 2, allowRepeat: true);
        $kebab = $this->makeVariation($item, $attr, 'Kebab', 2.00);

        $req = PricingRequest::forPos(
            1,
            $this->branch->id,
            [$this->line($item->id, 1, [['id' => $kebab->id, 'quantity' => 5]])],
            0, 0, 0.0, 0.0
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        $this->service->calculateOrder($req, $this->couponService);
    }

    public function test_violation_min_select_returns_422(): void
    {
        $item = $this->makeItem(10.0);
        $attr = $this->makeAttribute('Viande', minSelect: 2, maxSelect: 4, allowRepeat: true);
        $kebab = $this->makeVariation($item, $attr, 'Kebab', 2.00);

        $req = PricingRequest::forPos(
            1,
            $this->branch->id,
            [$this->line($item->id, 1, [['id' => $kebab->id, 'quantity' => 1]])],
            0, 0, 0.0, 0.0
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        $this->service->calculateOrder($req, $this->couponService);
    }

    public function test_violation_allow_repeat_false(): void
    {
        $item = $this->makeItem(10.0);
        $attr = $this->makeAttribute('Sauce', minSelect: 0, maxSelect: 4, allowRepeat: false);
        $alg = $this->makeVariation($item, $attr, 'Algerienne', 1.0);

        $req = PricingRequest::forPos(
            1,
            $this->branch->id,
            [$this->line($item->id, 1, [['id' => $alg->id, 'quantity' => 2]])],
            0, 0, 0.0, 0.0
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        $this->service->calculateOrder($req, $this->couponService);
    }

    /* -----------------------------------------------------------------
     * Extras multi-quantity
     * ---------------------------------------------------------------*/

    public function test_extras_with_quantity_field_multiplies_price(): void
    {
        $item = $this->makeItem(8.0);
        $cheese = $this->makeExtra($item, 'Cheddar', 1.00);

        $req = PricingRequest::forPos(
            1,
            $this->branch->id,
            [$this->line($item->id, 1, [], [['id' => $cheese->id, 'quantity' => 3]])],
            0, 0, 0.0, 0.0
        );
        $out = $this->service->calculateOrder($req, $this->couponService);

        // 8 + (1.00 * 3) = 11.00
        $this->assertSame(11.0, $out->lines[0]->lineSubtotalExTax);
    }

    /* -----------------------------------------------------------------
     * SSOT path — composition_snapshot persistence sentinels
     * (added after audit V1 found the snapshot was NOT written on the
     *  default PRICING_USE_SSOT=true path → NF525 immutability not enforced)
     * ---------------------------------------------------------------*/

    public function test_ssot_path_emits_composition_snapshot_in_insert_rows(): void
    {
        $item = $this->makeItem(10.0);
        $attr = $this->makeAttribute('Viande', minSelect: 1, maxSelect: 4, allowRepeat: true);
        $kebab = $this->makeVariation($item, $attr, 'Kebab', 2.00);

        $req = PricingRequest::forPos(
            1,
            $this->branch->id,
            [$this->line($item->id, 1, [['id' => $kebab->id, 'quantity' => 4]])],
            0, 0, 0.0, 0.0
        );
        $out = $this->service->calculateOrder($req, $this->couponService);

        $this->assertCount(1, $out->orderItemInsertRows, 'SSOT must yield exactly 1 insert row');
        $row = $out->orderItemInsertRows[0];
        $this->assertArrayHasKey('composition_snapshot', $row,
            'SSOT path must persist composition_snapshot on order_items (NF525).');
        $this->assertIsString($row['composition_snapshot'], 'composition_snapshot must be json_encoded for mass insert.');

        $snap = json_decode($row['composition_snapshot'], true);
        $this->assertIsArray($snap);
        $this->assertSame(1, $snap['schema_version']);
        $this->assertCount(1, $snap['lines']);
        $this->assertSame('Kebab', $snap['lines'][0]['variation_name']);
        $this->assertSame(4, $snap['lines'][0]['quantity']);
        $this->assertEquals(2.0, (float) $snap['lines'][0]['unit_price']);
        $this->assertEquals(8.0, (float) $snap['lines'][0]['line_total']);
    }

    public function test_ssot_snapshot_includes_attribute_name_and_extras(): void
    {
        $item = $this->makeItem(10.0);
        $viande = $this->makeAttribute('Viande', minSelect: 0, maxSelect: 4, allowRepeat: true);
        $sauce = $this->makeAttribute('Sauce', minSelect: 0, maxSelect: 1, allowRepeat: false);
        $steak = $this->makeVariation($item, $viande, 'Steak', 3.00);
        $algerienne = $this->makeVariation($item, $sauce, 'Algerienne', 0.0);
        $cheddar = $this->makeExtra($item, 'Cheddar', 1.00);

        $req = PricingRequest::forPos(
            1,
            $this->branch->id,
            [$this->line(
                $item->id,
                1,
                [
                    ['id' => $steak->id, 'quantity' => 2],
                    ['id' => $algerienne->id],
                ],
                [['id' => $cheddar->id, 'quantity' => 2]]
            )],
            0, 0, 0.0, 0.0
        );
        $out = $this->service->calculateOrder($req, $this->couponService);

        $snap = json_decode($out->orderItemInsertRows[0]['composition_snapshot'], true);
        $this->assertCount(2, $snap['lines']);
        $this->assertCount(1, $snap['extras']);
        $names = array_column($snap['lines'], 'variation_name');
        $this->assertContains('Steak', $names);
        $this->assertContains('Algerienne', $names);
        $attrNames = array_column($snap['lines'], 'attribute_name');
        $this->assertTrue(collect($attrNames)->contains(fn ($n) => str_starts_with((string) $n, 'Viande ')));
        $this->assertTrue(collect($attrNames)->contains(fn ($n) => str_starts_with((string) $n, 'Sauce ')));
        $this->assertSame('Cheddar', $snap['extras'][0]['extra_name']);
        $this->assertSame(2, $snap['extras'][0]['quantity']);
    }

    public function test_ssot_legacy_payload_without_quantity_still_emits_snapshot(): void
    {
        $item = $this->makeItem(10.0);
        $attr = $this->makeAttribute('Sauce', minSelect: 0, maxSelect: 1, allowRepeat: false);
        $algerienne = $this->makeVariation($item, $attr, 'Algerienne', 1.50);

        $req = PricingRequest::forPos(
            1,
            $this->branch->id,
            [$this->line($item->id, 1, [['id' => $algerienne->id]])],
            0, 0, 0.0, 0.0
        );
        $out = $this->service->calculateOrder($req, $this->couponService);

        $snap = json_decode($out->orderItemInsertRows[0]['composition_snapshot'], true);
        $this->assertSame(1, $snap['lines'][0]['quantity'], 'legacy payload (no quantity) must default to 1 in snapshot too.');
        $this->assertEquals(1.5, (float) $snap['lines'][0]['unit_price']);
        $this->assertEquals(1.5, (float) $snap['lines'][0]['line_total']);
    }

    /* -----------------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------------*/

    private function makeItem(float $price): Item
    {
        return Item::factory()->create([
            'item_category_id' => $this->category->id,
            'tax_id' => $this->tax10->id,
            'price' => $price,
            'status' => Status::ACTIVE,
        ]);
    }

    private function makeAttribute(string $name, int $minSelect, int $maxSelect, bool $allowRepeat): ItemAttribute
    {
        return ItemAttribute::query()->create([
            'name' => $name . ' ' . uniqid(),
            'status' => Status::ACTIVE,
            'min_select' => $minSelect,
            'max_select' => $maxSelect,
            'allow_repeat' => $allowRepeat,
        ]);
    }

    private function makeVariation(Item $item, ItemAttribute $attr, string $name, float $price): ItemVariation
    {
        return ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attr->id,
            'name' => $name,
            'price' => $price,
            'new_price' => $price,
            'status' => Status::ACTIVE,
        ]);
    }

    private function makeExtra(Item $item, string $name, float $price): ItemExtra
    {
        return ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => $name,
            'price' => $price,
            'new_price' => $price,
            'status' => Status::ACTIVE,
        ]);
    }

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
