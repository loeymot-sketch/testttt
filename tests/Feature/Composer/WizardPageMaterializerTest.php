<?php

namespace Tests\Feature\Composer;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\WizardPage;
use App\Models\WizardPageChoice;
use App\Services\Composer\WizardPageMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Une page de la bibliothèque = une liste de choix avec prix ; la matérialisation les écrit sur
 * chaque produit de la catégorie sans jamais rien supprimer, et un second passage ne change rien.
 */
class WizardPageMaterializerTest extends TestCase
{
    use RefreshDatabase;

    private ItemCategory $category;

    private Item $tacosM;

    private Item $tacosL;

    private WizardPage $viande;

    private WizardPage $supplements;

    private ItemWizardProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        $this->category = ItemCategory::factory()->create(['name' => 'Wraps']);
        $this->tacosM = Item::factory()->create(['item_category_id' => $this->category->id, 'name' => 'Wrap M']);
        $this->tacosL = Item::factory()->create(['item_category_id' => $this->category->id, 'name' => 'Wrap L']);

        $this->viande = WizardPage::factory()->create([
            'key' => 'viande', 'label' => 'Choisis ta viande', 'kind' => 'viande', 'source_type' => 'item_attribute',
            'min_select' => 1, 'max_select' => 1,
        ]);
        foreach ([['Poulet', 0], ['Bœuf', 0], ['Kebab', 0]] as $i => [$name, $price]) {
            WizardPageChoice::factory()->create(['wizard_page_id' => $this->viande->id, 'name' => $name, 'price' => $price, 'sort' => $i]);
        }

        $this->supplements = WizardPage::factory()->extraGroup('supplement')->create([
            'key' => 'supplements', 'label' => 'Suppléments', 'kind' => 'supplements', 'min_select' => 0, 'max_select' => 5,
        ]);
        WizardPageChoice::factory()->create(['wizard_page_id' => $this->supplements->id, 'name' => 'Cheddar', 'price' => 0.9, 'sort' => 0]);
        WizardPageChoice::factory()->create(['wizard_page_id' => $this->supplements->id, 'name' => 'Bacon', 'price' => 1.5, 'sort' => 1]);

