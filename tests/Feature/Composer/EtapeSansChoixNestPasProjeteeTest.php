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
use App\Services\Composer\ComposerProfileProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [INCIDENT COMPOSEUR 2026-09-03/04] Une étape sans le moindre choix est une IMPASSE :
 * l'écran affiche un titre, aucune tuile, et si l'étape est obligatoire le client ne peut
 * plus avancer. Deux incidents réels en vingt-quatre heures ont eu cette forme :
 *
 *   · 2026-09-03 22:27 — les 45 viandes de Cayenne / Suprême / Sandwich Classique éteintes
 *     d'un coup, l'étape « Viande 1 » laissée obligatoire : trois produits phares invendables.
 *   · 2026-09-04 — les six burgers affichaient « Choisis ta viande » avec ZÉRO tuile, après
 *     que leurs variations de viande eurent été détachées alors que leur profil publié
 *     gardait l'étape active. Constaté par le propriétaire en service, capture à l'appui.
 *
 * `ComposerProfileProjection::project()` ne filtrait que sur `is_active` et la visibilité de
 * surface — jamais sur l'existence d'un choix. Une étape vide était donc projetée telle
 * quelle jusqu'à l'écran.
 *
 * Règle posée ici : **une étape dont la liste de choix est vide n'est pas projetée**, quel
 * que soit son type de source (`item_attribute`, `extra_group`, `addon`) — les trois
 * construisent une vraie liste, une liste vide ne propose donc rien à cliquer dans tous les
 * cas. Une étape qui a des choix reste projetée intacte, y compris quand ils sont en
 * rupture : « indisponible » s'affiche et s'explique, ce n'est pas une impasse.
 */
class EtapeSansChoixNestPasProjeteeTest extends TestCase
{
    use RefreshDatabase;

    private function item(string $nom = 'Cheese Burger'): Item
    {
        $categorie = ItemCategory::create([
            'name' => 'Burgers', 'slug' => 'burgers-'.uniqid(), 'status' => Status::ACTIVE,
        ]);

        return Item::create([
            'name' => $nom, 'slug' => strtolower($nom).'-'.uniqid(),
            'item_category_id' => $categorie->id, 'price' => 6.50, 'status' => Status::ACTIVE,
        ]);
    }

    private function profil(Item $item): ItemWizardProfile
    {
        return ItemWizardProfile::create([
            'item_id' => $item->id, 'template' => 'sandwich', 'version' => 1, 'is_published' => true,
        ]);
    }

    private function etape(ItemWizardProfile $p, string $cle, string $type, ?string $ref, int $min, int $pos, ?string $addonRole = null): ItemWizardStep
    {
        return ItemWizardStep::create([
            'profile_id' => $p->id, 'step_key' => $cle, 'label' => ucfirst($cle),
            'source_type' => $type, 'source_ref' => $ref, 'min_select' => $min, 'max_select' => 3,
            'position' => $pos, 'is_active' => true, 'addon_role' => $addonRole,
        ]);
    }

    /** @return array<int,string> les step_key réellement projetés */
    private function clesProjetees(ItemWizardProfile $profil, Item $item, string $surface = 'kiosk'): array
    {
        $profil->load('steps');
        $item->load(['variations.itemAttribute', 'extras', 'addons.addonItem']);
        $projete = (new ComposerProfileProjection())->project($profil, $item, $surface);

        return array_column($projete['steps'] ?? [], 'step_key');
    }

    /** @test */
    public function une_etape_viande_sans_aucune_variation_n_est_pas_projetee(): void
    {
        // Reproduction exacte du défaut burger constaté à l'écran par le propriétaire.
        $item = $this->item();
        $profil = $this->profil($item);
        $this->etape($profil, 'viande', 'item_attribute', 'Viande 1', 1, 1);

        $cles = $this->clesProjetees($profil, $item);

        $this->assertNotContains('viande', $cles, 'une étape viande sans une seule tuile ne doit pas atteindre l\'écran');
        $this->assertSame([], $cles);
    }

