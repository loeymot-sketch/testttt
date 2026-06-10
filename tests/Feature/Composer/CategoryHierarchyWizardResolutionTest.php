<?php

namespace Tests\Feature\Composer;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Services\Menu\MenuProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL CMS GESTION 2026-06-10 — G-0c (défaut owner appliqué : PAS d'héritage)]
 *
 * Caractérisation : le wizard d'une catégorie PARENTE ne s'applique PAS aux
 * items d'une SOUS-catégorie (résolution composer = match EXACT sur
 * item_category_id). Déplacer un item vers une sous-catégorie le DÉTACHE de
 * fait du wizard parent — comportement assumé (hint UI dans le form catégorie),
 * PINNÉ ici pour qu'un héritage futur soit un choix explicite, pas une dérive.
 */
class CategoryHierarchyWizardResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_category_wizard_does_not_apply_to_subcategory_items(): void
    {
        $parent = ItemCategory::factory()->create(['status' => 5]);
        $child = ItemCategory::factory()->create(['status' => 5, 'parent_id' => $parent->id]);

        $profile = ItemWizardProfile::factory()->create([
            'item_id'          => null,
            'item_category_id' => $parent->id,
            'is_published'     => true,
        ]);
        ItemWizardStep::factory()->create([
            'profile_id'  => $profile->id,
            'step_key'    => 'sauce',
            'label'       => 'Sauces',
            'source_type' => 'extra_group',
            'source_ref'  => 'sauce',
            'position'    => 1,
        ]);

        $itemInParent = Item::factory()->create(['item_category_id' => $parent->id, 'status' => 5]);
        $itemInChild = Item::factory()->create(['item_category_id' => $child->id, 'status' => 5]);

        $projection = app(MenuProjectionService::class)->forChannel('kiosk', 1);

        $found = [];
        foreach ($projection['categories'] as $category) {
            foreach ($category['items'] as $item) {
                $found[$item['id']] = $item['composer_profile'];
            }
        }

        $this->assertArrayHasKey($itemInParent->id, $found);
        $this->assertArrayHasKey($itemInChild->id, $found);

        $this->assertNotNull(
            $found[$itemInParent->id],
            'item in the parent category must resolve the category wizard'
        );
        $this->assertNull(
            $found[$itemInChild->id],
            'G-0c default: item in a SUB-category must NOT inherit the parent category wizard'
        );
    }
}
