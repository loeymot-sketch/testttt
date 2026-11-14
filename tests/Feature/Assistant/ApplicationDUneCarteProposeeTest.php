<?php

namespace Tests\Feature\Assistant;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use App\Services\Menu\MenuDraftApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-04 2026-08-28] Appliquer une carte proposée par lecture d'image.
 *
 * Le cahier des charges tient en une phrase du propriétaire : **l'IA propose,
 * l'humain valide, le système applique**. Ce banc exerce le troisième temps, celui
 * qui écrit — et donc celui où une erreur coûte vraiment.
 *
 * Trois propriétés sont vérifiées ici, et aucune n'est décorative :
 *
 *   · C3 IDEMPOTENCE — appliquer deux fois la même proposition ne duplique rien.
 *     C'est ce qui rend l'écran utilisable : un commerçant qui doute et reclique ne
 *     doit pas se retrouver avec sa carte en double, sans moyen simple de revenir.
 *
 *   · LA TAXE OBLIGATOIRE D'ONB-02 ATTEINT AUSSI CE CHEMIN. L'applicateur passe par
 *     `ItemRequest`, donc par la règle `tax_id => required` posée en ONB-02. Sans ce
 *     détour, un article arrivé par photo aurait été facturé HORS TAXE en silence
 *     (`PricingService` fait `(int) ($dbItem->tax_id ?? 0)` puis `$taxes[0] ?? null`,
 *     donc 0 % sans alerte). Une nouvelle porte d'écriture qui contourne la
 *     FormRequest rouvrirait exactement le trou qu'ONB-02 a fermé — c'est le risque
 *     principal de toute la mission ONB-04, et il est verrouillé ici.
 *
 *   · AUCUNE ÉCRITURE SANS VALIDATION HUMAINE. La taxe et le type d'article ne
 *     viennent JAMAIS de la lecture d'image : ce sont des choix du commerçant. Une
 *     proposition sans eux est refusée, avec un motif lisible.
 */
class ApplicationDUneCarteProposeeTest extends TestCase
{
    use RefreshDatabase;