    /** @test */
    public function une_etape_viande_avec_des_choix_reste_projetee(): void
    {
        // Le garde ne doit RIEN retirer d'utile : c'est la moitié qui compte.
        $item = $this->item('Terminator');
        $attribut = ItemAttribute::create([
            'name' => 'Viande 1', 'min_select' => 1, 'max_select' => 3, 'status' => Status::ACTIVE,
        ]);
        ItemVariation::create([
            'item_id' => $item->id, 'item_attribute_id' => $attribut->id,
            'name' => 'Poulet mariné', 'price' => 0, 'status' => Status::ACTIVE,
        ]);
        $profil = $this->profil($item);
        $this->etape($profil, 'viande', 'item_attribute', 'Viande 1', 1, 1);

        $this->assertSame(['viande'], $this->clesProjetees($profil, $item));
    }

    /** @test */
    public function une_etape_dont_les_choix_sont_reserves_a_la_caisse_disparait_de_la_borne_seulement(): void
    {
        // Le piège de la règle « les viandes du Cayenne seulement à la caisse » : la borne
        // ne doit pas afficher une étape qu'elle n'a pas le droit de garnir.
        $item = $this->item('Cayenne');
        $attribut = ItemAttribute::create([
            'name' => 'Viande 1', 'min_select' => 1, 'max_select' => 3, 'status' => Status::ACTIVE,
        ]);
        ItemVariation::create([
            'item_id' => $item->id, 'item_attribute_id' => $attribut->id,
            'name' => 'Viande Hachée', 'price' => 0, 'status' => Status::ACTIVE, 'visible_on' => ['pos'],
        ]);
        $profil = $this->profil($item);
        $this->etape($profil, 'viande', 'item_attribute', 'Viande 1', 1, 1);

        $this->assertSame([], $this->clesProjetees($profil, $item, 'kiosk'), 'la borne n\'a rien à proposer');
        $this->assertSame(['viande'], $this->clesProjetees($profil, $item, 'pos'), 'la caisse, elle, voit le choix');
    }

    /** @test */
    public function une_etape_de_supplements_sans_extra_n_est_pas_projetee_mais_l_autre_reste(): void
    {
        $item = $this->item('Tacos M');
        ItemExtra::create([
            'item_id' => $item->id, 'name' => 'Cheddar', 'price' => 0.9,
            'status' => Status::ACTIVE, 'group_label' => 'supplement',
        ]);
        $profil = $this->profil($item);
        $this->etape($profil, 'supplements', 'extra_group', 'supplement', 0, 1);
        $this->etape($profil, 'garnitures', 'extra_group', 'crudite', 0, 2);

        $cles = $this->clesProjetees($profil, $item);

        $this->assertContains('supplements', $cles, 'le groupe qui a un extra reste');
        $this->assertNotContains('garnitures', $cles, 'le groupe vide disparaît');
    }

    /** @test */
    public function une_etape_formule_sans_aucun_addon_n_est_pas_projetee(): void
    {
        // « Choisis ta formule » sans une seule formule à choisir est une page morte.
        $item = $this->item('Sandwich Classique');
        $profil = $this->profil($item);
        $this->etape($profil, 'menu', 'addon', 'menu_component', 0, 1, 'menu_component');

        $this->assertSame([], $this->clesProjetees($profil, $item));
    }

    /** @test */
    public function l_ordre_et_le_contenu_des_etapes_survivantes_sont_intacts(): void
    {
        // Filtrer ne doit ni réordonner ni altérer ce qui reste.
        $item = $this->item('Galette Normale');
        $attribut = ItemAttribute::create([
            'name' => 'Viande 1', 'min_select' => 1, 'max_select' => 1, 'status' => Status::ACTIVE,
        ]);
        ItemVariation::create([
            'item_id' => $item->id, 'item_attribute_id' => $attribut->id,
            'name' => 'Tenders', 'price' => 0, 'status' => Status::ACTIVE,
        ]);
        ItemExtra::create([
            'item_id' => $item->id, 'name' => 'Cheddar', 'price' => 0.9,
            'status' => Status::ACTIVE, 'group_label' => 'supplement',
        ]);
        $profil = $this->profil($item);
        $this->etape($profil, 'viande', 'item_attribute', 'Viande 1', 1, 1);
        $this->etape($profil, 'garnitures', 'extra_group', 'crudite', 0, 2);   // vide
        $this->etape($profil, 'supplements', 'extra_group', 'supplement', 0, 3);

        $this->assertSame(['viande', 'supplements'], $this->clesProjetees($profil, $item));
    }
}
