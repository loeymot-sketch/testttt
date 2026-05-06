<?php

namespace Tests\Feature\Composer;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\StockLevel;
use App\Services\Composer\ComposerProfileProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComposerProfileProjectionVariationRuptureTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_attribute_stockable_step_projects_attribute_rupture(): void
    {
        [$branch, $item, $profile, $variation] = $this->profileWithVariationStep(stockableChoices: true, attributeAvailable: false);
        $this->stockVariation($branch, $variation, onHand: 10);

        $choice = $this->firstChoice($profile, $item, $branch);

        $this->assertSame($variation->id, $choice['id']);
        $this->assertFalse($choice['is_available']);
        $this->assertSame('ingredient_rupture', $choice['unavailable_reason']);
    }

    public function test_item_attribute_non_stockable_step_projects_attribute_rupture(): void
    {
        [$branch, $item, $profile, $variation] = $this->profileWithVariationStep(stockableChoices: false, attributeAvailable: false);
        $this->stockVariation($branch, $variation, onHand: 10);

        $choice = $this->firstChoice($profile, $item, $branch);

        $this->assertSame($variation->id, $choice['id']);
        $this->assertFalse($choice['is_available']);
        $this->assertSame('ingredient_rupture', $choice['unavailable_reason']);
    }

    public function test_extra_group_non_stockable_step_projects_ingredient_rupture(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $profile = ItemWizardProfile::factory()->create(['item_id' => $item->id]);
        $extra = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Sauce blanche',
            'price' => 0.50,
            'status' => Status::ACTIVE,
            'group_label' => 'sauces',
            'is_available' => false,
            'unavailable_reason' => 'rupture',
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'sauce',
            'label' => 'Sauce',
            'source_type' => 'extra_group',
            'source_ref' => 'sauces',
            'stockable_choices' => false,
            'position' => 1,
        ]);

        $choice = $this->firstChoice($profile, $item->fresh(['extras']), $branch);

        $this->assertSame($extra->id, $choice['id']);
        $this->assertFalse($choice['is_available']);
        $this->assertSame('ingredient_rupture', $choice['unavailable_reason']);
    }

    private function profileWithVariationStep(bool $stockableChoices, bool $attributeAvailable): array
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $profile = ItemWizardProfile::factory()->create(['item_id' => $item->id]);
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
        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'viande',
            'label' => 'Viande',
            'source_type' => 'item_attribute',
            'source_ref' => (string) $attribute->id,
            'stockable_choices' => $stockableChoices,
            'position' => 1,
        ]);

        return [$branch, $item, $profile, $variation];
    }

    private function firstChoice(ItemWizardProfile $profile, Item $item, Branch $branch): array
    {
        $projection = app(ComposerProfileProjection::class)->project(
            $profile->fresh('steps'),
            $item->fresh(['variations', 'extras', 'addons']),
            'kiosk',
            (int) $branch->id
        );

        return $projection['steps'][0]['choices'][0];
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
