<?php

namespace Tests\Feature\Composer;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Services\Composer\ComposerTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * [GOAL_WIZARD_DYNAMIC_BUILDER Wave 4] Turnkey template binding.
 *
 * Applying a template now binds each item_attribute / extra_group step's
 * source_ref to the item's REAL constructs by name — so "apply template -> works"
 * is true out of the box (no more empty-source_ref match-all "ça fonctionne pas").
 * Attribute steps that fit no attribute are DEACTIVATED, not left match-all.
 */
class ComposerTemplateTurnkeyBindingTest extends TestCase
{
    use RefreshDatabase;

    private function steps(array $payload): Collection
    {
        return collect($payload['steps'])->keyBy('step_key');
    }

    public function test_tacos_template_binds_real_attributes_and_deactivates_unmatched(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $viande = ItemAttribute::factory()->create(['name' => 'Viande 1', 'status' => Status::ACTIVE]);
        $sauce = ItemAttribute::factory()->create(['name' => 'Sauce (1ère Gratuite)', 'status' => Status::ACTIVE]);
        foreach (['Poulet', 'Boeuf'] as $n) {
            ItemVariation::query()->create(['item_id' => $item->id, 'item_attribute_id' => $viande->id, 'name' => $n, 'price' => 0, 'status' => Status::ACTIVE]);
        }
        foreach (['Algérienne', 'Blanche'] as $n) {
            ItemVariation::query()->create(['item_id' => $item->id, 'item_attribute_id' => $sauce->id, 'name' => $n, 'price' => 0, 'status' => Status::ACTIVE]);
        }
        ItemExtra::query()->create(['item_id' => $item->id, 'name' => 'Salade', 'price' => 0, 'status' => Status::ACTIVE, 'group_label' => 'Garnitures']);

        $payload = app(ComposerTemplateService::class)->buildPayload('tacos', $item->fresh());
        $steps = $this->steps($payload);

        // Matched attribute steps -> bound to the REAL attribute name, active.
        $this->assertSame('Viande 1', $steps['viande']['source_ref']);
        $this->assertTrue($steps['viande']['is_active']);
        $this->assertSame('Sauce (1ère Gratuite)', $steps['sauce']['source_ref']);
        $this->assertTrue($steps['sauce']['is_active']);

        // No "taille" attribute on a tacos -> step kept but DEACTIVATED (never match-all).
        $this->assertSame('', $steps['taille']['source_ref']);
        $this->assertFalse($steps['taille']['is_active']);

        // Matched extra group -> bound to the real group_label.
        $this->assertSame('Garnitures', $steps['garnitures']['source_ref']);

        // Addon step untouched (addon_role drives it).
        $this->assertSame('menu_component', $steps['menu']['addon_role']);
        $this->assertSame('', $steps['menu']['source_ref']);

        // Full skeleton preserved (count + step_keys unchanged).
        $this->assertCount(6, $payload['steps']);
    }

    public function test_bare_item_deactivates_all_attribute_steps_but_keeps_skeleton(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);

        $payload = app(ComposerTemplateService::class)->buildPayload('tacos', $item->fresh());
        $steps = $this->steps($payload);

