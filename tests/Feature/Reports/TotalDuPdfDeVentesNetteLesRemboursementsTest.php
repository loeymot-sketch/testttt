<?php

namespace Tests\Feature\Reports;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Http\Requests\PaginateRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-07 2026-08-28] Le Total imprimé sur le PDF de ventes ne défalquait plus les remboursements.
 *
 * DEUX CORRECTIFS JUSTES SE SONT ANNULÉS. Les deux sont documentés dans le code, et
 * aucun n'est à jeter :
 *
 *   · 2026-06-01 « SALES-NET-01 », mandat propriétaire « net, concorder avec le Z » :
 *     le Total en euros doit inclure les contre-écritures négatives, pour qu'une vente
 *     remboursée nette à ~0 et que le document s'accorde avec le rapport Z signé. Le
 *     gabarit PDF porte encore ce commentaire, mot pour mot, au-dessus de son calcul.
 *   · 2026-08-12 « GOAL-OPS-SWAP W2 » : le NOMBRE de lignes du tableau devait cesser
 *     de compter les contre-écritures comme des commandes — la tuile annonçait 3185
 *     et le pied de tableau 3191, sur le même écran.
 *
 * Le second a été obtenu en retirant les lignes miroir du jeu de données
 * (`whereNull('parent_order_id')`), y compris sur le chemin du PDF. La branche de
 * nettage du gabarit est alors devenue INATTEIGNABLE : plus aucun miroir ne lui
 * parvient. Le Total imprimé surestime donc le chiffre d'affaires du montant
 * remboursé — sur un document que le commerçant sort pour son comptable.
 *
 * POURQUOI AUCUNE SENTINELLE NE L'A VU. Il en existe deux, vertes toutes les deux, et
 * l'angle mort est exactement entre elles : `SalesReportNetTotalSentinelTest` couvre
 * le prédicat et la tuile, jamais le rendu du document ; `SalesReportListMirrorParity`
 * couvre l'écran — et verrouille précisément l'inverse (aucune contre-écriture dans le
 * tableau). Personne ne gardait le document.
 *
 * LE JOINT RETENU, qui préserve les deux décisions dans leur domaine :
 *   · l'ÉCRAN garde son comportement du 2026-08-12 (pas de miroir, compte aligné sur
 *     la tuile) — la sentinelle existante reste verte, je n'y touche pas ;
 *   · le DOCUMENT retrouve celui du 2026-06-01 : il liste les mouvements et nette son
 *     total. Un document fiscal cohérent, dont la colonne s'additionne jusqu'au Total.
 *
 * L'invariant verrouillé ici est celui que personne ne peut contester : **un même
 * écran ne publie pas deux chiffres d'affaires différents.**
 */
