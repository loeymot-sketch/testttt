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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-07 2026-08-28] Le « Total » du rapport articles additionnait la PAGE.
 *
 * `ItemsReportListComponent.vue:125` rendait `subTotal(itemsReports)` — la somme du
 * tableau de la page COURANTE, servie en `paginate: 1, per_page: 10`. L'export et le
 * PDF, eux, forcent `paginate => 0` et totalisent tout le catalogue.
 *
 * Même libellé `label.total`, deux nombres. Sur 45 produits, l'écran affichait le
 * total de dix — et c'est ce chiffre qui sert à décider d'un réassort.
 *
 * ═══ CE QUI REND CE DÉFAUT PARTICULIÈREMENT NET ═══
 *
 * Il avait DÉJÀ été corrigé une fois, pour le PDF, en juillet. Le commentaire de
 * `ItemsReportController::pdf()` le dit mot pour mot : « le PDF n'affichait que 10
 * des 45 items du catalogue et le "Total" sous-comptait les unités vendues ». La
 * correction a été portée au PDF et à l'export. Pas à l'écran.
 *
 * C'est le motif du jumeau oublié, le même qui a laissé l'import de catégories muet
 * pendant que celui des articles était réparé.
 *
 * ═══ LE JEU D'ESSAI DISTINGUE ═══
 *
 * Douze produits, donc DEUX pages de dix. Les ventes sont placées de sorte que le
 * total de la première page (35) diffère du total réel (42). Sans cet écart, le
 * banc serait vert avec l'ancien calcul comme avec le nouveau.
 */
class LEcranEtLExportDonnentLeMemeTotalTest extends TestCase
{
    use RefreshDatabase;

    private User $karim;
    private Branch $branche;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branche = Branch::factory()->create();

        $taxe = Tax::factory()->create(['tax_rate' => 10, 'status' => Status::ACTIVE]);
        $categorie = ItemCategory::factory()->create(['status' => Status::ACTIVE]);

        // Une commande réalisée : c'est elle qui rend les ventes comptables
        // (`Order::scopeRealizedRevenue` — payée, non terminale, hors Uber).
        $commande = Order::factory()->create([
            'branch_id'      => $this->branche->id,
            'status'         => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'order_datetime' => Carbon::now('Europe/Paris')->setTime(12, 0),
            'source_surface' => null,
        ]);

        // Douze produits. Le tri du rapport est `units_sold DESC`, donc la première
        // page (10 lignes) portera les dix meilleures ventes : 7+6+5+4+3+2+2+2+2+2
        // = 35. Les deux dernières lignes valent 7 de plus → 42 au total.
        $quantites = [7, 6, 5, 4, 3, 2, 2, 2, 2, 2, 4, 3];

        foreach ($quantites as $rang => $quantite) {
            $produit = Item::factory()->create([
                'name'             => 'Produit ' . str_pad((string) ($rang + 1), 2, '0', STR_PAD_LEFT),
                'item_category_id' => $categorie->id,
                'tax_id'           => $taxe->id,
                'status'           => Status::ACTIVE,
                'price'            => 8.50,
            ]);

            // Pas de fabrique pour `OrderItem` : on écrit les colonnes NOT NULL de
            // la migration (`order_id`, `branch_id`, `item_id`, `quantity`,
            // `discount`, `price`).
            OrderItem::create([
                'order_id'    => $commande->id,
                'branch_id'   => $this->branche->id,
                'item_id'     => $produit->id,
                'quantity'    => $quantite,
                'discount'    => 0,
                'price'       => 8.50,
                'total_price' => 8.50 * $quantite,
            ]);
        }

        $this->karim = User::factory()->create(['branch_id' => 0]);
        $this->karim->assignRole('Admin');
        Permission::findOrCreate('items-report', 'sanctum');
        $this->karim->givePermissionTo(['items-report']);
    }

    public function test_le_total_de_l_ecran_porte_sur_le_perimetre_et_non_sur_la_page(): void
    {
        $reponse = $this->actingAs($this->karim, 'sanctum')
            ->getJson('/api/admin/items-report?paginate=1&per_page=10');

        $reponse->assertOk();

        // Le contrôle de périmètre : l'écran reçoit bien 10 lignes sur 12.
        $this->assertCount(10, $reponse->json('data'), 'La page doit rester paginée à 10.');

        $this->assertSame(
            42,
            $reponse->json('total_unites_vendues'),
            "Le total doit porter sur les DOUZE produits (42 unités), pas sur les dix\n"
            . "de la page (35). Même libellé « Total » que l'export, qui totalise tout :\n"
            . 'deux nombres pour un seul mot, et c\'est celui-là qui décide d\'un réassort.'
        );
    }

    public function test_le_total_suit_les_filtres_comme_la_liste(): void
    {
        // Un filtre qui ne retient qu'un produit : le total doit le suivre, sinon il
        // afficherait le total du catalogue entier sous une liste filtrée — un
        // mensonge plus grave que le total de la page.
        $reponse = $this->actingAs($this->karim, 'sanctum')
            ->getJson('/api/admin/items-report?paginate=1&per_page=10&name=Produit 01');

        $reponse->assertOk();

        $this->assertCount(1, $reponse->json('data'));
        $this->assertSame(
            7,
            $reponse->json('total_unites_vendues'),
            "Le total et la liste doivent partager EXACTEMENT les mêmes filtres.\n"
            . 'Deux requêtes construites séparément divergent au premier filtre ajouté.'
        );
    }

    public function test_sans_pagination_le_total_reste_le_meme(): void
    {
        // C'est le chemin de l'export et du PDF. Les trois surfaces doivent donner le
        // même nombre — c'est tout l'objet de ce banc.
        $reponse = $this->actingAs($this->karim, 'sanctum')
            ->getJson('/api/admin/items-report?paginate=0');

        $reponse->assertOk();

        $this->assertCount(12, $reponse->json('data'));
        $this->assertSame(42, $reponse->json('total_unites_vendues'));

        // Et la somme des lignes rendues doit valoir le total annoncé : sans cette
        // assertion, un total juste posé à côté de lignes fausses passerait.
        $this->assertSame(
            42,
            array_sum(array_column($reponse->json('data'), 'units_sold'))
        );
    }
}
