<?php

namespace Tests\Feature\Stock;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemVariation;
use App\Models\StockLevel;
use App\Services\Stock\ChoiceAvailabilityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ChoiceAvailabilityResolverVariationIngredientRuptureTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_available_when_attribute_is_available_and_stock_exists(): void
    {
        [$branch, $item, $variation] = $this->itemWithVariation(attributeAvailable: true);
        $this->stockVariation($branch, $variation, onHand: 10);

        $snapshot = app(ChoiceAvailabilityResolver::class)->snapshotForItem($item->fresh(['variations']), (int) $branch->id);

        $this->assertTrue($snapshot['variations'][$variation->id]['is_available']);
        $this->assertNull($snapshot['variations'][$variation->id]['unavailable_reason']);
    }

    public function test_it_returns_ingredient_rupture_when_attribute_is_unavailable_regardless_of_stock(): void
    {
        [$branch, $item, $variation] = $this->itemWithVariation(attributeAvailable: false);
        $this->stockVariation($branch, $variation, onHand: 10);

        $snapshot = app(ChoiceAvailabilityResolver::class)->snapshotForItem($item->fresh(['variations']), (int) $branch->id);

        $this->assertFalse($snapshot['variations'][$variation->id]['is_available']);
        $this->assertSame('ingredient_rupture', $snapshot['variations'][$variation->id]['unavailable_reason']);
    }

    public function test_stock_rupture_applies_when_attribute_is_available_and_stock_is_zero(): void
    {
        [$branch, $item, $variation] = $this->itemWithVariation(attributeAvailable: true);
        $this->stockVariation($branch, $variation, onHand: 0);

        $snapshot = app(ChoiceAvailabilityResolver::class)->snapshotForItem($item->fresh(['variations']), (int) $branch->id);

        $this->assertFalse($snapshot['variations'][$variation->id]['is_available']);
        $this->assertSame('stock_rupture', $snapshot['variations'][$variation->id]['unavailable_reason']);
    }

    public function test_variation_without_attribute_keeps_legacy_stock_level_behavior(): void
    {
        [$branch, $item, $variation] = $this->itemWithVariation(attributeAvailable: true);
        $this->stockVariation($branch, $variation, onHand: 0);
        $variation = $variation->fresh();
        $variation->setRelation('itemAttribute', null);
        $item->setRelation('variations', collect([$variation]));

        $snapshot = app(ChoiceAvailabilityResolver::class)->snapshotForItem($item, (int) $branch->id);

        $this->assertFalse($snapshot['variations'][$variation->id]['is_available']);
        $this->assertSame('stock_rupture', $snapshot['variations'][$variation->id]['unavailable_reason']);
    }

    public function test_assert_selections_orderable_throws_when_variation_attribute_is_unavailable(): void
    {
        [$branch, , $variation] = $this->itemWithVariation(attributeAvailable: false);
        $this->stockVariation($branch, $variation, onHand: 10);

        try {
            app(ChoiceAvailabilityResolver::class)->assertSelectionsOrderable(
                (int) $branch->id,
                collect([$variation->fresh('itemAttribute')]),
                collect(),
                collect(),
                'kiosk',
                false
            );

            $this->fail('Expected unavailable variation to throw.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(422, $exception->getCode());
            $this->assertStringContainsString('ingredient_rupture', $exception->getMessage());
        }
    }

    private function itemWithVariation(bool $attributeAvailable): array
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $attribute = ItemAttribute::factory()->create([
            'name' => 'Viande',
            'is_available' => $attributeAvailable,
            'unavailable_reason' => $attributeAvailable ? null : 'rupture',
        ]);
        $variation = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Boeuf',
            'price' => 0,
            'caution' => null,
            'status' => Status::ACTIVE,
            'visible_on' => null,
        ]);

        return [$branch, $item, $variation];
    }

    private function stockVariation(Branch $branch, ItemVariation $variation, int $onHand): void
    {
        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => ItemVariation::class,
            'stockable_id' => $variation->id,
            'on_hand' => $onHand,
            'reserved' => 0,
            'threshold_low' => 2,
        ]);
    }
}
