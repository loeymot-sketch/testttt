<?php

namespace Tests\Feature\Composer;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Models\User;
use App\Models\WizardPage;
use App\Models\WizardPageChoice;
use App\Services\Composer\ComposerProfileService;
use Database\Seeders\ComposerPermissionsMinimalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La demande du propriétaire, prise au mot : « si demain j'ai une nouvelle catégorie à ajouter, tu dois
 * créer N pages de wizard, je mets celles qui sont déjà enregistrées (pain, crudités…) et j'en
 * personnalise d'autres ».
 *
 * Ce test fait exactement ça, par l'API du Dashboard, et vérifie que la CAISSE et la BORNE voient le
 * parcours avec ses choix ET ses prix — sans une seule saisie dans les onglets Variante / Extra.
 */
class NouvelleCategorieDepuisLaBibliothequeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        $this->seed(ComposerPermissionsMinimalSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    /**
     * La bibliothèque écrit les PRIX du menu : elle doit être fermée à qui n'a pas le droit de
     * composer. La route porte `permission:catalog.compose` ET la FormRequest le redit — ce test
     * couvre les deux barrières d'un coup (il tombe si l'une des deux disparaît).
     */
    public function test_sans_le_droit_de_composer_la_bibliotheque_est_fermee(): void
    {
        $page = $this->page('pain', 'Choisis ton pain', 'pain', ['Pain', 'Galette']);
        $sansDroit = User::factory()->create();

        $this->actingAs($sansDroit, 'sanctum')
            ->getJson('/api/admin/composer/wizard-pages')
            ->assertForbidden();

        $this->actingAs($sansDroit, 'sanctum')
            ->putJson("/api/admin/composer/wizard-pages/{$page->id}", ['label' => 'Détourné'])
            ->assertForbidden();

        $this->assertSame('Choisis ton pain', $page->refresh()->label);
    }

    public function test_une_nouvelle_categorie_prend_les_pages_de_la_bibliotheque_et_arrive_en_caisse(): void
    {
        // Bibliothèque : deux pages déjà enregistrées, réutilisables par toutes les catégories.
        $pain = $this->page('pain', 'Choisis ton pain', 'pain', ['Pain', 'Galette']);
        $garnitures = $this->pageExtras('garnitures', 'Choisis tes garnitures', ['Salade' => 0.0, 'Tomate' => 0.0, 'Oignon' => 0.0]);

        // Le gérant crée sa catégorie et son premier produit.
        $category = ItemCategory::factory()->create(['name' => 'Wraps']);
        $item = Item::factory()->create(['item_category_id' => $category->id, 'name' => 'Wrap Poulet', 'status' => Status::ACTIVE]);

        // Il personnalise une des pages pour CETTE catégorie (copie privée).
        $privee = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/wizard-pages/{$garnitures->id}/duplicate-for-category/{$category->id}")
            ->assertOk()
            ->json('data');
        $this->assertFalse($privee['is_library']);
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/composer/wizard-pages/{$privee['id']}", [
                'label' => 'Crudités du wrap',
                'max_select' => 2,
                'choices' => [
                    ['name' => 'Salade', 'price' => 0],
                    ['name' => 'Chou rouge', 'price' => 0.5],
                ],
            ])
            ->assertOk();

        // Il compose le parcours de la catégorie : page de bibliothèque + page personnalisée.
        $profile = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/categories/{$category->id}/profile", [
                'template' => 'custom',
                'steps' => [
                    ['wizard_page_id' => $pain->id, 'step_key' => 'pain', 'label' => 'Ton pain', 'source_type' => 'item_attribute', 'min_select' => 1, 'max_select' => 1, 'position' => 0, 'visible_on' => ['pos', 'kiosk']],
                    ['wizard_page_id' => $privee['id'], 'step_key' => 'garnitures', 'label' => 'Tes crudités', 'source_type' => 'extra_group', 'min_select' => 0, 'max_select' => 2, 'position' => 1, 'visible_on' => ['pos', 'kiosk']],
                ],
            ])
            ->assertOk()
            ->json('data');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$profile['id']}/publish")
            ->assertOk();

        // CAISSE : le produit expose le parcours complet, choix ET prix, sans aucune saisie produit.
        $payload = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/item/show/{$item->id}")
            ->assertOk()
            ->json('data.composer_profile');

        $this->assertNotNull($payload, 'La caisse doit voir un profil publié pour ce produit.');
        $steps = collect($payload['steps']);
        $this->assertSame(['pain', 'garnitures'], $steps->pluck('step_key')->all());

        $painStep = $steps->firstWhere('step_key', 'pain');
        $this->assertSame(['Pain', 'Galette'], collect($painStep['choices'])->pluck('name')->all());

        $garnStep = $steps->firstWhere('step_key', 'garnitures');
        $this->assertSame(['Salade', 'Chou rouge'], collect($garnStep['choices'])->pluck('name')->all(), 'La page personnalisée prime sur la bibliothèque.');
        $this->assertSame(2, (int) $garnStep['max_select']);
        $this->assertSame(0.5, (float) \App\Models\ItemExtra::query()->where('item_id', $item->id)->where('name', 'Chou rouge')->value('price'));

        // Aucune fuite vers la bibliothèque : la page partagée garde ses trois choix d'origine.
        $this->assertSame(3, $garnitures->choices()->count());
    }

    public function test_un_produit_ajoute_apres_publication_recoit_le_wizard_automatiquement(): void
    {
        $sauce = $this->page('sauce', 'Choisis ta sauce', 'sauce', ['Blanche', 'Harissa']);
        $category = ItemCategory::factory()->create(['name' => 'Wraps']);
        $premier = Item::factory()->create(['item_category_id' => $category->id, 'status' => Status::ACTIVE]);

        $profile = app(ComposerProfileService::class)->createForCategory($category, [
            'template' => 'custom',
            'steps' => [[
                'wizard_page_id' => $sauce->id, 'step_key' => 'sauce', 'label' => 'Ta sauce',
                'source_type' => 'item_attribute', 'min_select' => 1, 'max_select' => 1, 'position' => 0,
            ]],
        ]);
        app(ComposerProfileService::class)->publish($profile);

        // Le gérant ajoute un produit depuis le Dashboard (vrai chemin : ItemController::store).
        $nouveauId = (int) $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/item', [
                'name' => 'Wrap Végé',
                'price' => 7.5,
                'item_category_id' => $category->id,
                'item_type' => 5,
                'is_featured' => 10,
                'status' => Status::ACTIVE,
                'order' => 1,
            ])
            ->assertCreated()
            ->json('data.id');
        $nouveau = Item::query()->findOrFail($nouveauId);

        $payload = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/item/show/{$nouveau->id}")
            ->assertOk()
            ->json('data.composer_profile');

        $this->assertNotNull($payload, 'Un produit créé après la publication doit hériter du wizard de sa catégorie.');
        $this->assertSame(['Blanche', 'Harissa'], collect($payload['steps'][0]['choices'])->pluck('name')->all());
        $this->assertNotNull($premier->fresh());
    }

    public function test_le_tableau_de_bord_dit_la_verite_sur_ce_que_lit_la_caisse(): void
    {
        $sauce = $this->page('sauce', 'Choisis ta sauce', 'sauce', ['Blanche']);
        $category = ItemCategory::factory()->create(['name' => 'Wraps']);
        $item = Item::factory()->create(['item_category_id' => $category->id, 'status' => Status::ACTIVE]);

        $service = app(ComposerProfileService::class);
        $profile = $service->createForCategory($category, [
            'template' => 'custom',
            'steps' => [[
                'wizard_page_id' => $sauce->id, 'step_key' => 'sauce', 'label' => 'Ta sauce',
                'source_type' => 'item_attribute', 'min_select' => 1, 'max_select' => 1, 'position' => 0,
            ]],
        ]);
        $service->publish($profile);

        // Cas réel mesuré en base le 2026-09-02 : profil catégorie publié, clones produit absents.
        ItemWizardProfile::query()->where('item_id', $item->id)->delete();

        $runtime = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/composer/categories/{$category->id}/runtime")
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $runtime['coverage']['total']);
        $this->assertSame(1, $runtime['coverage']['missing'], 'Le Dashboard doit signaler un produit non couvert, pas afficher « Publié » et se taire.');
        $this->assertSame('missing', $runtime['items'][0]['state']);

        $resync = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/categories/{$category->id}/materialize")
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $resync['runtime']['coverage']['covered']);
        $this->assertSame(0, $resync['runtime']['coverage']['missing']);
        $this->assertNotNull(
            $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/item/show/{$item->id}")->json('data.composer_profile')
        );
    }

    public function test_un_vieux_brouillon_ne_masque_plus_le_wizard_en_caisse(): void
    {
        $category = ItemCategory::factory()->create();
        Item::factory()->create(['item_category_id' => $category->id, 'status' => Status::ACTIVE]);

        $vieuxBrouillon = ItemWizardProfile::factory()->forCategory($category)->create(['version' => 9, 'is_published' => false]);
        $publie = ItemWizardProfile::factory()->forCategory($category)->create(['version' => 2, 'is_published' => true, 'published_at' => now()]);

        $vu = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/composer/categories/{$category->id}/profile")
            ->assertOk()
            ->json('data');

        $this->assertSame($publie->id, $vu['id'], 'Un brouillon plus ANCIEN que la publication ne doit plus être affiché comme état courant.');
        $this->assertNotSame($vieuxBrouillon->id, $vu['id']);
    }

    public function test_la_page_utilisee_par_une_categorie_ne_peut_pas_etre_supprimee(): void
    {
        $pain = $this->page('pain', 'Choisis ton pain', 'pain', ['Pain']);
        $category = ItemCategory::factory()->create(['name' => 'Wraps']);
        Item::factory()->create(['item_category_id' => $category->id, 'status' => Status::ACTIVE]);

        app(ComposerProfileService::class)->createForCategory($category, [
            'template' => 'custom',
            'steps' => [[
                'wizard_page_id' => $pain->id, 'step_key' => 'pain', 'label' => 'Ton pain',
                'source_type' => 'item_attribute', 'min_select' => 1, 'max_select' => 1, 'position' => 0,
            ]],
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/admin/composer/wizard-pages/{$pain->id}")
            ->assertStatus(422)
            ->assertJsonFragment(['page' => ['Cette page est utilisée par « Wraps ». Retirez-la de ces wizards avant de la supprimer.']]);
    }

    /**
     * @param  array<int, string>  $choices
     */
    private function page(string $key, string $label, string $kind, array $choices): WizardPage
    {
        $page = app(\App\Services\Composer\WizardPageService::class)->create([
            'key' => $key,
            'label' => $label,
            'kind' => $kind,
            'min_select' => 1,
            'max_select' => 1,
            'choices' => array_map(fn (string $name): array => ['name' => $name, 'price' => 0], $choices),
        ]);

        return $page->fresh('choices');
    }

    /**
     * @param  array<string, float>  $choices
     */
    private function pageExtras(string $key, string $label, array $choices): WizardPage
    {
        $page = WizardPage::factory()->extraGroup($key)->create([
            'key' => $key, 'label' => $label, 'kind' => 'garnitures', 'min_select' => 0, 'max_select' => 6,
        ]);
        $sort = 0;
        foreach ($choices as $name => $price) {
            WizardPageChoice::factory()->create(['wizard_page_id' => $page->id, 'name' => $name, 'price' => $price, 'sort' => $sort++]);
        }

        return $page->fresh('choices');
    }
}
