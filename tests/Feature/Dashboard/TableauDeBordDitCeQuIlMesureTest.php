<?php

namespace Tests\Feature\Dashboard;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\ItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [2026-09-03] Trois chiffres du tableau de bord ne mesuraient pas ce qu'ils annonçaient.
 *
 * Constatés en navigateur puis vérifiés en base pendant la campagne E2E du 2026-09-02, et
 * toujours ouverts en production au moment d'écrire ces bancs.
 */
class TableauDeBordDitCeQuIlMesureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function categorie(): ItemCategory
    {
        return ItemCategory::query()->firstOrCreate(
            ['name' => 'Sandwichs'],
            ItemCategory::factory()->make(['name' => 'Sandwichs'])->getAttributes()
        );
    }

    private function article(string $nom, int $ordre = 0, bool $misEnAvant = false): Item
    {
        return Item::factory()->create([
            'name' => $nom,
            'status' => Status::ACTIVE,
            'order' => $ordre,
            'is_featured' => $misEnAvant ? Ask::YES : Ask::NO,
            'item_category_id' => $this->categorie()->id,
        ]);
    }

    /** Pas de fabrique pour `OrderItem` dans ce dépôt : on pose la ligne à la main. */
    private function ligne(int $commandeId, int $brancheId, int $articleId, int $quantite): void
    {
        OrderItem::create([
            'order_id' => $commandeId,
            'branch_id' => $brancheId,
            'item_id' => $articleId,
            'quantity' => $quantite,
            'discount' => 0,
            'price' => 5.00,
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'total_price' => 5.00 * $quantite,
            'tax_name' => 'TVA 10',
            'tax_rate' => 10,
            'tax_type' => 1,
            'tax_amount' => 0,
        ]);
    }

    /** Une commande encaissée et non annulée : ce que `realizedRevenue()` appelle une vente. */
    private function vente(Branch $branche, Item $article, int $quantite): void
    {
        $commande = Order::factory()->create([
            'branch_id' => $branche->id,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::DELIVERED,
            'parent_order_id' => null,
            'source_surface' => null,
        ]);
        $this->ligne($commande->id, $branche->id, $article->id, $quantite);
    }

    /** Une commande REFUSÉE et impayée : elle ne doit peser sur aucun classement. */
    private function commandeRefusee(Branch $branche, Item $article, int $lignes): void
    {
        for ($i = 0; $i < $lignes; $i++) {
            $commande = Order::factory()->create([
                'branch_id' => $branche->id,
                'payment_status' => PaymentStatus::UNPAID,
                'status' => OrderStatus::REJECTED,
                'parent_order_id' => null,
            ]);
            $this->ligne($commande->id, $branche->id, $article->id, 1);
        }
    }

    /**
     * Le défaut mesuré : le tableau de bord couronnait Cayenne (83 unités réellement vendues)
     * devant Coca-Cola (109), parce qu'il comptait des LIGNES de commande de n'importe quel
     * statut — 54 des lignes de Cayenne venaient de commandes refusées et impayées.
     */
    public function test_le_classement_ignore_les_commandes_refusees(): void
    {
        $branche = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $coca = $this->article('Coca-Cola 33cl');
        $cayenne = $this->article('Cayenne');

        // Coca vend PLUS en unités, sur moins de commandes.
        $this->vente($branche, $coca, 9);
        $this->vente($branche, $cayenne, 3);
        // ... et Cayenne accumule des lignes REFUSÉES, qui gonflaient son score.
        $this->commandeRefusee($branche, $cayenne, 12);

        $classement = app(ItemService::class)->mostPopularItems()->pluck('name')->all();

        $this->assertSame(
            'Coca-Cola 33cl',
            $classement[0] ?? null,
            "Le n°1 doit être l'article le plus VENDU (9 unités), pas celui qui accumule le plus "
            . 'de lignes refusées (12 lignes, 3 unités vendues).'
        );
    }

    /** Contrôle apparié : sans commande refusée, le classement reste celui des unités. */
    public function test_le_classement_suit_les_unites_vendues(): void
    {
        $branche = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $peu = $this->article('Eau Plate 50cl');
        $beaucoup = $this->article('Galette Normale');

        $this->vente($branche, $peu, 2);
        $this->vente($branche, $beaucoup, 7);

        $classement = app(ItemService::class)->mostPopularItems()->pluck('name')->all();

        $this->assertSame('Galette Normale', $classement[0] ?? null);
    }

    /**
     * Le défaut mesuré : `inRandomOrder()->limit(8)` sur 16 articles mis en avant. Trois
     * chargements donnaient trois listes différentes ; le gérant venu vérifier qu'un article
     * est bien mis en avant avait une chance sur deux de ne pas le voir.
     */
    public function test_les_articles_mis_en_avant_ne_sont_pas_tires_au_sort(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        foreach (range(1, 12) as $i) {
            $this->article('Mis en avant '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), $i, true);
        }

        $service = app(ItemService::class);
        $premier = $service->featuredItems()->pluck('name')->all();
        $second = $service->featuredItems()->pluck('name')->all();
        $troisieme = $service->featuredItems()->pluck('name')->all();

        $this->assertSame($premier, $second, 'Deux chargements doivent donner la même liste.');
        $this->assertSame($premier, $troisieme);
        // Garde anti-test-vide : il doit y avoir PLUS d'articles que de places, sinon un
        // tirage aléatoire donnerait lui aussi toujours la même liste et ce banc ne prouverait rien.
        $this->assertGreaterThan(count($premier), 12);
    }

    /**
     * Le défaut mesuré : `avg_per_day` divisait par le nombre de jours de la PLAGE, jours à
     * venir compris. Sur le mois en cours, la moyenne était donc divisée par 30 ou 31 dès le
     * 1er du mois. Arithmétique sur des valeurs relevées (juillet, J1 = 71,10 €, J2 = 131,60 €) :
     * consulté le 2 juillet, le widget affichait 6,54 €/jour au lieu de 101,35 € — facteur 15.
     */
    public function test_la_moyenne_par_jour_ne_divise_pas_par_les_jours_a_venir(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        // Plage : le mois EN COURS. Le nombre de jours écoulés est forcément inférieur au
        // nombre de jours du mois, sauf le dernier jour — auquel cas ce banc est neutre et
        // le dit plutôt que de prétendre mesurer.
        $debut = \Carbon\Carbon::now()->startOfMonth();
        $fin = \Carbon\Carbon::now()->endOfMonth();
        if ($debut->isSameDay($fin)) {
            $this->markTestSkipped('Mois d’un seul jour : rien à distinguer.');
        }

        $requete = new \Illuminate\Http\Request([
            'first_date' => $debut->toDateString(),
            'last_date' => $fin->toDateString(),
        ]);

        $resume = app(\App\Services\DashboardService::class)->salesSummary($requete);

        $joursDuMois = $debut->diffInDays($fin) + 1;
        $joursEcoules = \Carbon\Carbon::now()->day;

        $this->assertSame(
            $joursEcoules,
            $resume['avg_per_day_days'] ?? null,
            'La moyenne doit être divisée par les jours ÉCOULÉS, pas par les '
            . $joursDuMois . ' jours du mois.'
        );

        // Garde anti-test-vide : si le mois était fini, les deux nombres coïncideraient et
        // ce banc ne prouverait rien.
        if ($joursEcoules === $joursDuMois) {
            $this->markTestSkipped('Dernier jour du mois : les deux définitions coïncident.');
        }
    }
}
