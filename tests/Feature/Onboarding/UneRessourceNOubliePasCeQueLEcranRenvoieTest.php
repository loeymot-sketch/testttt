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
 * [ONB-02 2026-08-28] Une ressource ne doit pas omettre un champ que l'écran renvoie.
 *
 * C'EST LE MOTIF LE PLUS RÉCURRENT DE CETTE SESSION — trois occurrences en un jour,
 * toutes avec la même mécanique et le même coût :
 *
 *   1. `BranchResource` omettait `siret` → corriger un téléphone EFFAÇAIT l'identité
 *      fiscale, et le ticket sortait non conforme.
 *   2. `ItemCategoryResource` omettait `default_menu_kiosk` et `sauce_included_menu`
 *      → corriger une faute dans le NOM d'une catégorie éteignait la formule par
 *      défaut de la borne ET ajoutait une étape « sauce frites » au parcours client.
 *   3. `SimpleItemResource` omettait `channels` → les trois cases « Caisse / Borne /
 *      Web » revenaient toujours décochées, et en cocher une RETIRAIT les autres :
 *      l'article disparaissait d'une surface de vente.
 *
 * LA MÉCANIQUE, invariable : le formulaire s'hydrate depuis la ressource, ne trouve
 * pas la clé, prend son repli (`?? ""`, `?? 0`, `?? []`), et le renvoie tel quel à
 * l'enregistrement. Le serveur l'accepte — c'est une valeur valide — et écrase.
 *
 * Aucun de ces trois défauts ne lève quoi que ce soit. L'écran affiche un succès.
 */
class UneRessourceNOubliePasCeQueLEcranRenvoieTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->admin = User::factory()->create(['branch_id' => 0]);
        $this->admin->assignRole('Admin');
        foreach (['settings', 'items', 'items_edit'] as $droit) {
            Permission::findOrCreate($droit, 'sanctum');
        }
        $this->admin->givePermissionTo(['settings', 'items', 'items_edit']);
    }

    public function test_la_categorie_rend_ses_deux_reglages_de_borne(): void
    {
        $categorie = ItemCategory::factory()->create([
            'name'                => 'Nos Tacos',
            'status'              => Status::ACTIVE,
            'default_menu_kiosk'  => true,
            'sauce_included_menu' => true,
        ]);

        $reponse = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/setting/item-category/show/' . $categorie->id);

        $this->assertContains($reponse->status(), [200, 201]);

        $fiche = $reponse->json('data') ?? $reponse->json() ?? [];

        foreach (['default_menu_kiosk', 'sauce_included_menu'] as $champ) {
            $this->assertArrayHasKey(
                $champ,
                $fiche,
                "`{$champ}` est absent de la réponse. Le formulaire fera `yn(undefined)`\n"
                . "= 0 et le reposera tel quel : renommer la catégorie éteindra ce\n"
                . 'réglage de la borne, sans un mot.'
            );
        }

        $this->assertTrue((bool) $fiche['default_menu_kiosk'], 'La valeur doit être fidèle.');
        $this->assertTrue((bool) $fiche['sauce_included_menu'], 'La valeur doit être fidèle.');
    }

    public function test_la_liste_des_articles_rend_les_canaux_de_vente(): void
    {
        $taxe = Tax::factory()->create(['status' => Status::ACTIVE]);
        $categorie = ItemCategory::factory()->create(['status' => Status::ACTIVE]);

        Item::factory()->create([
            'name'             => 'Tacos caisse seulement',
            'item_category_id' => $categorie->id,
            'tax_id'           => $taxe->id,
            'status'           => Status::ACTIVE,
            'channels'         => ['pos'],
        ]);

        $reponse = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/item?paginate=0');

        $reponse->assertOk();

        $ligne = collect($reponse->json('data'))->firstWhere('name', 'Tacos caisse seulement');

        $this->assertNotNull($ligne, "L'article doit être listé.");

        $this->assertArrayHasKey(
            'channels',
            $ligne,
            "`channels` est absent de la liste, seule source du tiroir d'édition.\n"
            . "Les trois cases reviendront décochées, et en cocher une RETIRERA les\n"
            . "autres : l'article disparaîtra d'une surface de vente."
        );

        $this->assertSame(['pos'], $ligne['channels']);
    }

    /**
     * Garde de source sur les TROIS ressources : c'est l'omission qui cause tout, et
     * elle est facile a reintroduire en « nettoyant » une liste de cles.
     *
     * @dataProvider ressourcesEtChamps
     */
    public function test_la_ressource_expose_toujours_le_champ(string $fichier, string $champ): void
    {
        $source = file_get_contents(app_path('Http/Resources/' . $fichier));

        // Les ressources du dépôt mélangent guillemets simples et doubles : on
        // accepte les deux plutôt que d'imposer un style, qui n'est pas le sujet.
        $this->assertMatchesRegularExpression(
            '/["\']' . preg_quote($champ, '/') . '["\']\s*=>/',
            $source,
            "{$fichier} n'expose plus `{$champ}`.\n"
            . "Le formulaire prendra son repli et l'écrasera au prochain enregistrement,\n"
            . 'sans que rien ne le signale.'
        );
    }

    /** @return array<string, array{0:string, 1:string}> */
    public function ressourcesEtChamps(): array
    {
        return [
            'filiale · siret'            => ['BranchResource.php', 'siret'],
            'filiale · tva intracom'     => ['BranchResource.php', 'vat_intra'],
            'filiale · mention legale'   => ['BranchResource.php', 'legal_footer'],
            'categorie · formule borne'  => ['ItemCategoryResource.php', 'default_menu_kiosk'],
            'categorie · sauce incluse'  => ['ItemCategoryResource.php', 'sauce_included_menu'],
            'article · canaux de vente'  => ['SimpleItemResource.php', 'channels'],
        ];
    }
}
