<?php

namespace Tests\Feature\Composer;

use App\Enums\Status;
use App\Models\ItemAttribute;
use App\Models\User;
use App\Models\WizardPage;
use App\Models\WizardPageChoice;
use App\Services\Composer\WizardPageService;
use Database\Seeders\ComposerPermissionsMinimalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Corriger un prix depuis l'écran « Pages de wizard » ne doit RIEN casser d'autre.
 *
 * [2026-09-02] Le formulaire n'envoie que 8 champs (nom, type, min, max, canaux, actif, choix).
 * `update()` ne reprenait de la page existante que `kind`, `source_type` et `label` : tout le
 * reste retombait sur les valeurs par défaut de `normalize()`. Conséquence la plus grave :
 * `item_attribute_id` passait à `null`, et `ensureAttributeFor()` recréait un attribut NEUF à
 * chaque enregistrement — les variations produits accrochées à l'ancien attribut devenaient
 * orphelines, et la caisse ne servait plus les bons choix. Un simple changement de prix suffisait.
 */
class ModifierUnePageNeCasseRienTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        $this->seed(ComposerPermissionsMinimalSeeder::class);
    }

    public function test_changer_un_prix_ne_recree_pas_l_attribut_ni_ne_vide_les_champs_absents(): void
    {
        $attribut = ItemAttribute::create([
            'name' => 'Viande 1',
            'status' => Status::ACTIVE,
            'min_select' => 1,
            'max_select' => 1,
            'allow_repeat' => false,
        ]);

        $page = WizardPage::create([
            'key' => 'viande',
            'label' => 'Choisis ta viande',
            'kind' => 'viande',
            'source_type' => 'item_attribute',
            'item_attribute_id' => $attribut->id,
            'min_select' => 1,
            'max_select' => 1,
            'allow_repeat' => true,
            'stockable_choices' => true,
            'description' => 'Une seule viande, gratuite.',
            'sort' => 7,
            'is_active' => true,
        ]);
        $choix = WizardPageChoice::create([
            'wizard_page_id' => $page->id,
            'name' => 'Poulet',
            'price' => 0,
            'sort' => 0,
            'status' => Status::ACTIVE,
        ]);

        $attributsAvant = ItemAttribute::count();

        // Exactement ce que l'écran envoie : 8 champs, rien de plus.
        app(WizardPageService::class)->update($page, [
            'label' => 'Choisis ta viande',
            'kind' => 'viande',
            'source_type' => 'item_attribute',
            'min_select' => 1,
            'max_select' => 1,
            'visible_on' => ['pos', 'kiosk'],
            'is_active' => true,
            'choices' => [
                ['id' => $choix->id, 'name' => 'Poulet', 'price' => 1.5, 'status' => Status::ACTIVE, 'sort' => 0],
            ],
        ]);

        $page->refresh();

        $this->assertSame($attribut->id, $page->item_attribute_id, "l'attribut lu par la caisse a changé");
        $this->assertSame($attributsAvant, ItemAttribute::count(), 'un attribut fantôme a été créé');
        $this->assertTrue((bool) $page->allow_repeat, 'allow_repeat a été effacé');
        $this->assertTrue((bool) $page->stockable_choices, 'stockable_choices a été effacé');
        $this->assertSame('Une seule viande, gratuite.', $page->description, 'la description a été effacée');
        $this->assertSame(7, (int) $page->sort, "l'ordre de la page a été remis à zéro");
        $this->assertSame('1.500000', (string) $page->choices()->first()->price);
    }

    public function test_une_page_extras_ne_perd_pas_son_groupe(): void
    {
        $page = WizardPage::create([
            'key' => 'supplements',
            'label' => 'Suppléments',
            'kind' => 'supplements',
            'source_type' => 'extra_group',
            'extra_group_label' => 'supplement',
            'min_select' => 0,
            'max_select' => 5,
            'is_active' => true,
        ]);

        app(WizardPageService::class)->update($page, [
            'label' => 'Suppléments',
            'kind' => 'supplements',
            'source_type' => 'extra_group',
            'min_select' => 0,
            'max_select' => 4,
            'visible_on' => ['pos', 'kiosk'],
            'is_active' => true,
            'choices' => [],
        ]);

        $this->assertSame(
            'supplement',
            $page->refresh()->extra_group_label,
            'sans groupe, la caisse n’affiche plus aucun supplément',
        );
        $this->assertSame(4, (int) $page->max_select);
    }

    public function test_un_champ_envoye_est_bien_pris_en_compte(): void
    {
        $page = WizardPage::create([
            'key' => 'garnitures',
            'label' => 'Garnitures',
            'kind' => 'garnitures',
            'source_type' => 'extra_group',
            'extra_group_label' => 'crudite',
            'min_select' => 0,
            'max_select' => 6,
            'is_active' => true,
        ]);

        app(WizardPageService::class)->update($page, [
            'extra_group_label' => 'crudites_v2',
            'is_active' => false,
        ]);

        $page->refresh();
        $this->assertSame('crudites_v2', $page->extra_group_label);
        $this->assertFalse((bool) $page->is_active, 'une modification explicite doit passer');
    }

    /**
     * Supprimer une page laissait son attribut derrière elle : la liste « Attribut d'articles » se
     * remplissait d'entrées fantômes. Constaté en base après un aller-retour de création/suppression.
     */
    public function test_supprimer_une_page_ne_laisse_pas_d_attribut_fantome(): void
    {
        $service = app(WizardPageService::class);
        $avant = ItemAttribute::count();

        $page = $service->create([
            'label' => 'Choisis ta base',
            'kind' => 'generic',
            'source_type' => 'item_attribute',
            'min_select' => 1,
            'max_select' => 1,
            'choices' => [['name' => 'Riz', 'price' => 0, 'sort' => 0]],
        ]);
        $this->assertSame($avant + 1, ItemAttribute::count(), "la page attribut crée bien son attribut");

        $service->delete($page->refresh());

        $this->assertSame($avant, ItemAttribute::count(), 'un attribut fantôme reste dans « Attribut d’articles »');
    }

    /** En revanche, un attribut qui sert encore à des produits ne doit JAMAIS être supprimé. */
    public function test_un_attribut_utilise_par_des_produits_survit_a_la_suppression_de_la_page(): void
    {
        $service = app(WizardPageService::class);
        $page = $service->create([
            'label' => 'Choisis ta viande',
            'kind' => 'viande',
            'source_type' => 'item_attribute',
            'min_select' => 1,
            'max_select' => 1,
            'choices' => [['name' => 'Poulet', 'price' => 0, 'sort' => 0]],
        ]);
        $attributId = $page->refresh()->item_attribute_id;

        $categorie = \App\Models\ItemCategory::factory()->create(['name' => 'Wraps']);
        $produit = \App\Models\Item::factory()->create(['item_category_id' => $categorie->id]);
        \App\Models\ItemVariation::create([
            'item_id' => $produit->id,
            'item_attribute_id' => $attributId,
            'name' => 'Poulet',
            'price' => 0,
            'status' => Status::ACTIVE,
        ]);

        $service->delete($page->refresh());

        $this->assertNotNull(ItemAttribute::find($attributId), 'des variations produits pointaient encore dessus');
    }

    public function test_l_ecran_qui_enregistre_deux_fois_ne_multiplie_pas_les_attributs(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $attribut = ItemAttribute::create([
            'name' => 'Sauce',
            'status' => Status::ACTIVE,
            'min_select' => 1,
            'max_select' => 1,
            'allow_repeat' => false,
        ]);
        $page = WizardPage::create([
            'key' => 'sauce',
            'label' => 'Choisis ta sauce',
            'kind' => 'sauce',
            'source_type' => 'item_attribute',
            'item_attribute_id' => $attribut->id,
            'min_select' => 1,
            'max_select' => 1,
            'is_active' => true,
        ]);

        $avant = ItemAttribute::count();
        $payload = [
            'label' => 'Choisis ta sauce',
            'kind' => 'sauce',
            'source_type' => 'item_attribute',
            'min_select' => 1,
            'max_select' => 1,
            'visible_on' => ['pos', 'kiosk'],
            'is_active' => true,
            'choices' => [['name' => 'Algérienne', 'price' => 0, 'status' => Status::ACTIVE, 'sort' => 0]],
        ];

        foreach ([1, 2, 3] as $_) {
            $this->actingAs($admin, 'sanctum')
                ->putJson("/api/admin/composer/wizard-pages/{$page->id}", $payload)
                ->assertOk();
        }

        $this->assertSame($avant, ItemAttribute::count(), 'trois enregistrements = trois attributs fantômes');
        $this->assertSame($attribut->id, $page->refresh()->item_attribute_id);
        $this->assertSame(1, $page->choices()->count(), 'le même choix a été dupliqué à chaque envoi');
    }
}
