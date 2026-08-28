<?php

namespace Tests\Feature\Onboarding;

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
 * [ONB-07 2026-08-28] Trois chiffres FAUX sur les rapports du commerçant.
 *
 * 1. LE FILTRE « PAYÉ » RAMENAIT DES COMMANDES NON ENCAISSÉES.
 *    `payment_status` est un `tinyInteger`, et le filtre posait
 *    `payment_status LIKE '%5%'`. Or `PENDING_COUNTER` vaut **15** :
 *    `15 LIKE '%5%'` est VRAI. Mesuré sur la base réelle : 3 017 commandes
 *    au lieu de 2 774, dont 243 en attente d'encaissement.
 *
 * 2. LE TICKET MOYEN divisait un chiffre d'affaires SANS Uber par un nombre de
 *    commandes AVEC Uber. Mesuré au 14/08 : 9,10 € affiché contre 22,09 € réels
 *    — 59 % de sous-évaluation, sur le chiffre qui sert à décider d'une hausse
 *    de prix.
 *
 * 3. LE PDF DE CLÔTURE comptait Uber quand l'écran, le rapport et le Z signé ne
 *    le comptaient pas : 413,38 € contre 154,65 € le 14/08. Son prédicat était
 *    RECOPIÉ à la main et sa copie omettait l'exclusion Uber — sous un
 *    commentaire affirmant « Mirrors the Order::scopeRealizedRevenue scope ».
 *    C'est le document remis au comptable et archivé six ans.
 *
 * Le point commun des trois : une règle recopiée plutôt qu'appelée, ou une
 * comparaison approximative là où la donnée est exacte.
 */
class LesChiffresDesRapportsSontJustesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branche;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branche = Branch::factory()->create();
    }

    private function commande(array $attributs): Order
    {
        return Order::factory()->create($attributs + [
            'branch_id'        => $this->branche->id,
            'order_type'       => OrderType::KIOSK,
            'order_datetime'   => Carbon::now('Europe/Paris')->setTime(12, 0),
            'is_advance_order' => Ask::NO,
            'source'           => Source::WEB,
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Permission::findOrCreate('sales-report', 'sanctum');
        $admin->givePermissionTo(['sales-report', 'pos-orders']);

        return $admin;
    }

    public function test_le_filtre_PAYE_ne_ramene_pas_les_commandes_en_attente(): void
    {
        // Deux commandes payées (statut 5) et une en attente d'encaissement (15).
        $this->commande(['status' => OrderStatus::DELIVERED, 'payment_status' => PaymentStatus::PAID, 'total' => 30]);
        $this->commande(['status' => OrderStatus::DELIVERED, 'payment_status' => PaymentStatus::PAID, 'total' => 20]);
        $this->commande(['status' => OrderStatus::PENDING,   'payment_status' => PaymentStatus::PENDING_COUNTER, 'total' => 99]);

        $this->actingAs($this->admin(), 'sanctum');

        $requete = new PaginateRequest();
        $requete->merge(['paginate' => 0, 'payment_status' => PaymentStatus::PAID]);

        $lignes = app(OrderService::class)->list($requete, false);

        $this->assertCount(
            2,
            $lignes,
            "Le filtre « Payé » ramène une commande EN ATTENTE d'encaissement.\n"
            . "`payment_status` est un tinyInteger et le filtre posait `LIKE '%5%'` :\n"
            . "PENDING_COUNTER vaut 15, donc `15 LIKE '%5%'` est vrai. Le commerçant\n"
            . 'croit lire ce qu\'il a encaissé.'
        );

        foreach ($lignes as $ligne) {
            $this->assertSame(
                PaymentStatus::PAID,
                (int) $ligne->payment_status,
                'Une commande non payée est passée dans le filtre « Payé ».'
            );
        }
    }

    public function test_le_filtre_de_statut_reste_exact_lui_aussi(): void
    {
        // Contrôle de périmètre : la correction porte sur toutes les colonnes
        // d'énumération, pas seulement `payment_status`.
        $this->commande(['status' => OrderStatus::DELIVERED, 'payment_status' => PaymentStatus::PAID, 'total' => 10]);
        $this->commande(['status' => OrderStatus::PENDING,   'payment_status' => PaymentStatus::PAID, 'total' => 10]);

        $this->actingAs($this->admin(), 'sanctum');

        $requete = new PaginateRequest();
        $requete->merge(['paginate' => 0, 'status' => OrderStatus::DELIVERED]);

        foreach (app(OrderService::class)->list($requete, false) as $ligne) {
            $this->assertSame(OrderStatus::DELIVERED, (int) $ligne->status);
        }
    }

    public function test_le_predicat_de_chiffre_d_affaires_est_APPELE_et_non_recopie(): void
    {
        // La cause des constats 2 et 3 : une règle recopiée ne suit pas les
        // corrections de l'original. Trois copies existaient ; celle du PDF de
        // clôture avait perdu l'exclusion Uber en chemin.
        $source = file_get_contents(app_path('Services/DashboardService.php'));

        $this->assertStringContainsString(
            'Order::isRealizedRevenueRow',
            $source,
            "Le PDF de clôture doit APPELER le prédicat partagé, pas le recopier.\n"
            . 'Une copie ne suit pas les corrections de son original.'
        );

        $this->assertStringNotContainsString(
            '$isLivePaidSale = (int) $o->payment_status === PaymentStatus::PAID',
            $source,
            "Le prédicat recopié est revenu dans DashboardService : il omettait\n"
            . "l'exclusion Uber et surévaluait le PDF remis au comptable."
        );
    }

    public function test_le_denominateur_du_ticket_moyen_exclut_uber_comme_le_numerateur(): void
    {
        // Le numérateur passe par `realizedRevenue()`, qui exclut Uber. Le
        // dénominateur ne l'excluait pas : on divisait un CA sans Uber par un
        // nombre de commandes avec Uber.
        $source = file_get_contents(app_path('Services/DashboardService.php'));

        $this->assertMatchesRegularExpression(
            "/daily_paid_orders[\s\S]{0,2000}source_surface/",
            $source,
            "Le dénominateur du ticket moyen n'exclut pas le canal Uber, alors que\n"
            . "son numérateur l'exclut. Mesuré : 9,10 € affiché contre 22,09 € réels."
        );
    }
}
