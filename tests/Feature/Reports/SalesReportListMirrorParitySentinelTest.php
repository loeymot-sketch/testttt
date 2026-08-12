<?php

/**
 * [GOAL-OPS-SWAP W2 2026-08-12 — constat RAPPORT-VENTES-DEUX-COMPTES]
 *
 * L'écran Rapport des ventes affichait DEUX chiffres contradictoires,
 * à quinze centimètres l'un de l'autre, sur la même population :
 *   · tuile « Total Commandes »        → 3185
 *   · pied de tableau « … sur N entrées » → 3191
 *
 * Cause prouvée : `SalesReportListComponent.vue:486` et `:492` envoient le MÊME
 * objet de recherche aux deux endpoints. Le seul écart est côté serveur —
 * `OrderService::salesReportOverview()` écarte les miroirs de remboursement
 * (`parent_order_id` non nul), `OrderService::list()` ne le fait pas.
 *
 * Les 6 lignes de l'écart sont des CONTRE-ÉCRITURES de remboursement (`RTN-*`,
 * statut RETURNED, totaux négatifs : -11, -8, -30, -24, -4, -12 €). Le tableau
 * les comptait comme des commandes.
 *
 * C'est le motif du JUMEAU OUBLIÉ : le heal « SELF-AUDIT R3 P2 2026-07-05 » a été
 * appliqué à `salesReportOverview()` et PAS à `list()`, alors que la doctrine
 * écrite dans le code dit « TOUS les compteurs excluent les miroirs ».
 *
 * PÉRIMÈTRE DU CORRECTIF — délibérément étroit : `OrderService::list()` sert
 * SIX contrôleurs (historique, commandes caisse, commandes en ligne, commandes
 * table, rapport, export). Filtrer globalement ferait DISPARAÎTRE les
 * remboursements de l'historique — une perte d'information que personne n'a
 * demandée. L'exclusion est donc portée par un drapeau que seul le SERVEUR
 * positionne, sur le seul chemin du rapport des ventes.
 *
 * @group sentinel
 * @group reports
 */

namespace Tests\Feature\Reports;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SalesReportListMirrorParitySentinelTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    /** Crée 2 vraies commandes + 1 miroir de remboursement. */
    private function semerDeuxVentesEtUnMiroir(): void
    {
        $this->branch = Branch::factory()->create();
        $maintenant = Carbon::now('Europe/Paris')->setTime(12, 0);

        $commun = [
            'branch_id' => $this->branch->id,
            'order_type' => OrderType::KIOSK,
            'order_datetime' => $maintenant,
            'is_advance_order' => Ask::NO,
        ];

        $parent = Order::factory()->create($commun + [
            'status' => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'total' => 30,
            'source' => Source::WEB,
        ]);

        Order::factory()->create($commun + [
            'status' => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'total' => 20,
            'source' => Source::WEB,
        ]);

        // La contre-écriture : ce n'est PAS une commande passée.
        Order::factory()->create($commun + [
            'parent_order_id' => $parent->id,
            'status' => OrderStatus::RETURNED,
            'payment_status' => PaymentStatus::REFUNDED,
            'total' => -30,
            'source' => null,
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        // `sales-report` n'est pas dans le jeu semé par `seedSpatieRoles()`
        // (tests/TestCase.php:111) — même motif que `credit-balance-report`
        // dans CreditBalanceCustomersOnlySentinelTest.
        Permission::findOrCreate('sales-report', 'sanctum');
        Permission::findOrCreate('pos-orders', 'sanctum');
        $admin->givePermissionTo(['sales-report', 'pos-orders']);

        return $admin;
    }

    public function test_le_tableau_et_la_tuile_du_rapport_comptent_la_meme_chose(): void
    {
        $this->semerDeuxVentesEtUnMiroir();
        $admin = $this->admin();

        $tuile = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/sales-report/overview')
            ->assertOk()
            ->json('data.total_orders');

        $tableau = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/sales-report?paginate=1&per_page=10')
            ->assertOk()
            ->json('meta.total');

        $this->assertSame(
            2,
            (int) $tuile,
            'La tuile doit compter les 2 ventes réelles, jamais la contre-écriture.'
        );

        $this->assertSame(
            (int) $tuile,
            (int) $tableau,
            "Le pied de tableau annonce {$tableau} entrées quand la tuile du MÊME écran "
            ."annonce {$tuile} commandes. Deux chiffres contradictoires sur la même "
            .'population : le tableau compte les miroirs de remboursement comme des commandes.'
        );
    }

    public function test_le_tableau_du_rapport_ne_liste_aucune_contre_ecriture(): void
    {
        $this->semerDeuxVentesEtUnMiroir();

        $lignes = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/sales-report?paginate=1&per_page=50')
            ->assertOk()
            ->json('data');

        $negatives = array_values(array_filter(
            $lignes,
            fn ($l) => isset($l['total']) && (float) preg_replace('/[^0-9\-\.]/', '', (string) $l['total']) < 0
        ));

        $this->assertSame(
            [],
            $negatives,
            'Le rapport des ventes liste une ligne à montant négatif : c\'est une '
            .'contre-écriture de remboursement présentée comme une vente.'
        );
    }

    /**
     * GARDE ANTI-SUR-CORRECTION — la vraie raison d'être de ce banc.
     *
     * `OrderService::list()` est partagé par 6 contrôleurs. Si le filtre fuitait
     * hors du rapport des ventes, l'historique perdrait ses remboursements —
     * une régression bien pire que le défaut corrigé.
     */
    public function test_l_historique_continue_de_montrer_les_remboursements(): void
    {
        $this->semerDeuxVentesEtUnMiroir();

        $meta = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/order-history?paginate=1&per_page=50')
            ->assertOk()
            ->json('meta.total');

        $this->assertSame(
            3,
            (int) $meta,
            'L\'historique doit TOUJOURS montrer les 3 lignes, miroir compris. '
            .'S\'il n\'en montre que 2, le filtre du rapport des ventes a fuité '
            .'sur un chemin partagé.'
        );
    }
}
