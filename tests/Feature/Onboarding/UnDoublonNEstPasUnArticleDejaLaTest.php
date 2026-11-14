<?php

namespace Tests\Feature\Onboarding;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Services\Menu\MenuDraftApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [ONB 2026-08-28] Deux défauts jumeaux de l'application d'une carte lue.
 *
 * ─── 1. « déjà dans votre carte » disait le contraire de la vérité ──────────
 *
 * Le catalogue impose l'unicité du nom d'article. Quand une lecture proposait
 * deux lignes de MÊME nom — cas réel : « Tacos poulet » à 8,50 € dans Tacos, et
 * « Tacos poulet » à 11,50 € dans Menus midi, avec frites et boisson — la
 * première était créée, puis la seconde tombait sur `articleExiste()` = vrai
 * (elle venait d'être créée deux lignes plus haut) et partait dans
 * `articles_deja_la`.
 *
 * Le commerçant lisait « 4 produits ajoutés, 1 déjà dans votre carte ». Son menu
 * midi n'existait pas, et le mot « déjà » lui affirmait précisément le contraire :
 * qu'il le possédait avant, donc qu'il n'avait rien perdu.
 *
 * ─── 2. Les catégories fantômes ────────────────────────────────────────────
 *
 * Les catégories étaient toutes créées AVANT la moindre écriture d'article. Une
 * catégorie dont tous les articles échouaient survivait donc, vide. « Menus midi »
 * n'a qu'un article — exactement celui que le doublon fait perdre — donc la
 * catégorie restait seule, et s'affichait dans le bandeau de la borne. Un client
 * la touche, et ne voit rien.
 *
 * Les deux défauts se nourrissaient l'un l'autre : c'est le produit perdu par le
 * premier qui laissait la catégorie vide du second.
 *
 * CE BANC MORD : neutraliser l'une des deux corrections le fait rougir en nommant
 * le produit perdu ou la catégorie vide.
 */
class UnDoublonNEstPasUnArticleDejaLaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * L'applicateur passe par `ItemRequest` et `ItemCategoryRequest` — donc par
     * l'autorisation. Sans commercant authentifie, tout part en `refus` et ce banc
     * mesurerait un echec de montage plutot que le defaut vise.
     */
    private function preparer(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Tax::factory()->create(['tax_rate' => 10, 'status' => Status::ACTIVE]);

        $admin = \App\Models\User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        \Spatie\Permission\Models\Permission::findOrCreate('items_create', 'sanctum');
        \Spatie\Permission\Models\Permission::findOrCreate('item-categories_create', 'sanctum');
        $admin->givePermissionTo(['items_create', 'item-categories_create']);
        $this->actingAs($admin, 'sanctum');
    }

    /** La forme exacte que le bouchon propose, réduite à ce qui est mesuré ici. */
    private function lignes(): array
    {
        return [
            ['nom' => 'Tacos poulet', 'categorie' => 'Tacos',      'prix' => 8.50,  'description' => null],
            ['nom' => 'Tacos mixte',  'categorie' => 'Tacos',      'prix' => 9.00,  'description' => null],
            // Même nom, autre catégorie, autre prix : un AUTRE produit.
            ['nom' => 'Tacos poulet', 'categorie' => 'Menus midi', 'prix' => 11.50, 'description' => 'Avec frites'],
        ];
    }

    private function appliquer(array $lignes): array
    {
        $taxe = Tax::query()->firstOrFail();

        return app(MenuDraftApplier::class)->appliquer(
            $lignes,
            ['tax_id' => (int) $taxe->id, 'item_type' => 1]
        );
    }

    public function test_le_second_produit_de_meme_nom_est_signale_et_non_avale(): void
    {
        $this->preparer();

        $rapport = $this->appliquer($this->lignes());

        $this->assertArrayHasKey(
            'doublons_dans_la_lecture',
            $rapport,
            "Le rapport doit distinguer un doublon INTERNE d'un article préexistant."
        );

        $this->assertCount(
            1,
            $rapport['doublons_dans_la_lecture'],
            "La troisième ligne porte le nom de la première : elle ne peut pas être créée,\n"
            . "et le commerçant doit l'apprendre. La ranger dans « déjà dans votre carte »\n"
            . "lui affirmait qu'il possédait déjà un produit qu'il venait de perdre."
        );

        $this->assertSame('Tacos poulet', $rapport['doublons_dans_la_lecture'][0]['ligne']);
        $this->assertStringContainsString(
            'Renommez',
            $rapport['doublons_dans_la_lecture'][0]['raison'],
            'Le message doit dire quoi faire, pas seulement constater.'
        );

        $this->assertSame(
            [],
            $rapport['articles_deja_la'],
            "Aucun de ces produits n'existait avant : rien ne peut être « déjà là »."
        );
    }

    public function test_un_article_vraiment_preexistant_reste_deja_la(): void
    {
        $this->preparer();

        // La vraie idempotence : le commerçant reclique sur « Appliquer ».
        $this->appliquer([$this->lignes()[0]]);
        $rapport = $this->appliquer([$this->lignes()[0]]);

        $this->assertSame(
            ['Tacos poulet'],
            $rapport['articles_deja_la'],
            "Recliquer ne doit rien recréer, et doit le dire comme « déjà là » — pas\n"
            . "comme un doublon à renommer, sinon on inquiète pour rien."
        );

        $this->assertSame([], $rapport['doublons_dans_la_lecture']);
    }

    public function test_aucune_categorie_vide_ne_survit_a_une_ligne_perdue(): void
    {
        $this->preparer();

        $this->appliquer($this->lignes());

        $menusMidi = ItemCategory::query()->where('name', 'Menus midi')->first();

        $this->assertNull(
            $menusMidi,
            "« Menus midi » n'a qu'un seul article, et c'est celui que le doublon de nom\n"
            . "fait perdre. La catégorie était créée AVANT les articles, donc elle survivait\n"
            . "vide — et s'affichait dans le bandeau de la borne, où un client la touche et\n"
            . 'ne voit rien.'
        );

        // Les catégories réellement utilisées, elles, doivent bien exister.
        $this->assertNotNull(
            ItemCategory::query()->where('name', 'Tacos')->first(),
            'La catégorie qui a reçu des articles doit exister.'
        );

        $this->assertSame(
            2,
            Item::query()->count(),
            'Deux produits distincts, pas trois : le troisième porte un nom déjà pris.'
        );
    }

    public function test_une_categorie_dont_tous_les_articles_echouent_nest_pas_creee(): void
    {
        $this->preparer();

        // Un seul article, au nom vide : il ne peut pas être écrit. Sa catégorie ne
        // doit donc jamais voir le jour.
        $rapport = $this->appliquer([
            ['nom' => '', 'categorie' => 'Desserts maison', 'prix' => 4.00, 'description' => null],
        ]);

        $this->assertNull(
            ItemCategory::query()->where('name', 'Desserts maison')->first(),
            "Aucun article n'a abouti dans cette catégorie : la créer laisserait une\n"
            . 'catégorie vide que le commerçant n\'a jamais demandée.'
        );

        $this->assertSame([], $rapport['categories_creees']);
    }
}
