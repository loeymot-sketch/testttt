<?php

namespace Tests\Feature\Composer;

use App\Http\Requests\ItemAttributeRequest;
use App\Models\ItemAttribute;
use App\Models\ItemWizardStep;
use App\Services\ItemAttributeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] Adversarial-audit finding.
 *
 * ComposerProfileProjection::matchesAttributeRef() resolves an
 * item_wizard_steps row with source_type='item_attribute' by comparing its
 * source_ref against the attribute's CURRENT lowercased name (or its id).
 * A real, live DB check found 57 wizard steps in this database referencing
 * an attribute by name (source_ref = strtolower(name)), not by id.
 *
 * ItemAttributeService::update() previously did a bare
 * $itemAttribute->update($request->validated()) with no propagation: renaming
 * an attribute silently orphaned every name-referencing step — choices()
 * would return an empty array, so the size/sauce/meat choice list for that
 * step goes blank in the kiosk/POS/web composer, with ZERO error surfaced
 * anywhere (not to the admin who renamed it, not to the customer who sees
 * an empty dropdown).
 */
class ItemAttributeRenamePropagationTest extends TestCase
{
    use RefreshDatabase;

    public function test_renaming_an_attribute_updates_matching_wizard_step_source_ref(): void
    {
        $attribute = ItemAttribute::create(['name' => 'Sauce Bol', 'status' => 1]);

        $step = ItemWizardStep::factory()->create([
            'source_type' => 'item_attribute',
            'source_ref' => 'sauce bol',
        ]);
        $otherStep = ItemWizardStep::factory()->create([
            'source_type' => 'item_attribute',
            'source_ref' => 'viande 1',
        ]);

        $request = ItemAttributeRequest::createFrom(Request::create('', 'PUT', [
            'name' => 'Sauce (Nouvelle)',
            'status' => 1,
        ]));
        $request->setContainer(app())->validateResolved();

        app(ItemAttributeService::class)->update($request, $attribute);

        $this->assertSame('sauce (nouvelle)', $step->fresh()->source_ref);
        // Unrelated steps referencing a different attribute must not be touched.
        $this->assertSame('viande 1', $otherStep->fresh()->source_ref);
    }

    public function test_renaming_an_attribute_leaves_id_referenced_steps_untouched(): void
    {
        $attribute = ItemAttribute::create(['name' => 'Sauce Bol', 'status' => 1]);

        $step = ItemWizardStep::factory()->create([
            'source_type' => 'item_attribute',
            'source_ref' => (string) $attribute->id,
        ]);

        $request = ItemAttributeRequest::createFrom(Request::create('', 'PUT', [
            'name' => 'Sauce (Nouvelle)',
            'status' => 1,
        ]));
        $request->setContainer(app())->validateResolved();

        app(ItemAttributeService::class)->update($request, $attribute);

        // id-referenced steps are already rename-proof — must be left as-is.
        $this->assertSame((string) $attribute->id, $step->fresh()->source_ref);
    }
}