        // No constructs -> every item_attribute step is deactivated, none match-all.
        foreach (['taille', 'viande', 'sauce'] as $key) {
            $this->assertFalse($steps[$key]['is_active'], "{$key} should be deactivated on a bare item");
            $this->assertSame('', $steps[$key]['source_ref']);
        }
        // Skeleton intact for the owner to bind later.
        $this->assertCount(6, $payload['steps']);
    }

    /**
     * [W7 convergence gate] Real-catalog shape: a "Big" tacos carries TWO meat
     * attributes (Viande 1 + Viande 2). The audit P1 was that only the first
     * rendered and the 2nd meat page was silently dropped. The single viande step
     * must now EXPAND into one bound step per attribute — the 2nd OPTIONAL.
     */
    public function test_multi_attribute_step_expands_into_one_step_per_attribute(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $viande1 = ItemAttribute::factory()->create(['name' => 'Viande 1', 'status' => Status::ACTIVE]);
        $viande2 = ItemAttribute::factory()->create(['name' => 'Viande 2', 'status' => Status::ACTIVE]);
        $sauce = ItemAttribute::factory()->create(['name' => 'Sauce', 'status' => Status::ACTIVE]);
        foreach (['Poulet', 'Boeuf'] as $n) {
            ItemVariation::query()->create(['item_id' => $item->id, 'item_attribute_id' => $viande1->id, 'name' => $n, 'price' => 0, 'status' => Status::ACTIVE]);
        }
        foreach (['Agneau', 'Merguez'] as $n) {
            ItemVariation::query()->create(['item_id' => $item->id, 'item_attribute_id' => $viande2->id, 'name' => $n, 'price' => 0, 'status' => Status::ACTIVE]);
        }
        ItemVariation::query()->create(['item_id' => $item->id, 'item_attribute_id' => $sauce->id, 'name' => 'Blanche', 'price' => 0, 'status' => Status::ACTIVE]);

        $payload = app(ComposerTemplateService::class)->buildPayload('tacos', $item->fresh());
        $steps = $this->steps($payload);

        // First meat -> original step, REQUIRED (template min_select=1).
        $this->assertSame('Viande 1', $steps['viande']['source_ref']);
        $this->assertTrue($steps['viande']['is_active']);
        $this->assertSame(1, (int) $steps['viande']['min_select']);

        // Second meat -> its OWN expanded step, labelled by attribute, OPTIONAL.
        $this->assertArrayHasKey('viande_2', $steps, 'the 2nd meat attribute must get its own wizard page');
        $this->assertSame('Viande 2', $steps['viande_2']['source_ref']);
        $this->assertSame('Viande 2', $steps['viande_2']['label']);
        $this->assertTrue($steps['viande_2']['is_active']);
        $this->assertSame(0, (int) $steps['viande_2']['min_select']);

        // step_keys unique (DB unique(profile_id, step_key)); positions gap-free.
        $keys = collect($payload['steps'])->pluck('step_key');
        $this->assertSame($keys->count(), $keys->unique()->count(), 'step_keys must stay unique');
        $positions = collect($payload['steps'])->pluck('position')->sort()->values();
        $this->assertSame(range(1, $positions->count()), $positions->all());

        // tacos = 6 template steps + 1 expanded meat page.
        $this->assertCount(7, $payload['steps']);
    }

    /**
     * [W7 convergence gate] The audit P1 on extra_group: an unmatched group_label
     * was left ACTIVE with an empty source_ref, which the projection treats as
     * match-all (EVERY extra of the item). It must be DEACTIVATED instead —
     * symmetric with item_attribute, never match-all.
     */
    public function test_unmatched_extra_group_is_deactivated_not_match_all(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $viande = ItemAttribute::factory()->create(['name' => 'Viande 1', 'status' => Status::ACTIVE]);
        ItemVariation::query()->create(['item_id' => $item->id, 'item_attribute_id' => $viande->id, 'name' => 'Poulet', 'price' => 0, 'status' => Status::ACTIVE]);
        // An extra whose group_label matches NO step hint (not a garniture/supplément).
        ItemExtra::query()->create(['item_id' => $item->id, 'name' => 'Sauce Maison', 'price' => 0, 'status' => Status::ACTIVE, 'group_label' => 'Sauces Maison']);

        $payload = app(ComposerTemplateService::class)->buildPayload('tacos', $item->fresh());
        $steps = $this->steps($payload);

        foreach (['garnitures', 'supplements'] as $key) {
            $this->assertFalse($steps[$key]['is_active'], "{$key} must be deactivated, never match-all");
            $this->assertSame('', $steps[$key]['source_ref']);
        }
    }
}
