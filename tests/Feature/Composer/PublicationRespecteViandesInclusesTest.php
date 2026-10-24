<?php

namespace Tests\Feature\Composer;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\WizardPage;
use App\Models\WizardPageChoice;
use App\Services\Composer\ComposerProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [VG-2026-10-21 C3 · OWNER 2026-10-22] La publication d'un wizard de catégorie (écran d'administration →
 * ComposerProfileService::publish → WizardPageMaterializer::materializeCategory + recopie sur chaque produit) ne doit
 * JAMAIS rallumer un emplacement de viande gratuite au-delà du nombre décidé par le propriétaire
 * (config/menu_meat_rules.php) : sandwich Cayenne/Classique = 1 (la 2e viande est l'extra payant 2,50 €),
 * Tacos M = 1, L = 2, XL = 3, Méga/Terminator = 2.
 *
 * Avant : la migration 2026_10_22_130000 éteignait « Viande 2 » sur la Cayenne ; republier le wizard « Sandwichs »
 * remettait ses variations à 0 € ACTIVES (rowChanges → status ACTIVE) et recopiait l'étape viande_2 ALLUMÉE sur le
 * profil produit — la 2e viande redevenait gratuite sur les trois canaux.
 *
 * @group menu
 */
class PublicationRespecteViandesInclusesTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_22_130000_viandes_incluses_decision_proprietaire.php';

    /** @var array<string, ItemAttribute> */
    private array $attr = [];

    /** @var array<string, Item> */
    private array $item = [];

    private ItemWizardProfile $profilSandwichs;

    private ItemWizardProfile $profilTacos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        foreach (['Viande 1', 'Viande 2', 'Viande 3', 'Sauce'] as $nom) {
            $this->attr[$nom] = ItemAttribute::create([
                'name' => $nom, 'status' => Status::ACTIVE, 'min_select' => 0, 'max_select' => 1,
            ]);
        }

        $pages = [];
        foreach (['viande' => 'Viande 1', 'viande_2' => 'Viande 2', 'viande_3' => 'Viande 3'] as $cle => $attribut) {
            $pages[$cle] = WizardPage::factory()->create([
                'key' => $cle, 'label' => $attribut, 'kind' => 'viande', 'source_type' => 'item_attribute',
                'item_attribute_id' => $this->attr[$attribut]->id, 'min_select' => $cle === 'viande' ? 1 : 0, 'max_select' => 1,
            ]);
            foreach (['Poulet mariné', 'Cordon Bleu'] as $i => $viande) {
                WizardPageChoice::factory()->create(['wizard_page_id' => $pages[$cle]->id, 'name' => $viande, 'price' => 0, 'sort' => $i]);
            }
        }
        $pages['sauce'] = WizardPage::factory()->create([
            'key' => 'sauce', 'label' => 'Choisis ta sauce', 'kind' => 'sauce', 'source_type' => 'item_attribute',
            'item_attribute_id' => $this->attr['Sauce']->id, 'min_select' => 1, 'max_select' => 2,
        ]);
        foreach (['Algérienne', 'Blanche'] as $i => $sauce) {
            WizardPageChoice::factory()->create(['wizard_page_id' => $pages['sauce']->id, 'name' => $sauce, 'price' => 0, 'sort' => $i]);
        }

        $sandwichs = ItemCategory::factory()->create(['name' => 'Sandwichs', 'status' => Status::ACTIVE]);
        $tacos = ItemCategory::factory()->create(['name' => 'Tacos', 'status' => Status::ACTIVE]);

        $this->profilSandwichs = $this->profilCategorie($sandwichs, $pages, ['viande', 'viande_2', 'sauce']);
        $this->profilTacos = $this->profilCategorie($tacos, $pages, ['viande', 'viande_2', 'viande_3', 'sauce']);

        $plan = [
            ['Cayenne', $sandwichs, ['Viande 1', 'Viande 2']],
            ['Méga', $sandwichs, ['Viande 1', 'Viande 2']],
            ['Tacos M', $tacos, ['Viande 1', 'Viande 2', 'Viande 3']],
            ['Tacos L', $tacos, ['Viande 1', 'Viande 2', 'Viande 3']],
            ['Tacos XL', $tacos, ['Viande 1', 'Viande 2', 'Viande 3']],
        ];
        foreach ($plan as [$nom, $categorie, $attributs]) {
            $item = Item::factory()->create([
                'name' => $nom, 'item_category_id' => $categorie->id, 'status' => Status::ACTIVE, 'tax_id' => null,
            ]);
            $this->item[$nom] = $item;
            // L'état du 2026-09-02 (matérialisation d'avant la décision) : chaque emplacement porte des viandes à 0 €.
            foreach ([...$attributs, 'Sauce'] as $attribut) {
                $choix = $attribut === 'Sauce' ? ['Algérienne', 'Blanche'] : ['Poulet mariné', 'Cordon Bleu'];
                foreach ($choix as $nomChoix) {
                    DB::table('item_variations')->insert([
                        'item_id' => $item->id, 'item_attribute_id' => $this->attr[$attribut]->id, 'name' => $nomChoix,
                        'price' => 0, 'status' => Status::ACTIVE, 'visible_on' => json_encode(['pos', 'kiosk']),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            DB::table('item_extras')->insert([
                'item_id' => $item->id, 'name' => 'Viande supplémentaire', 'price' => 2.50, 'status' => Status::ACTIVE,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @param array<string, WizardPage> $pages @param array<int, string> $cles */
    private function profilCategorie(ItemCategory $categorie, array $pages, array $cles): ItemWizardProfile
    {
        $profil = ItemWizardProfile::factory()->forCategory($categorie)->create(['template' => 'custom']);
        foreach ($cles as $position => $cle) {
            $page = $pages[$cle];
            ItemWizardStep::factory()->create([
                'profile_id' => $profil->id, 'wizard_page_id' => $page->id, 'step_key' => $cle, 'label' => $page->label,
                'source_type' => 'item_attribute', 'source_ref' => (string) $page->item_attribute_id,
                'source_item_attribute_id' => $page->item_attribute_id, 'position' => $position, 'is_active' => true,
                'min_select' => $page->min_select, 'max_select' => $page->max_select,
            ]);
        }

        return $profil;
    }

    private function publier(ItemWizardProfile $profil): void
    {
        app(ComposerProfileService::class)->publish($profil->fresh('steps'));
    }

    private function actives(string $article, string $attribut): int
    {
        return DB::table('item_variations')
            ->where('item_id', $this->item[$article]->id)
            ->where('item_attribute_id', $this->attr[$attribut]->id)
            ->where('status', Status::ACTIVE)
            ->whereNull('deleted_at')
            ->count();
    }

    /** @return array<string, int> step_key => is_active, du profil PUBLIÉ que la caisse et la borne lisent (version max) */
    private function etapesPubliees(string $article): array
    {
        $profil = ItemWizardProfile::query()
            ->where('item_id', $this->item[$article]->id)
            ->where('is_published', true)
            ->orderByDesc('version')
            ->firstOrFail();

        return $profil->steps()->get()->mapWithKeys(fn ($s) => [$s->step_key => (int) $s->is_active])->all();
    }

    public function test_republier_le_wizard_de_categorie_ne_rallume_aucune_viande_gratuite_en_trop(): void
    {
        // Les clones produits publiés existent (première publication), puis le propriétaire tranche (migration).
        $this->publier($this->profilSandwichs);
        $this->publier($this->profilTacos);
        (require base_path(self::MIGRATION))->up();
        $this->assertSame(0, $this->actives('Cayenne', 'Viande 2'), 'prérequis : la migration a éteint la Viande 2 gratuite');

        // L'admin republie les deux wizards de catégorie depuis l'écran d'administration.
        $this->publier($this->profilSandwichs->fresh());
        $this->publier($this->profilTacos->fresh());

        // Aucun emplacement gratuit au-delà de la règle du propriétaire.
        $this->assertSame(0, $this->actives('Cayenne', 'Viande 2'), 'Cayenne : la 2e viande gratuite a été rallumée');
        $this->assertSame(0, $this->actives('Tacos M', 'Viande 2'), 'Tacos M : Viande 2 gratuite rallumée');
        $this->assertSame(0, $this->actives('Tacos M', 'Viande 3'), 'Tacos M : Viande 3 gratuite rallumée');
        $this->assertSame(0, $this->actives('Tacos L', 'Viande 3'), 'Tacos L : Viande 3 gratuite rallumée');

        // Les emplacements décidés et les autres étapes sont publiés normalement.
        $this->assertSame(2, $this->actives('Cayenne', 'Viande 1'));
        $this->assertSame(2, $this->actives('Méga', 'Viande 2'), 'Méga : 2 viandes imposées');
        $this->assertSame(2, $this->actives('Tacos L', 'Viande 2'));
        $this->assertSame(2, $this->actives('Tacos XL', 'Viande 3'));
        foreach (array_keys($this->item) as $article) {
            $this->assertSame(2, $this->actives($article, 'Sauce'), "$article : sauces publiées");
        }

        // Le profil PRODUIT recopié (celui que lisent caisse et borne) n'a pas l'étape gratuite en trop allumée.
        $this->assertSame(['viande' => 1, 'viande_2' => 0, 'sauce' => 1], $this->etapesPubliees('Cayenne'));
        $this->assertSame(['viande' => 1, 'viande_2' => 1, 'sauce' => 1], $this->etapesPubliees('Méga'));
        $this->assertSame(['viande' => 1, 'viande_2' => 0, 'viande_3' => 0, 'sauce' => 1], $this->etapesPubliees('Tacos M'));
        $this->assertSame(['viande' => 1, 'viande_2' => 1, 'viande_3' => 0, 'sauce' => 1], $this->etapesPubliees('Tacos L'));
        $this->assertSame(['viande' => 1, 'viande_2' => 1, 'viande_3' => 1, 'sauce' => 1], $this->etapesPubliees('Tacos XL'));

        // Le wizard de CATÉGORIE lui-même est inchangé : la règle s'applique produit par produit.
        $this->assertTrue((bool) $this->profilSandwichs->fresh('steps')->steps->firstWhere('step_key', 'viande_2')->is_active);
    }

    public function test_une_premiere_publication_ne_cree_pas_l_emplacement_gratuit_en_trop(): void
    {
        // Catalogue vierge de toute viande : seul le wizard décide de ce qui est écrit.
        DB::table('item_variations')->whereIn('item_attribute_id', [
            $this->attr['Viande 1']->id, $this->attr['Viande 2']->id, $this->attr['Viande 3']->id,
        ])->delete();

        $this->publier($this->profilSandwichs);
        $this->publier($this->profilTacos);

        $this->assertSame(0, $this->actives('Cayenne', 'Viande 2'));
        $this->assertSame(0, $this->actives('Tacos M', 'Viande 2'));
        $this->assertSame(0, $this->actives('Tacos M', 'Viande 3'));
        $this->assertSame(0, $this->actives('Tacos L', 'Viande 3'));
        $this->assertSame(2, $this->actives('Cayenne', 'Viande 1'));
        $this->assertSame(2, $this->actives('Méga', 'Viande 2'));
        $this->assertSame(2, $this->actives('Tacos XL', 'Viande 3'));
    }
}