        $this->profile = ItemWizardProfile::factory()->forCategory($this->category)->create(['template' => 'custom']);
        ItemWizardStep::factory()->create([
            'profile_id' => $this->profile->id, 'wizard_page_id' => $this->viande->id, 'step_key' => 'viande',
            'label' => 'Ta viande', 'source_type' => 'item_attribute', 'source_ref' => '', 'position' => 0,
            'min_select' => 1, 'max_select' => 1,
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $this->profile->id, 'wizard_page_id' => $this->supplements->id, 'step_key' => 'supplements',
            'label' => 'Suppléments', 'source_type' => 'extra_group', 'source_ref' => '', 'position' => 1,
            'min_select' => 0, 'max_select' => 5,
        ]);
    }

    public function test_materialization_writes_every_choice_on_every_product_and_is_idempotent(): void
    {
        $report = app(WizardPageMaterializer::class)->materializeCategory($this->category, $this->profile);

        $this->assertSame(2, $report->itemsTouched);
        $this->assertSame(1, $report->counts['attributes_created'], 'La page attribut crée son attribut au premier passage.');
        $this->assertSame(6, $report->counts['variations_created']);
        $this->assertSame(4, $report->counts['extras_created']);

        $attribute = ItemAttribute::query()->where('name', 'Choisis ta viande')->firstOrFail();
        foreach ([$this->tacosM, $this->tacosL] as $item) {
            $names = ItemVariation::query()->where('item_id', $item->id)->where('item_attribute_id', $attribute->id)
                ->where('status', Status::ACTIVE)->orderBy('id')->pluck('name')->all();
            $this->assertSame(['Poulet', 'Bœuf', 'Kebab'], $names, "Produit {$item->name}");

            $extras = ItemExtra::query()->where('item_id', $item->id)->where('group_label', 'supplement')
                ->orderBy('id')->get(['name', 'price'])->map(fn ($e) => [$e->name, (float) $e->price])->all();
            $this->assertSame([['Cheddar', 0.9], ['Bacon', 1.5]], $extras, "Produit {$item->name}");
        }

        // L'étape porte désormais la référence que la projection comprend.
        $step = $this->profile->steps()->where('wizard_page_id', $this->viande->id)->firstOrFail();
        $this->assertSame((string) $attribute->id, (string) $step->source_ref);
        $this->assertSame($attribute->id, (int) $step->source_item_attribute_id);
        $extraStep = $this->profile->steps()->where('wizard_page_id', $this->supplements->id)->firstOrFail();
        $this->assertSame('supplement', $extraStep->source_ref);

        $second = app(WizardPageMaterializer::class)->materializeCategory($this->category, $this->profile);
        $this->assertSame(0, $second->changes(), 'Second passage : zéro écart. '.implode(' | ', $second->lines));
    }

    public function test_removed_choice_is_deactivated_never_deleted_and_price_change_propagates(): void
    {
        $materializer = app(WizardPageMaterializer::class);
        $materializer->materializeCategory($this->category, $this->profile);

        WizardPageChoice::query()->where('wizard_page_id', $this->viande->id)->where('name', 'Kebab')->firstOrFail()->delete();
        WizardPageChoice::query()->where('wizard_page_id', $this->supplements->id)->where('name', 'Cheddar')->firstOrFail()->update(['price' => 1.2]);

        $report = $materializer->materializeCategory($this->category, $this->profile);

        $this->assertSame(2, $report->counts['variations_deactivated']);
        $this->assertSame(2, $report->counts['extras_updated']);
        $kebab = ItemVariation::query()->where('item_id', $this->tacosM->id)->where('name', 'Kebab')->firstOrFail();
        $this->assertSame(Status::INACTIVE, (int) $kebab->status, 'Un choix retiré est désactivé, jamais supprimé (historique des commandes).');
        $this->assertNull($kebab->deleted_at);
        $this->assertSame(1.2, (float) ItemExtra::query()->where('item_id', $this->tacosL->id)->where('name', 'Cheddar')->value('price'));

        // Le choix revient dans la page : la ligne existante est réactivée, pas dupliquée.
        WizardPageChoice::withTrashed()->where('wizard_page_id', $this->viande->id)->where('name', 'Kebab')->firstOrFail()->restore();
        $materializer->materializeCategory($this->category, $this->profile);
        $this->assertSame(1, ItemVariation::query()->where('item_id', $this->tacosM->id)->where('name', 'Kebab')->count());
        $this->assertSame(Status::ACTIVE, (int) $kebab->fresh()->status);
    }

    public function test_dry_run_reports_the_plan_without_writing(): void
    {
        $report = app(WizardPageMaterializer::class)->materializeCategory($this->category, $this->profile, true);

        $this->assertTrue($report->dryRun);
        $this->assertGreaterThan(0, $report->changes());
        $this->assertSame(0, ItemVariation::query()->count());
        $this->assertSame(0, ItemExtra::query()->count());
        $this->assertSame(0, ItemAttribute::query()->where('name', 'Choisis ta viande')->count());
        $this->assertNull($this->profile->steps()->first()->fresh()->wizard_page_id === null ? null : null);
        $this->assertSame('', (string) $this->profile->steps()->where('wizard_page_id', $this->supplements->id)->value('source_ref'));
    }

    public function test_existing_rows_are_reused_case_insensitively_and_foreign_rows_untouched(): void
    {
        $attribute = ItemAttribute::factory()->create(['name' => 'Viande maison']);
        $this->viande->forceFill(['item_attribute_id' => $attribute->id])->save();
        ItemVariation::create(['item_id' => $this->tacosM->id, 'item_attribute_id' => $attribute->id, 'name' => 'poulet', 'price' => 0, 'status' => Status::ACTIVE]);
        $otherAttribute = ItemAttribute::factory()->create(['name' => 'Cuisson']);
        ItemVariation::create(['item_id' => $this->tacosM->id, 'item_attribute_id' => $otherAttribute->id, 'name' => 'Saignant', 'price' => 0, 'status' => Status::ACTIVE]);
        ItemExtra::create(['item_id' => $this->tacosM->id, 'name' => 'Sauce supplémentaire', 'price' => 0.5, 'status' => Status::ACTIVE, 'group_label' => 'sauce']);

        $report = app(WizardPageMaterializer::class)->materializeCategory($this->category, $this->profile);

        $this->assertSame(0, $report->counts['attributes_created']);
        $this->assertSame(1, ItemVariation::query()->where('item_id', $this->tacosM->id)->where('item_attribute_id', $attribute->id)->whereRaw('LOWER(name) = ?', ['poulet'])->count(), 'Pas de doublon « poulet » / « Poulet ».');
        $this->assertSame('Poulet', ItemVariation::query()->where('item_id', $this->tacosM->id)->whereRaw('LOWER(name) = ?', ['poulet'])->value('name'));
        $this->assertSame(Status::ACTIVE, (int) ItemVariation::query()->where('name', 'Saignant')->value('status'), 'Un autre attribut n\'est pas touché.');
        $this->assertSame(Status::ACTIVE, (int) ItemExtra::query()->where('name', 'Sauce supplémentaire')->value('status'), 'Un autre groupe d\'extras n\'est pas touché.');
    }
}
