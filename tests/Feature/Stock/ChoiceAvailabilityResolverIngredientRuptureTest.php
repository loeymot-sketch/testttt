<?php

namespace Tests\Feature\Stock;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemExtra;
use App\Models\StockLevel;
use App\Services\Stock\ChoiceAvailabilityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ChoiceAvailabilityResolverIngredientRuptureTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_ingredient_rupture_reason_when_extra_is_unavailable(): void
    {
        [$branch, $item, $extra] = $this->itemWithExtra(isAvailable: false);
        $this->stockExtra($branch, $extra, onHand: 10);

        $snapshot = app(ChoiceAvailabilityResolver::class)->snapshotForItem($item->fresh(['extras']), (int) $branch->id);

        $this->assertFalse($snapshot['extras'][$extra->id]['is_available']);
        $this->assertSame('ingredient_rupture', $snapshot['extras'][$extra->id]['unavailable_reason']);
    }

    public function test_stock_rupture_takes_precedence_when_extra_is_available_and_stock_is_zero(): void
    {
        [$branch, $item, $extra] = $this->itemWithExtra(isAvailable: true);
        $this->stockExtra($branch, $extra, onHand: 0);

        $snapshot = app(ChoiceAvailabilityResolver::class)->snapshotForItem($item->fresh(['extras']), (int) $branch->id);

        $this->assertFalse($snapshot['extras'][$extra->id]['is_available']);
        $this->assertSame('stock_rupture', $snapshot['extras'][$extra->id]['unavailable_reason']);
    }

    public function test_assert_selections_orderable_throws_when_extra_is_ingredient_unavailable(): void
    {
        [$branch, , $extra] = $this->itemWithExtra(isAvailable: false);
        $this->stockExtra($branch, $extra, onHand: 10);

        $this->expectException(InvalidArgumentException::class);
        // [DEEP-R2] message UX : nom lisible client au lieu de l'ID technique.
        $this->expectExceptionMessage('n\'est plus disponible');

        app(ChoiceAvailabilityResolver::class)->assertSelectionsOrderable(
            (int) $branch->id,
            collect(),
            collect([$extra]),
            collect(),
            'kiosk',
            false
        );
    }

    private function itemWithExtra(bool $isAvailable): array
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $extra = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Sauce blanche',
            'price' => 0.50,
            'status' => Status::ACTIVE,
            'group_label' => 'sauces',
            'is_available' => $isAvailable,
            'unavailable_reason' => $isAvailable ? null : 'rupture',
        ]);

        return [$branch, $item, $extra];
    }

    private function stockExtra(Branch $branch, ItemExtra $extra, int $onHand): void
    {
        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => ItemExtra::class,
            'stockable_id' => $extra->id,
            'on_hand' => $onHand,
            'reserved' => 0,
            'threshold_low' => 2,
        ]);
    }
}
