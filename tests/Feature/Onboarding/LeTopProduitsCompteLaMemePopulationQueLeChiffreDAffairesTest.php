<?php

namespace Tests\Feature\Onboarding;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tax;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [ONB-07 2026-08-28] Le Top 5 et le chiffre d'affaires comptent la même chose.
 *
 * ═══ LE DÉFAUT, ET IL EST DE MOI ═══
 *
 * Le même jour, j'ai remplacé la recopie du prédicat de revenu par un appel à la
 * règle (`DashboardService:737`), en écrivant au-dessus :
 *
 * > « On appelle la règle au lieu de la recopier. Une copie ne suit pas les
 * > corrections de l'original — c'est exactement ce qui s'est passé ici. »
 *
 * Et j'ai laissé la **même recopie** 150 lignes plus bas, dans `topItemsOfDay`,
 * sans l'exclusion Uber. Le jumeau oublié, dans le même fichier, sous
 * l'avertissement qui le décrit.
 *
 * ═══ CE QUE ÇA COÛTAIT ═══
 *
 * Le CA du PDF de clôture passe par `Order::isRealizedRevenueRow`, qui écarte
 * `source_surface = 'uber_eats'` — déjà facturé par l'agrégateur, non fiscalisé par
 * design. Le Top 5 les comptait.
 *
 * Mesuré sur la base en service, journée du 14/08 : **17 commandes** retenues par
 * le prédicat du Top 5 contre **7** pour le CA. Le document remis au comptable et
 * archivé six ans présentait donc deux populations différentes sous deux titres
 * voisins, sans rien signaler.
 */
class LeTopProduitsCompteLaMemePopulationQueLeChiffreDAffairesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branche;
    private Item $produit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        $this->branche = Branch::factory()->create();

        $taxe = Tax::factory()->create(['tax_rate' => 10, 'status' => Status::ACTIVE]);
        $categorie = ItemCategory::factory()->create(['status' => Status::ACTIVE]);

        $this->produit = Item::factory()->create([
            'name'             => 'Tacos poulet',
            'item_category_id' => $categorie->id,
            'tax_id'           => $taxe->id,
            'price'            => 10.00,
            'status'           => Status::ACTIVE,
        ]);
    }

    private function vente(?string $surface, float $montant, int $quantite): Order
    {
        $commande = Order::factory()->create([
            'branch_id'      => $this->branche->id,
            'status'         => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'source_surface' => $surface,
            'order_datetime' => now(),
            'business_date'  => now()->toDateString(),
            'total'          => $montant,
            'total_tax'      => round($montant / 11, 2),
        ]);

        // Pas de fabrique pour `OrderItem` dans ce dépôt : on écrit la ligne
        // directement, avec les colonnes que la requête du Top 5 interroge.
        OrderItem::query()->forceCreate([
            'order_id'    => $commande->id,
            'branch_id'   => $this->branche->id,
            'item_id'     => $this->produit->id,
            'quantity'    => $quantite,
            'price'       => $montant / max(1, $quantite),
            'total_price' => $montant,
            'tax_name'    => 'TVA 10%',
            'tax_rate'    => 10,
            'tax_amount'  => round($montant / 11, 2),
            'discount'    => 0,
            'tax_type'    => 1,
            'item_variation_total' => 0,
            'item_extra_total'     => 0,
        ]);

        return $commande;
    }

    public function test_une_vente_uber_n_entre_pas_dans_le_top_produits(): void
    {
        // Une vente comptoir et une vente Uber, le même produit, le même jour.
        $this->vente(surface: 'pos', montant: 100.00, quantite: 2);
        $this->vente(surface: 'uber_eats', montant: 500.00, quantite: 40);

        $service = app(DashboardService::class);

        $reflexion = new \ReflectionMethod($service, 'topItemsOfDay');
        $reflexion->setAccessible(true);

        $top = $reflexion->invoke(
            $service,
            now()->startOfDay(),
            now()->addDay()->startOfDay(),
            $this->branche->id
        );

        $this->assertNotEmpty($top, 'Le Top produits est vide : la vente comptoir a disparu aussi.');

        $quantite = (int) ($top[0]['qty'] ?? 0);

        $this->assertSame(
            2,
            $quantite,
            "Le Top produits compte la vente Uber : {$quantite} unités au lieu de 2.\n\n"
            . "Le chiffre d'affaires imprimé juste au-dessus, lui, l'écarte via\n"
            . "`Order::isRealizedRevenueRow`. Le document remis au comptable présente\n"
            . "donc deux populations différentes sous deux titres voisins."
        );
    }

    public function test_la_vente_comptoir_reste_bien_comptee(): void
    {
        // Contrôle de non-régression : écarter Uber ne doit pas écarter le reste.
        // Sans ce contrôle, un filtre trop large rendrait le banc précédent vert
        // en vidant simplement le tableau.
        $this->vente(surface: 'pos', montant: 60.00, quantite: 3);
        $this->vente(surface: null, montant: 40.00, quantite: 2);

        $service = app(DashboardService::class);
        $reflexion = new \ReflectionMethod($service, 'topItemsOfDay');
        $reflexion->setAccessible(true);

        $top = $reflexion->invoke(
            $service,
            now()->startOfDay(),
            now()->addDay()->startOfDay(),
            $this->branche->id
        );

        $this->assertNotEmpty($top, 'Le Top produits est vide alors que deux ventes légitimes existent.');

        $quantite = (int) ($top[0]['qty'] ?? 0);

        $this->assertSame(
            5,
            $quantite,
            "Des ventes légitimes ont disparu du Top produits ({$quantite} au lieu de 5).\n"
            . "Une commande sans `source_surface` renseignée est une vente ordinaire."
        );
    }

    public function test_le_pdf_ecrit_les_montants_en_francais(): void
    {
        // Le PDF de ventes formatait `1,234.56` — le format anglo-saxon — quand
        // l'écran affiche `1 234,56 €`. Deux formats pour la même somme, dans le
        // même produit, sur des documents que le commerçant compare.
        //
        // Pire qu'inélégant : « 1,234.56 » se lit « 1,23 » pour un œil français.
        // Un facteur mille sur un document remis au comptable.
        $rendu = \App\Libraries\AppLibrary::reportCurrencyAmountFormat(1234.56);

        $this->assertStringContainsString(
            ',56',
            $rendu,
            "Le séparateur décimal du PDF n'est pas la virgule : « {$rendu} »"
        );

        $this->assertStringNotContainsString(
            '.',
            $rendu,
            "Le PDF écrit encore un point décimal : « {$rendu} ». Un commerçant\n"
            . 'français y lit un séparateur de milliers.'
        );

        // Et le millier doit être séparé, sinon « 123456,00 » devient illisible.
        $this->assertStringNotContainsString(
            '1234',
            $rendu,
            "Les milliers ne sont pas séparés : « {$rendu} »"
        );
    }
}
