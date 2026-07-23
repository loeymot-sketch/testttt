<?php

namespace Tests\Feature\Composer;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Services\Composer\ComposerProfileProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [PERF 2026-07-23 POS-instant-open] Verrouille le contrat du 5ᵉ paramètre optionnel
 * `$choiceAvailability` de ComposerProfileProjection::project() : le resource appelant
 * (NormalItemResource) calcule le snapshot de disponibilité UNE fois et le repasse à la
 * projection, qui doit le réutiliser tel quel (au lieu de relancer
 * ChoiceAvailabilityResolver::snapshotForItem pour le même item/branche/surface).
 * Le fallback (paramètre omis) doit rester strictement identique au comportement
 * historique — cf. ComposerProfileProjectionVariationRuptureTest.
 */
class ComposerProfileProjectionReusesChoiceAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_passed_choice_availability_is_used_verbatim_instead_of_recomputing(): void
    {
        [$branch, $item, $profile, $variation] = $this->profileWithVariationStep();

        // Snapshot pré-calculé (comme NormalItemResource) marquant la variation indisponible
        // avec une raison UNIQUE, impossible à obtenir par recalcul (attribut dispo, aucune
        // rupture stock ici). Si project() réutilise ce snapshot → is_available=false + raison
        // custom ; s'il recalculait, il obtiendrait is_available=true → l'assertion échouerait.
        $precomputed = [
            'variations' => [
                (int) $variation->id => ['is_available' => false, 'unavailable_reason' => 'passed_through_snapshot'],
            ],
            'extras' => [],
            'addons' => [],
        ];

        $projection = app(ComposerProfileProjection::class)->project(
            $profile->fresh('steps'),
            $item->fresh(['variations', 'extras', 'addons']),
            'kiosk',
            (int) $branch->id,
            $precomputed,
        );

        $choice = $projection['steps'][0]['choices'][0];
        $this->assertSame((int) $variation->id, $choice['id']);
        $this->assertFalse($choice['is_available']);
        $this->assertSame('passed_through_snapshot', $choice['unavailable_reason']);
    }

    public function test_without_passed_snapshot_it_falls_back_to_computing(): void
    {
        [$branch, $item, $profile, $variation] = $this->profileWithVariationStep();

        // 5ᵉ argument omis → chemin historique (calcul interne). Attribut disponible + aucune
        // rupture → is_available=true, raison null. Prouve la rétro-compatibilité du fallback.
        $projection = app(ComposerProfileProjection::class)->project(
            $profile->fresh('steps'),
            $item->fresh(['variations', 'extras', 'addons']),
            'kiosk',
            (int) $branch->id,
        );

        $choice = $projection['steps'][0]['choices'][0];
        $this->assertSame((int) $variation->id, $choice['id']);
        $this->assertTrue($choice['is_available']);
        $this->assertNull($choice['unavailable_reason']);
    }

    private function profileWithVariationStep(): array
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $profile = ItemWizardProfile::factory()->create(['item_id' => $item->id]);
        $attribute = ItemAttribute::factory()->create([
            'name' => 'Viande',
            'is_available' => true,
            'unavailable_reason' => null,
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
            'stockable_choices' => false,
            'position' => 1,
        ]);

        return [$branch, $item, $profile, $variation];
    }
}
