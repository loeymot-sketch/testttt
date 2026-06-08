<?php

namespace Tests\Feature\Composer;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * [GOAL_WIZARD_DYNAMIC_BUILDER Wave 4 — T-4.2.1] The provisioning command turns
 * a real category into a published, turnkey-bound wizard — picking the RICHEST
 * item as source representative (not the first, which may be an outlier).
 */
class ProvisionCayenneWizardsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisions_published_bound_wizard_using_richest_representative(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Tacos', 'status' => Status::ACTIVE]);

        // Poor item FIRST (no attributes) — the outlier that the old first() picked.
        Item::factory()->create(['item_category_id' => $category->id, 'name' => 'Tacos Sec', 'status' => Status::ACTIVE]);

        // Rich item with the real category attributes.
        $rich = Item::factory()->create(['item_category_id' => $category->id, 'name' => 'Tacos Complet', 'status' => Status::ACTIVE]);
        $viande = ItemAttribute::factory()->create(['name' => 'Viande 1', 'status' => Status::ACTIVE]);
        $sauce = ItemAttribute::factory()->create(['name' => 'Sauce (1ère Gratuite)', 'status' => Status::ACTIVE]);
        foreach (['Poulet', 'Boeuf'] as $n) {
            ItemVariation::query()->create(['item_id' => $rich->id, 'item_attribute_id' => $viande->id, 'name' => $n, 'price' => 0, 'status' => Status::ACTIVE]);
        }
        foreach (['Algérienne', 'Blanche'] as $n) {
            ItemVariation::query()->create(['item_id' => $rich->id, 'item_attribute_id' => $sauce->id, 'name' => $n, 'price' => 0, 'status' => Status::ACTIVE]);
        }

        $code = Artisan::call('foodking:provision-cayenne-wizards');
        $this->assertSame(0, $code);

        $profile = ItemWizardProfile::query()
            ->whereNull('item_id')
            ->where('item_category_id', $category->id)
            ->where('is_published', true)
            ->with('steps')
            ->first();

        $this->assertNotNull($profile, 'a published category wizard should be provisioned');

        $steps = $profile->steps->keyBy('step_key');
        // Bound to the RICH item's real attributes (proves richest-representative pick).
        $this->assertSame('Viande 1', $steps['viande']->source_ref);
        $this->assertTrue((bool) $steps['viande']->is_active);
        $this->assertSame('Sauce (1ère Gratuite)', $steps['sauce']->source_ref);
        // "taille" has no attribute -> deactivated, never match-all.
        $this->assertFalse((bool) $steps['taille']->is_active);
    }

    public function test_is_idempotent_skips_already_published(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Tacos', 'status' => Status::ACTIVE]);
        $item = Item::factory()->create(['item_category_id' => $category->id, 'status' => Status::ACTIVE]);
        $attr = ItemAttribute::factory()->create(['name' => 'Viande 1', 'status' => Status::ACTIVE]);
        ItemVariation::query()->create(['item_id' => $item->id, 'item_attribute_id' => $attr->id, 'name' => 'Poulet', 'price' => 0, 'status' => Status::ACTIVE]);

        Artisan::call('foodking:provision-cayenne-wizards');
        Artisan::call('foodking:provision-cayenne-wizards'); // second run

        $count = ItemWizardProfile::query()
            ->whereNull('item_id')->where('item_category_id', $category->id)->where('is_published', true)->count();
        $this->assertSame(1, $count, 'second run must not create a duplicate published wizard');
    }
}
