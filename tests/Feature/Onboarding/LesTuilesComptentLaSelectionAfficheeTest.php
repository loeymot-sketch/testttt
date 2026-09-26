<?php

namespace Tests\Feature\Onboarding;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-11 2026-08-28] Un même écran ne publie pas deux chiffres pour la même chose.
 *
 * La barre du catalogue porte trois tuiles côte à côte :
 *
 *   « Produits »       total du paginator — donc FILTRÉ (`ItemListComponent.vue:490`)
 *   « Actifs »         `meta.available_count` — compteur serveur, NON FILTRÉ
 *   « Indisponibles »  `meta.unavailable_count` — idem
 *
 * `availabilityCounts()` faisait `Item::query()->where('status', ACTIVE)` et rien
 * d'autre : ni catégorie, ni nom, ni type. Le commerçant filtrait sur « Burgers » et
 * lisait, sur la même ligne : **« 5 Produits »** à côté de **« 57 Actifs »**.
 *
 * Et la tuile « 57 » est un BOUTON (`ItemListComponent.vue:27`,
 * `@click.prevent="filterActiveItems"`) : il cliquait sur 57, la liste lui en montrait
 * 5. Aucun moyen, depuis l'écran, de savoir lequel des deux chiffres était sa carte.
 *
 * Les tuiles comptent désormais la SÉLECTION AFFICHÉE. Le filtre `status` en est
 * délibérément exclu : ces tuiles SONT la répartition par statut, les filtrer par
 * statut serait circulaire.
 */
class LesTuilesComptentLaSelectionAfficheeTest extends TestCase
{
    use RefreshDatabase;

    private ItemCategory $burgers;
    private ItemCategory $boissons;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $taxe = Tax::factory()->create(['status' => Status::ACTIVE]);
        $this->burgers = ItemCategory::factory()->create(['name' => 'Burgers', 'status' => Status::ACTIVE]);
        $this->boissons = ItemCategory::factory()->create(['name' => 'Boissons', 'status' => Status::ACTIVE]);

        // 3 burgers actifs, 1 burger désactivé, 6 boissons actives.
        foreach (range(1, 3) as $i) {
            Item::factory()->create([
                'name' => 'Burger ' . $i, 'item_category_id' => $this->burgers->id,
                'tax_id' => $taxe->id, 'status' => Status::ACTIVE, 'is_available' => true,
            ]);
        }

        Item::factory()->create([
            'name' => 'Burger hiver', 'item_category_id' => $this->burgers->id,
            'tax_id' => $taxe->id, 'status' => Status::INACTIVE, 'is_available' => true,
        ]);

        foreach (range(1, 6) as $i) {
            Item::factory()->create([
                'name' => 'Boisson ' . $i, 'item_category_id' => $this->boissons->id,
                'tax_id' => $taxe->id, 'status' => Status::ACTIVE, 'is_available' => true,
            ]);
        }
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Permission::findOrCreate('items', 'sanctum');
        $admin->givePermissionTo(['items']);

        return $admin;
    }

    private function catalogue(array $parametres = []): array
    {
        $reponse = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/item?' . http_build_query($parametres + ['paginate' => 0]));

        $reponse->assertOk();

        return [
            'lignes' => count($reponse->json('data') ?? []),
            'actifs' => $reponse->json('meta.available_count'),
        ];
    }

    public function test_sans_filtre_les_deux_chiffres_portent_sur_toute_la_carte(): void
    {
        $vue = $this->catalogue();

        // 10 articles au total (9 actifs + 1 désactivé), 9 actifs.
        $this->assertSame(10, $vue['lignes']);
        $this->assertSame(9, $vue['actifs']);
    }

    public function test_en_filtrant_par_categorie_les_tuiles_suivent(): void
    {
        // LE DÉFAUT. Avant, cette assertion donnait 4 lignes et 9 actifs : le
        // commerçant lisait « 4 Produits » à côté de « 9 Actifs » sur la même barre.
        $vue = $this->catalogue(['item_category_id' => $this->burgers->id]);

        $this->assertSame(4, $vue['lignes'], 'La liste montre les 4 burgers.');

        $this->assertSame(
            3,
            $vue['actifs'],
            "La tuile « Actifs » doit compter les burgers ACTIFS (3), pas toute la\n"
            . "carte (9). Sinon l'écran publie deux chiffres pour la même sélection,\n"
            . "et la tuile — qui est un bouton — mène à une liste qui la contredit."
        );
    }

    public function test_en_filtrant_par_nom_les_tuiles_suivent_aussi(): void
    {
        $vue = $this->catalogue(['name' => 'Boisson']);

        $this->assertSame(6, $vue['lignes']);
        $this->assertSame(6, $vue['actifs']);
    }

    public function test_le_filtre_de_statut_ne_s_applique_PAS_aux_tuiles(): void
    {
        // Contrôle de périmètre. Ces tuiles SONT la répartition par statut : les
        // filtrer par statut serait circulaire — « Actifs » vaudrait le total, ou
        // zéro, selon le filtre posé, et ne dirait plus rien.
        $vue = $this->catalogue([
            'item_category_id' => $this->burgers->id,
            'status'           => Status::INACTIVE,
        ]);

        $this->assertSame(1, $vue['lignes'], 'La liste montre le seul burger désactivé.');

        $this->assertSame(
            3,
            $vue['actifs'],
            "La tuile « Actifs » doit rester la répartition des burgers (3 actifs),\n"
            . "et non 0 : filtrer par statut une tuile qui compte les statuts n'a\n"
            . 'aucun sens pour le commerçant.'
        );
    }
}