class TotalDuPdfDeVentesNetteLesRemboursementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    /** 30 € + 20 € de ventes, moins un remboursement de 30 € → net attendu : 20 €. */
    private function semerDeuxVentesEtUnMiroir(): Branch
    {
        $branche = Branch::factory()->create();
        $maintenant = Carbon::now('Europe/Paris')->setTime(12, 0);

        $commun = [
            'branch_id'        => $branche->id,
            'order_type'       => OrderType::KIOSK,
            'order_datetime'   => $maintenant,
            'is_advance_order' => Ask::NO,
        ];

        $parent = Order::factory()->create($commun + [
            'status'         => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'total'          => 30,
            'source'         => Source::WEB,
        ]);

        Order::factory()->create($commun + [
            'status'         => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'total'          => 20,
            'source'         => Source::WEB,
        ]);

        Order::factory()->create($commun + [
            'parent_order_id' => $parent->id,
            'status'          => OrderStatus::RETURNED,
            'payment_status'  => PaymentStatus::REFUNDED,
            'total'           => -30,
            'source'          => null,
        ]);

        return $branche;
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Permission::findOrCreate('sales-report', 'sanctum');
        $admin->givePermissionTo(['sales-report', 'pos-orders']);

        return $admin;
    }

    /** Reproduit le calcul du gabarit PDF sur le jeu qu'il reçoit réellement. */
    private function totalDuDocument(): float
    {
        $requete = new PaginateRequest();
        $requete->merge(['paginate' => 0]);

        // Le contrôleur PDF n'écarte plus les contre-écritures : le gabarit peut donc
        // à nouveau les défalquer, comme son propre commentaire l'annonce.
        $lignes = app(OrderService::class)->list($requete, false);

        $total = 0.0;
        foreach ($lignes as $ligne) {
            if (Order::isRealizedRevenueRow($ligne)) {
                $total += (float) $ligne->total;
            }
        }

        return round($total, 2);
    }

    public function test_le_total_du_document_egale_celui_de_la_tuile(): void
    {
        $this->semerDeuxVentesEtUnMiroir();

        $tuile = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/sales-report/overview')
            ->assertOk()
            ->json('data.total_earnings');

        // La tuile formate en devise ; on compare les nombres.
        $tuileNombre = (float) preg_replace('/[^\d.\-]/', '', str_replace(',', '.', (string) $tuile));

        $this->assertSame(
            20.0,
            $this->totalDuDocument(),
            "Le Total du document doit défalquer le remboursement : 30 + 20 − 30 = 20 €.\n"
            . "S'il vaut 50 €, la contre-écriture n'atteint plus le gabarit et le\n"
            . 'document surestime le chiffre d\'affaires du montant remboursé.'
        );

        $this->assertEqualsWithDelta(
            $tuileNombre,
            $this->totalDuDocument(),
            0.01,
            "Le document et la tuile du MÊME écran annoncent deux chiffres d'affaires\n"
            . "différents. C'est l'invariant que ce banc protège : peu importe la règle\n"
            . 'retenue, il ne peut pas y en avoir deux.'
        );
    }

    /**
     * Le défaut, nommément : c'est le drapeau d'exclusion qui rendait la branche de
     * nettage inatteignable. On le vérifie explicitement pour que l'échec futur
     * désigne la cause, et pas seulement l'écart.
     */
    public function test_ecarter_les_contre_ecritures_surestimerait_le_total(): void
    {
        $this->semerDeuxVentesEtUnMiroir();

        $requete = new PaginateRequest();
        $requete->merge(['paginate' => 0]);

        $sansMiroirs = 0.0;
        foreach (app(OrderService::class)->list($requete, true) as $ligne) {
            if (Order::isRealizedRevenueRow($ligne)) {
                $sansMiroirs += (float) $ligne->total;
            }
        }

        $this->assertSame(
            50.0,
            round($sansMiroirs, 2),
            "Ce contrôle documente la cause : avec le drapeau d'exclusion, le gabarit ne\n"
            . 'voit que les deux ventes et totalise 50 € au lieu de 20 €.'
        );
    }

    /**
     * Les deux tests ci-dessus exercent le SERVICE. Il faut aussi verrouiller le
     * chemin du CONTRÔLEUR : sans cette assertion, remettre le drapeau d'exclusion
     * dans `pdf()` laisserait le banc vert — il ne mesurerait plus le vrai chemin.
     * C'est le piège dans lequel je suis déjà tombé cette nuit avec un autre banc.
     */
    public function test_le_controleur_du_pdf_n_ecarte_pas_les_contre_ecritures(): void
    {
        $source = (string) file_get_contents(
            app_path('Http/Controllers/Admin/SalesReportController.php')
        );

        $debut = strpos($source, 'public function pdf(');
        $this->assertNotFalse($debut, 'La méthode pdf() a changé de nom : adapter ce banc.');

        $corps = substr($source, $debut, 3000);

        $this->assertStringContainsString(
            'list($request, false)',
            $corps,
            "Le PDF doit recevoir les contre-écritures pour pouvoir les défalquer.\n"
            . "Avec `list(\$request, true)`, la branche de nettage du gabarit devient\n"
            . 'inatteignable et le Total imprimé surestime le chiffre d\'affaires.'
        );
        $this->assertStringNotContainsString(
            'list($request, true)',
            $corps,
            "Le drapeau d'exclusion est revenu sur le chemin du PDF."
        );
    }

    /**
     * Contrôle négatif : l'écran, lui, ne doit PAS changer. Sa règle est verrouillée
     * par SalesReportListMirrorParitySentinelTest depuis le 2026-08-12 et reste due.
     */
    public function test_l_ecran_continue_de_ne_lister_aucune_contre_ecriture(): void
    {
        $this->semerDeuxVentesEtUnMiroir();

        $lignes = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/sales-report?paginate=1&per_page=50')
            ->assertOk()
            ->json('data');

        foreach ($lignes as $ligne) {
            $this->assertGreaterThanOrEqual(
                0,
                (float) ($ligne['total'] ?? 0),
                "L'écran ne doit lister aucune contre-écriture : c'est la décision du\n"
                . '2026-08-12, et ce banc-ci ne la remet pas en cause.'
            );
        }
    }
}
