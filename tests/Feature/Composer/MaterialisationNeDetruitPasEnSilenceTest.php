<?php

namespace Tests\Feature\Composer;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\WizardPage;
use App\Models\WizardPageChoice;
use App\Services\Composer\WizardPageMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Constats d'un audit adverse du 2026-09-02, figés ici.
 *
 * La matérialisation écrit sur le catalogue que la caisse facture. Deux comportements y étaient
 * silencieux et destructeurs :
 *  - une page « formule » dont aucun choix ne désigne un produit supprimait TOUS les addons du rôle
 *    et n'en recréait aucun (étape vide en caisse, sur toute la catégorie) ;
 *  - une étape active sans page reliée était écartée sans un mot : la commande annonçait
 *    « 0 changement » alors qu'elle n'avait rien pu écrire. Faux vert.
 */
class MaterialisationNeDetruitPasEnSilenceTest extends TestCase
{
    use RefreshDatabase;

    private ItemCategory $category;

    private Item $produit;

    private Item $boisson;

    private ItemWizardProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        $this->category = ItemCategory::factory()->create(['name' => 'Wraps']);
        $this->produit = Item::factory()->create(['item_category_id' => $this->category->id, 'name' => 'Wrap Poulet']);
        $this->boisson = Item::factory()->create(['name' => 'Coca 33cl']);
        $this->profile = ItemWizardProfile::factory()->forCategory($this->category)->create(['template' => 'custom']);
    }

    public function test_une_page_formule_sans_produit_relie_ne_supprime_aucun_addon(): void
    {
        // L'existant : le produit propose déjà une boisson.
        ItemAddon::create([
            'item_id' => $this->produit->id,
            'addon_item_id' => $this->boisson->id,
            'role' => 'drink',
        ]);

        // Le gérant crée « Boisson » dans la bibliothèque et tape des noms sans relier de produit
        // du catalogue — la validation l'autorise (`choices.*.addon_item_id` est `nullable`).
        $page = WizardPage::factory()->addon('drink')->create([
            'key' => 'boisson', 'label' => 'Boisson', 'kind' => 'menu', 'min_select' => 0, 'max_select' => 1,
        ]);
        WizardPageChoice::factory()->create([
            'wizard_page_id' => $page->id, 'name' => 'Coca', 'price' => 0, 'sort' => 0, 'addon_item_id' => null,
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $this->profile->id, 'wizard_page_id' => $page->id, 'step_key' => 'boisson',
            'label' => 'Boisson', 'source_type' => 'addon', 'source_ref' => 'drink', 'position' => 0,
            'min_select' => 0, 'max_select' => 1,
        ]);

        $report = app(WizardPageMaterializer::class)->materializeCategory($this->category, $this->profile);

        $this->assertSame(
            1,
            ItemAddon::query()->where('item_id', $this->produit->id)->where('role', 'drink')->count(),
            'la formule existante a été effacée : l’étape serait vide en caisse et sur la borne',
        );
        $this->assertSame(0, $report->counts['addons_removed']);
        $this->assertNotEmpty($report->warnings, 'le silence est le vrai défaut : il faut le dire');
        $this->assertStringContainsString('aucun choix ne désigne un produit', implode(' ', $report->warnings));
    }

    public function test_une_page_formule_reliee_ecrit_bien_l_addon(): void
    {
        $page = WizardPage::factory()->addon('drink')->create([
            'key' => 'boisson', 'label' => 'Boisson', 'kind' => 'menu', 'min_select' => 0, 'max_select' => 1,
        ]);
        WizardPageChoice::factory()->create([
            'wizard_page_id' => $page->id, 'name' => 'Coca 33cl', 'price' => 0, 'sort' => 0,
            'addon_item_id' => $this->boisson->id,
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $this->profile->id, 'wizard_page_id' => $page->id, 'step_key' => 'boisson',
            'label' => 'Boisson', 'source_type' => 'addon', 'source_ref' => 'drink', 'position' => 0,
            'min_select' => 0, 'max_select' => 1,
        ]);

        app(WizardPageMaterializer::class)->materializeCategory($this->category, $this->profile);

        $this->assertDatabaseHas('item_addons', [
            'item_id' => $this->produit->id,
            'addon_item_id' => $this->boisson->id,
            'role' => 'drink',
        ]);
    }

    public function test_une_etape_active_sans_page_est_signalee_et_non_avalee(): void
    {
        ItemWizardStep::factory()->create([
            'profile_id' => $this->profile->id, 'wizard_page_id' => null, 'step_key' => 'taille',
            'label' => 'Choisis la taille', 'source_type' => 'item_attribute', 'source_ref' => '', 'position' => 0,
            'min_select' => 1, 'max_select' => 1, 'is_active' => 1,
        ]);

        $report = app(WizardPageMaterializer::class)->materializeCategory($this->category, $this->profile);

        $this->assertNotEmpty($report->warnings, '« 0 changement » sans un mot est un faux vert');
        $this->assertStringContainsString('aucune page reliée', implode(' ', $report->warnings));
    }

    public function test_une_page_eteinte_est_signalee(): void
    {
        $page = WizardPage::factory()->create([
            'key' => 'pain', 'label' => 'Choisis ton pain', 'kind' => 'pain',
            'source_type' => 'item_attribute', 'min_select' => 1, 'max_select' => 1, 'is_active' => false,
        ]);
        WizardPageChoice::factory()->create(['wizard_page_id' => $page->id, 'name' => 'Galette', 'price' => 0, 'sort' => 0]);
        ItemWizardStep::factory()->create([
            'profile_id' => $this->profile->id, 'wizard_page_id' => $page->id, 'step_key' => 'pain',
            'label' => 'Choisis ton pain', 'source_type' => 'item_attribute', 'source_ref' => '', 'position' => 0,
            'min_select' => 1, 'max_select' => 1, 'is_active' => 1,
        ]);

        $report = app(WizardPageMaterializer::class)->materializeCategory($this->category, $this->profile);

        $this->assertStringContainsString('éteinte', implode(' ', $report->warnings));
        $this->assertSame(0, $report->counts['variations_created'] ?? 0);
    }

    public function test_les_choix_hors_page_sont_desactives_jamais_supprimes(): void
    {
        // Contrat explicite : la matérialisation ne fait pas de suppression dure sur les variations.
        $page = WizardPage::factory()->create([
            'key' => 'viande', 'label' => 'Choisis ta viande', 'kind' => 'viande',
            'source_type' => 'item_attribute', 'min_select' => 1, 'max_select' => 1,
        ]);
        WizardPageChoice::factory()->create(['wizard_page_id' => $page->id, 'name' => 'Poulet', 'price' => 0, 'sort' => 0]);
        ItemWizardStep::factory()->create([
            'profile_id' => $this->profile->id, 'wizard_page_id' => $page->id, 'step_key' => 'viande',
            'label' => 'Viande', 'source_type' => 'item_attribute', 'source_ref' => '', 'position' => 0,
            'min_select' => 1, 'max_select' => 1,
        ]);

        app(WizardPageMaterializer::class)->materializeCategory($this->category, $this->profile);
        $attribut = \App\Models\ItemAttribute::query()->where('name', 'Choisis ta viande')->firstOrFail();

        $maison = \App\Models\ItemVariation::create([
            'item_id' => $this->produit->id,
            'item_attribute_id' => $attribut->id,
            'name' => 'Viande du chef',
            'price' => 2.5,
            'status' => Status::ACTIVE,
        ]);

        app(WizardPageMaterializer::class)->materializeCategory($this->category, $this->profile);

        $maison->refresh();
        $this->assertNull($maison->deleted_at, 'aucune suppression dure');
        $this->assertSame(Status::INACTIVE, (int) $maison->status, 'retiré de la vente, mais récupérable');
    }
}
