<?php

namespace Tests\Feature\Onboarding;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-13 2026-08-28] L'assistant tenait deux promesses en moins.
 *
 * ═══ DÉFAUT 1 — il refusait 47 articles sur 104 ═══
 *
 * `ExecuteurDeMission::requeteArticle()` rejoue l'état réel du produit — c'est
 * voulu, et c'est ce qui empêche d'écraser par omission. Mais l'état réel n'est pas
 * toujours valide : **47 articles** portent `is_featured = 0`, qui n'est ni
 * `Ask::YES` (5) ni `Ask::NO` (10), pendant que `ItemRequest:93` porte `not_in:0`.
 *
 * Mesuré par catégorie sur la base en service : Boissons 15/15, Suppléments 10/10,
 * Bols 8/8, Desserts 3/3 — **entièrement bloquées**.
 *
 * Le commerçant tapait « désactivez toutes les Boissons » et lisait « 0 modifié,
 * 15 en échec », sans jamais savoir que la faute venait d'un champ qu'il n'avait
 * pas demandé de toucher.
 *
 * ═══ DÉFAUT 2 — le catalogue finissait à moitié modifié ═══
 *
 * Le `try/catch` était **à l'intérieur** de la clôture `DB::transaction` : les échecs
 * étaient collectés sans jamais faire échouer la transaction, donc les succès
 * partiels étaient commités.
 *
 * Or le docblock de la classe promet, en toutes lettres : « cinquante produits
 * changent ensemble, ou aucun. Un catalogue à moitié modifié serait pire que pas
 * modifié — le commerçant ne saurait pas où il en est. »
 *
 * *Un commentaire qui affirme un comportement que le code n'a pas.* Le motif de la
 * semaine — et celui-ci était de moi.
 */
class UneMissionSAppliqueEntierementOuPasDuToutTest extends TestCase
{
    use RefreshDatabase;

    private User $patron;
    private ItemCategory $categorie;
    private Tax $taxe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->patron = User::factory()->create(['branch_id' => 0]);
        $this->patron->assignRole('Admin');

        foreach (['items', 'items_show', 'items_create', 'items_edit', 'settings'] as $droit) {
            Permission::findOrCreate($droit, 'sanctum');
        }
        $this->patron->givePermissionTo(['items', 'items_show', 'items_create', 'items_edit', 'settings']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->patron, 'sanctum');

        $this->taxe = Tax::factory()->create(['tax_rate' => 10, 'status' => Status::ACTIVE]);
        $this->categorie = ItemCategory::factory()->create([
            'name'   => 'Boissons',
            'status' => Status::ACTIVE,
        ]);
    }

    private function produit(string $nom, int $miseEnAvant): Item
    {
        return Item::factory()->create([
            'name'             => $nom,
            'item_category_id' => $this->categorie->id,
            'tax_id'           => $this->taxe->id,
            'price'            => 2.50,
            'status'           => Status::ACTIVE,
            'is_featured'      => $miseEnAvant,
            'order'            => 1,
        ]);
    }

    public function test_un_article_a_la_valeur_heritee_zero_n_est_plus_refuse(): void
    {
        // LE SCÉNARIO MESURÉ : `is_featured = 0`, une valeur que la règle refuse et
        // que 47 articles du catalogue en service portent pourtant.
        $coca = $this->produit('Coca', miseEnAvant: 0);

        $reponse = $this->postJson('/api/admin/assistant/mission/application', [
            'phrase'       => 'désactivez toutes les Boissons',
            'confirmation' => true,
        ]);

        $reponse->assertOk();

        // Le rapport est enveloppé sous `rapport` : le lire au mauvais niveau
        // rendait `null`, et l'assertion passait pour la mauvaise raison. On vérifie
        // donc d'abord que la clé EXISTE, avant de vérifier son contenu.
        $reponse->assertJsonPath('compris', true);
        $this->assertIsArray($reponse->json('rapport'), 'Le rapport est absent de la réponse.');

        $this->assertSame(
            [],
            $reponse->json('rapport.echecs'),
            "L'assistant refuse encore les articles à `is_featured = 0`.\n"
            . "Quatre catégories entières du catalogue en service sont dans ce cas :\n"
            . 'Boissons, Suppléments, Bols, Desserts.'
        );

        $this->assertSame(
            Status::INACTIVE,
            (int) $coca->fresh()->status,
            "La mission n'a pas été appliquée."
        );
    }

    public function test_si_un_seul_produit_echoue_rien_n_est_ecrit(): void
    {
        // Deux produits sains, un cassé. Avant, on obtenait « 2 modifiés, 1 en
        // échec » — et les 2 étaient bel et bien commités.
        $bons = [$this->produit('Fanta', Ask::NO), $this->produit('Sprite', Ask::NO)];

        $casse = $this->produit('Eau', Ask::NO);
        // Une taxe absente fait échouer la validation à coup sûr, sans dépendre du
        // détail d'une autre règle : le produit reste sinon parfaitement normal.
        $casse->forceFill(['tax_id' => null])->saveQuietly();

        $etatsAvant = collect($bons)->map(fn (Item $i) => (int) $i->fresh()->status)->all();

        $reponse = $this->postJson('/api/admin/assistant/mission/application', [
            'phrase'       => 'désactivez toutes les Boissons',
            'confirmation' => true,
        ]);

        $reponse->assertOk();

        $this->assertSame(
            0,
            $reponse->json('rapport.applique'),
            "Le rapport annonce des modifications alors qu'un produit a échoué."
        );

        $this->assertNotEmpty(
            $reponse->json('rapport.echecs'),
            'Le produit en échec doit être listé pour que le commerçant sache quoi corriger.'
        );

        // ET LA VÉRIFICATION QUI COMPTE : la base n'a pas bougé.
        foreach ($bons as $index => $produit) {
            $this->assertSame(
                $etatsAvant[$index],
                (int) $produit->fresh()->status,
                "Le catalogue a été modifié à moitié : `{$produit->name}` a changé\n"
                . "alors qu'un autre produit de la même mission a échoué.\n"
                . "C'est exactement ce que le docblock de la classe déclare impossible."
            );
        }
    }

    public function test_le_rapport_explique_pourquoi_rien_n_a_bouge(): void
    {
        // Un rapport qui dit « 0 modifié » sans dire pourquoi laisse le commerçant
        // croire que sa phrase n'a pas été comprise.
        $this->produit('Ice Tea', Ask::NO);
        $casse = $this->produit('Limonade', Ask::NO);
        $casse->forceFill(['tax_id' => null])->saveQuietly();

        $resume = (string) $this->postJson('/api/admin/assistant/mission/application', [
            'phrase'       => 'désactivez toutes les Boissons',
            'confirmation' => true,
        ])->json('rapport.resume');

        $this->assertStringContainsString(
            'entièrement ou pas du tout',
            str_replace(['entierement'], ['entièrement'], $resume),
            "Le résumé ne dit pas POURQUOI rien n'a bougé : « {$resume} »"
        );
    }
}
