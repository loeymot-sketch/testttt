<?php

namespace Tests\Feature\Order;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemVariation;
use App\Models\StockLevel;
use App\Services\CouponService;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Ultra-audit R1: every order path that uses PricingService::calculateOrder re-validates
 * choice/stock/ingredient availability on submit (assertOptionsOrderable → assertSelectionsOrderable).
 *
 * This is a contract test: if calculateOrder did not call the resolver, this would pass incorrectly.
 */
class SubmitRevalidatesChoiceAvailabilityThroughPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculate_order_rejects_unavailable_ingredient_on_pos_submit_path(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        [$branch, $item, $variation] = $this->itemWithVariation(attributeAvailable: false);
        $this->stockVariation($branch, $variation, onHand: 10);

        $line = (object) [
            'item_id' => $item->id,
            'quantity' => 1,
            'item_variations' => [
                (object) ['id' => $variation->id, 'quantity' => 1],
            ],
            'item_extras' => [],
            'instruction' => null,
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(422);

        (new PricingService())->calculateOrder(
            PricingRequest::forPos(0, (int) $branch->id, [$line], 0, 0, 0.0, 0.0),
            app(CouponService::class)
        );
    }

    /**
     * @return array{0: Branch, 1: Item, 2: ItemVariation}
     */
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
