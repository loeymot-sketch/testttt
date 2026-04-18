<?php

namespace Tests\Feature\Orders;

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

class CrossItemGuardTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private ItemCategory $category;
    private Tax $tax;
    private PricingService $pricingService;
    private CouponService $couponService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->branch = Branch::factory()->create();
        $this->tax = Tax::factory()->create([
            'name' => 'TVA 10%',
            'code' => 'TVA10',
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 10.0,
            'status' => Status::ACTIVE,
        ]);
        $this->category = ItemCategory::factory()->create([
            'name' => 'Cross Item Category',
            'wizard_template' => 'tacos',
            'has_menu' => true,
        ]);

        $this->pricingService = new PricingService();
        $this->couponService = app(CouponService::class);
    }

    public function test_pos_pricing_request_rejects_forbidden_item_combo(): void
    {
        [$target, , $foreignVariation] = $this->foreignVariationFixture();

        $request = PricingRequest::forPos(
            1,
            $this->branch->id,
            [$this->line($target->id, 1, [['id' => $foreignVariation->id]])],
            0,
            0,
            0.0,
            0.0
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/n'appartient pas/");
        $this->pricingService->calculateOrder($request, $this->couponService);
    }

    public function test_table_and_web_also_enforce(): void
    {
        [$target, $foreignExtra] = $this->foreignExtraFixture();

        $tableRequest = PricingRequest::forTable(
            1,
            $this->branch->id,
            [$this->line($target->id, 1, [], [['id' => $foreignExtra->id]])],
            0,
            0,
            0.0,
            0.0
        );

        try {
            $this->pricingService->calculateOrder($tableRequest, $this->couponService);
            $this->fail('Expected table pricing request to reject a foreign extra.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertMatchesRegularExpression("/n'appartient pas/", $exception->getMessage());
        }

        $webRequest = PricingRequest::forWeb(
            1,
            $this->branch->id,
            [$this->line($target->id, 1, [], [['id' => $foreignExtra->id]])],
            0,
            0,
            0.0
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/n'appartient pas/");
        $this->pricingService->calculateOrder($webRequest, $this->couponService);
    }

    /**
     * @return array{0: Item, 1: Item, 2: ItemVariation}
     */
    private function foreignVariationFixture(): array
    {
        $foreign = $this->makeItem(10.00);
        $target = $this->makeItem(8.00);

        return [$target, $foreign, $this->makeVariation($foreign, 5.00)];
    }

    /**
     * @return array{0: Item, 1: ItemExtra}
     */
    private function foreignExtraFixture(): array
    {
        [$target, $foreign] = $this->foreignVariationFixture();
        $foreignExtra = $this->makeExtra($foreign, 1.50);

        return [$target, $foreignExtra];
    }

    private function makeItem(float $price): Item
    {
        return Item::factory()->create([
            'item_category_id' => $this->category->id,
            'tax_id' => $this->tax->id,
            'price' => $price,
            'status' => Status::ACTIVE,
        ]);
    }

    private function makeVariation(Item $item, float $price): ItemVariation
    {
        $attribute = ItemAttribute::query()->firstOrCreate(
            ['name' => 'CrossItem Variation ' . uniqid()],
            [
                'item_category_id' => $this->category->id,
                'is_required' => 0,
                'max_selection' => 1,
            ]
        );

        return ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Large',
            'price' => $price,
            'status' => Status::ACTIVE,
        ]);
    }

    private function makeExtra(Item $item, float $price): ItemExtra
    {
        return ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Cheese',
            'price' => $price,
            'status' => Status::ACTIVE,
        ]);
    }

    /**
     * @param  array<int, array{id:int}>  $variations
     * @param  array<int, array{id:int}>  $extras
     * @return object
     */
    private function line(int $itemId, int $quantity, array $variations = [], array $extras = []): object
    {
        return (object) [
            'item_id' => $itemId,
            'quantity' => $quantity,
            'item_variations' => array_map(fn ($variation) => (object) $variation, $variations),
            'item_extras' => array_map(fn ($extra) => (object) $extra, $extras),
        ];
    }
}
