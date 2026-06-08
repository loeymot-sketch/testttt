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

    /**
     * [GOAL_WIZARD_DYNAMIC_BUILDER Wave 2] The projection emits per-option
     * image + description (non-fiscal catalog metadata). PRICE is DELIBERATELY
     * EXCLUDED from the composer_profile — the wizard joins price by choice id
     * from the item's variations/extras payload (NF525 anti-duplication invariant,
     * MenuProjectionComposerProfileTest::assertNoPriceKeys). This test locks both:
     * media present AND price absent.
     */
    public function test_choice_payload_emits_media_without_price_for_variation_and_extra(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $profile = ItemWizardProfile::factory()->create(['item_id' => $item->id]);
        $attribute = ItemAttribute::factory()->create(['name' => 'Viande', 'status' => Status::ACTIVE]);

        $variation = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Poulet',
            'price' => 1.50,
            'status' => Status::ACTIVE,
            'visible_on' => null,
            'description' => 'Poulet mariné',
            'image_path' => 'https://cdn.example.test/poulet.png',
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'viande',
            'label' => 'Viande',
            'source_type' => 'item_attribute',
            'source_ref' => (string) $attribute->id,
            'stockable_choices' => false,
            'position' => 1,
        ]);
        $extra = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Cheddar',
            'price' => 0.80,
            'status' => Status::ACTIVE,
            'group_label' => 'sup',
            'description' => 'Cheddar fondu',
            'image_path' => 'https://cdn.example.test/cheddar.png',
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'sup',
            'label' => 'Suppléments',
            'source_type' => 'extra_group',
            'source_ref' => 'sup',
            'stockable_choices' => false,
            'position' => 2,
        ]);

        $projection = app(ComposerProfileProjection::class)->project(
            $profile->fresh('steps'),
            $item->fresh(['variations', 'extras', 'addons']),
            'kiosk',
            (int) $branch->id
        );
        $vChoice = $projection['steps'][0]['choices'][0];
        $eChoice = $projection['steps'][1]['choices'][0];

        $this->assertSame('https://cdn.example.test/poulet.png', $vChoice['image']);
        $this->assertSame('Poulet mariné', $vChoice['description']);
        $this->assertSame('https://cdn.example.test/cheddar.png', $eChoice['image']);
        $this->assertSame('Cheddar fondu', $eChoice['description']);

        // NF525 anti-duplication: NO price keys leak into the composer_profile.
        foreach (['price', 'convert_price', 'currency_price', 'flat_price'] as $priceKey) {
            $this->assertArrayNotHasKey($priceKey, $vChoice);
            $this->assertArrayNotHasKey($priceKey, $eChoice);
        }
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
