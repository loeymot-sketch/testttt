<?php

namespace Tests\Feature\Dashboard;

use App\Enums\Status;
use App\Models\Item;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sentinelle — « Total articles menu » compte le menu, pas les lignes de la table.
 *
 * Le défaut, mesuré à l'écran le 2026-08-29 : le tableau de bord affichait **123** et le
 * catalogue **59 produits**, pour ce qu'un exploitant lit comme le même fait.
 * `DashboardService::totalMenuItems()` faisait un `Item::count()` nu : il additionnait les
 * articles actifs, les 64 désactivés et 17 fiches de test.
 *
 * Ce banc vérifie la seule chose qui compte pour l'exploitant — que le chiffre du tableau
 * de bord soit CELUI DU CATALOGUE. Il ne fige pas une implémentation : si la définition du
 * menu change un jour, les deux doivent changer ensemble, et c'est exactement ce que
 * l'assertion exige.
 */
class TotalMenuItemsCompteLeMenuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * [fusion 2026-09-02] La catégorie est désormais EXPLICITE.
     *
     * `Item::factory()` tire un nom de catégorie en latin de Faker, et plusieurs de ces mots
     * figurent dans la liste d'exclusion de `constrainVisibleCatalog()`
     * (`FAKER_LATIN_CATEGORY_NAMES`). Depuis que « menu » signifie « catégories client », ce
     * tirage rendait le banc NON DÉTERMINISTE : vert en isolation, rouge dans la suite
     * complète, sans que le produit ait changé. On lui donne une catégorie de vente normale.
     */
    private function creerArticle(int $statut): Item
    {
        // Pas de cache statique ici : `RefreshDatabase` vide la base entre deux tests, et
        // un objet mémorisé pointerait sur une ligne disparue.
        $categorie = \App\Models\ItemCategory::query()->firstOrCreate(
            ['name' => 'Sandwichs'],
            \App\Models\ItemCategory::factory()->make(['name' => 'Sandwichs'])->getAttributes()
        );

        return Item::factory()->create([
            'status' => $statut,
            'item_category_id' => $categorie->id,
        ]);
    }

    /** @test */
    public function il_ignore_les_articles_desactives(): void
    {
        $this->creerArticle(Status::ACTIVE);
        $this->creerArticle(Status::ACTIVE);
        $this->creerArticle(Status::INACTIVE);
        $this->creerArticle(Status::INACTIVE);
        $this->creerArticle(Status::INACTIVE);

        $affiche = app(DashboardService::class)->totalMenuItems();

        $this->assertSame(
            2,
            $affiche,
            "Le tableau de bord annonce « Total articles menu » : il doit compter les articles "
            . "SERVIS. Ici 2 actifs et 3 désactivés — un « {$affiche} » signifie que les fiches "
            . 'désactivées sont recomptées, et le commerçant lit un menu plus gros que le sien.',
        );
    }

    /** @test */
    public function il_donne_le_meme_nombre_que_le_catalogue(): void
    {
        foreach ([Status::ACTIVE, Status::ACTIVE, Status::ACTIVE, Status::INACTIVE] as $s) {
            $this->creerArticle($s);
        }

        // La définition de référence est celle du menu tel qu'un exploitant le lit.
        //
        // [fusion 2026-09-02] Elle a bougé : le menu EXCLUT désormais les catégories de
        // pollution (fiches de test, « Technique (interne — upsell) »), exigence portée par
        // AuditPollutionCategoriesHiddenTest. Ce banc annonce lui-même qu'il ne fige pas une
        // implémentation et que « les deux doivent changer ensemble » : c'est fait ici. Ce
        // qu'il continue de garantir — un même fait, un même nombre — est intact.
        $catalogue = Item::query()
            ->where('status', Status::ACTIVE)
            ->whereHas('category', function ($category) {
                \App\Services\ItemCategoryService::constrainCustomerFacing($category);
            })
            ->count();
        $tableauDeBord = app(DashboardService::class)->totalMenuItems();

        $this->assertSame(
            $catalogue,
            $tableauDeBord,
            "Deux écrans affichent le même fait : le tableau de bord dit {$tableauDeBord}, "
            . "le catalogue dit {$catalogue}. Un même fait doit donner un même nombre, "
            . 'quel que soit l\'écran où on le lit.',
        );
    }

    /** @test */
    public function une_carte_entierement_desactivee_affiche_zero(): void
    {
        $this->creerArticle(Status::INACTIVE);
        $this->creerArticle(Status::INACTIVE);

        // Cas limite qui distingue un vrai filtre d'un simple `count()` : si rien n'est
        // servi, l'écran doit dire zéro, pas « 2 articles au menu ».
        $this->assertSame(0, app(DashboardService::class)->totalMenuItems());
    }
}