    private Tax $taxe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->taxe = Tax::factory()->create(['tax_rate' => 10, 'status' => \App\Enums\Status::ACTIVE]);

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Permission::findOrCreate('items_create', 'sanctum');
        Permission::findOrCreate('item-categories_create', 'sanctum');
        $admin->givePermissionTo(['items_create', 'item-categories_create']);
        $this->actingAs($admin, 'sanctum');
    }

    /** @return array<int, array<string, mixed>> */
    private function propositionDeKarim(): array
    {
        return [
            ['nom' => 'Tacos Poulet',  'categorie' => 'Tacos',    'prix' => 8.5,  'description' => null],
            ['nom' => 'Tacos Merguez', 'categorie' => 'Tacos',    'prix' => 8.5,  'description' => null],
            ['nom' => 'Coca 33cl',     'categorie' => 'Boissons', 'prix' => 2.0,  'description' => null],
        ];
    }

    /** @return array{tax_id:int, item_type:int} */
    private function choixDuCommercant(): array
    {
        return ['tax_id' => (int) $this->taxe->id, 'item_type' => 1];
    }

    private function applicateur(): MenuDraftApplier
    {
        return app(MenuDraftApplier::class);
    }

    public function test_une_carte_proposee_devient_des_categories_et_des_articles(): void
    {
        $rapport = $this->applicateur()->appliquer(
            $this->propositionDeKarim(),
            $this->choixDuCommercant()
        );

        $this->assertSame(
            [],
            $rapport['refus'],
            "Aucune ligne ne devait être refusée. Refus : " . json_encode($rapport['refus'], JSON_UNESCAPED_UNICODE)
        );

        $this->assertEqualsCanonicalizing(['Tacos', 'Boissons'], $rapport['categories_creees']);
        $this->assertCount(3, $rapport['articles_crees']);

        $this->assertSame(2, ItemCategory::query()->count());
        $this->assertSame(3, Item::query()->count());

        // Le prix proposé est repris tel quel : c'est une donnée SAISIE, relue par le
        // commerçant. Rien n'est recalculé ici.
        $this->assertEqualsWithDelta(
            8.5,
            (float) Item::query()->where('name', 'Tacos Poulet')->value('price'),
            0.001
        );
    }

    public function test_appliquer_deux_fois_ne_duplique_rien(): void
    {
        // C3 du cahier des charges. La propriété qui rend l'écran utilisable.
        $this->applicateur()->appliquer($this->propositionDeKarim(), $this->choixDuCommercant());

        $categoriesApres1 = ItemCategory::query()->count();
        $articlesApres1   = Item::query()->count();

        $rapport = $this->applicateur()->appliquer(
            $this->propositionDeKarim(),
            $this->choixDuCommercant()
        );

        $this->assertSame(
            $categoriesApres1,
            ItemCategory::query()->count(),
            'Une seconde application a créé des catégories en double.'
        );
        $this->assertSame(
            $articlesApres1,
            Item::query()->count(),
            "Une seconde application a créé des articles en double. Un commerçant qui\n"
            . "doute et reclique se retrouverait avec sa carte en double."
        );

        // Et le rapport le DIT, au lieu de crier à l'erreur : « déjà là » n'est pas
        // un échec. Un rapport tout rouge sur une seconde application ferait croire
        // que rien n'a marché.
        $this->assertCount(3, $rapport['articles_deja_la']);
        $this->assertSame([], $rapport['refus']);
        $this->assertSame([], $rapport['articles_crees']);
    }

    public function test_une_categorie_ecrite_avec_une_autre_casse_n_est_pas_un_doublon(): void
    {
        $this->applicateur()->appliquer($this->propositionDeKarim(), $this->choixDuCommercant());

        $this->applicateur()->appliquer(
            [['nom' => 'Tacos Chicken', 'categorie' => '  tacos ', 'prix' => 9.0, 'description' => null]],
            $this->choixDuCommercant()
        );

        $this->assertSame(
            2,
            ItemCategory::query()->count(),
            "« Tacos », « tacos » et « tacos » avec des espaces sont la MÊME catégorie\n"
            . "pour un restaurateur. Deux lectures de la même carte n'en créent qu'une."
        );
    }

    public function test_sans_taxe_choisie_l_article_est_refuse_et_non_facture_a_zero(): void
    {
        // LE VERROU CENTRAL DE CETTE MISSION. `PricingService` (zone gelée) fait
        // `(int) ($dbItem->tax_id ?? 0)` puis `$taxes[0] ?? null` : un article sans
        // taxe est facturé à 0 % SANS alerte ni journal. ONB-02 a fermé cette porte
        // dans `ItemRequest`. Si l'applicateur écrivait en base directement, il en
        // rouvrirait une seconde, invisible.
        $rapport = $this->applicateur()->appliquer(
            $this->propositionDeKarim(),
            ['tax_id' => null, 'item_type' => 1]
        );

        $this->assertCount(
            3,
            $rapport['refus'],
            "Les trois articles devaient être REFUSÉS faute de taxe choisie par le\n"
            . "commerçant. Rapport : " . json_encode($rapport, JSON_UNESCAPED_UNICODE)
        );

        $this->assertSame(
            0,
            Item::query()->count(),
            "Un article a été créé sans taxe : il serait facturé à 0 % en silence, à la\n"
            . "borne comme à la caisse. C'est exactement le défaut qu'ONB-02 a fermé."
        );

        // Et le motif du refus doit être lisible par un commerçant, pas un code.
        $this->assertNotSame('', $rapport['refus'][0]['raison']);
    }

    public function test_le_refus_d_une_ligne_n_empeche_pas_les_autres(): void
    {
        // Une carte photographiée contient toujours une ligne illisible. Elle ne doit
        // pas faire perdre les 40 autres.
        $rapport = $this->applicateur()->appliquer(
            [
                ['nom' => 'Tacos Poulet', 'categorie' => 'Tacos', 'prix' => 8.5,  'description' => null],
                ['nom' => str_repeat('X', 300), 'categorie' => 'Tacos', 'prix' => 5.0, 'description' => null],
                ['nom' => 'Coca 33cl',    'categorie' => 'Boissons', 'prix' => 2.0, 'description' => null],
            ],
            $this->choixDuCommercant()
        );

        $this->assertCount(2, $rapport['articles_crees'], 'Les deux lignes valides devaient passer.');
        $this->assertCount(1, $rapport['refus'], 'La ligne trop longue devait être la seule refusée.');
    }

    public function test_une_ligne_sans_nom_est_ignoree_sans_planter(): void
    {
        $rapport = $this->applicateur()->appliquer(
            [
                ['nom' => '',   'categorie' => 'Tacos', 'prix' => 8.5, 'description' => null],
                ['nom' => '   ', 'categorie' => 'Tacos', 'prix' => 8.5, 'description' => null],
                ['nom' => 'Tacos Poulet', 'categorie' => 'Tacos', 'prix' => 8.5, 'description' => null],
            ],
            $this->choixDuCommercant()
        );

        $this->assertSame(['Tacos Poulet'], $rapport['articles_crees']);
        $this->assertSame(1, Item::query()->count());
    }
}
