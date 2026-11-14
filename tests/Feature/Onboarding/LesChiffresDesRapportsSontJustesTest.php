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

    /**
     * [ONB-07 2026-08-28 · réécrit après audit adverse]
     *
     * ⚠️ LA VERSION PRÉCÉDENTE DE CE BANC NE MORDAIT PAS. Elle faisait
     * `assertStringContainsString('Order::isRealizedRevenueRow', $source)` sur le
     * fichier `DashboardService.php` — or cette chaîne y apparaît trois fois, dont
     * deux dans les COMMENTAIRES posés par le commit correctif lui-même. Remettre la
     * copie manuelle du prédicat laissait le banc vert.
     *
     * On mesure désormais le NOMBRE, pas le texte.
     *
     * Le jeu d'essai contient une commande que le prédicat REJETTE — une vente Uber
     * payée. Sans elle, la copie manuelle (qui omettait justement l'exclusion Uber)
     * et l'appel au prédicat donneraient le même total, et le banc serait vert dans
     * les deux cas. Un garde ne mord que si son jeu d'essai contient une ligne qu'il
     * refuse.
     */
    public function test_le_pdf_de_cloture_ne_compte_pas_le_canal_uber(): void
    {
        // Ce que le commerçant a réellement encaissé lui-même.
        $this->commande([
            'status'         => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'total'          => 100,
            'total_tax'      => 10,
            'source_surface' => null,
        ]);

        // LA LIGNE QUI DISTINGUE. Uber facture séparément : la compter ici la
        // déclarerait DEUX FOIS. C'est le document remis au comptable et archivé
        // six ans.
        $this->commande([
            'status'         => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'total'          => 500,
            'total_tax'      => 50,
            'source_surface' => 'uber_eats',
        ]);

        // Et une commande payée puis ANNULÉE : le prédicat la rejette aussi.
        $this->commande([
            'status'         => OrderStatus::CANCELED,
            'payment_status' => PaymentStatus::PAID,
            'total'          => 300,
            'total_tax'      => 30,
            'source_surface' => null,
        ]);

        $synthese = app(\App\Services\DashboardService::class)->eodSynthesis();

        $this->assertEqualsWithDelta(
            100.0,
            (float) $synthese['total_ca'],
            0.001,
            "Le PDF « Clôture du jour » doit compter 100 € — pas 600 € (avec Uber),\n"
            . "ni 400 € (avec l'annulée). Mesuré sur la base réelle le 14/08 :\n"
            . '413,38 € annoncés contre 154,65 € au Z signé.'
        );

        $this->assertEqualsWithDelta(
            10.0,
            (float) $synthese['total_tva'],
            0.001,
            'La TVA suit le même prédicat : la surévaluer, c\'est la déclarer deux fois.'
        );
    }

    /**
     * [ONB-07 2026-08-28 · réécrit après audit adverse]
     *
     * ⚠️ La version précédente cherchait `daily_paid_orders … source_surface` dans le
     * texte source. Le commentaire explicatif contient les deux mots : le banc était
     * vert même sans la clause.
     *
     * Ici on compare le NOMBRE affiché. Le numérateur passe par `realizedRevenue()`,
     * qui exclut Uber ; si le dénominateur ne l'exclut pas, on divise un chiffre
     * d'affaires SANS Uber par un nombre de commandes AVEC Uber.
     */
    public function test_le_ticket_moyen_divise_par_le_meme_perimetre_que_son_numerateur(): void
    {
        foreach ([20, 20] as $montant) {
            $this->commande([
                'status'         => OrderStatus::DELIVERED,
                'payment_status' => PaymentStatus::PAID,
                'total'          => $montant,
                'source_surface' => null,
            ]);
        }

        // La ligne qui distingue : payée, donc au dénominateur si on ne l'exclut pas,
        // mais absente du numérateur.
        $this->commande([
            'status'         => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'total'          => 100,
            'source_surface' => 'uber_eats',
        ]);

        $rapport = app(\App\Services\DashboardService::class)->realtimeReport();

        // 40 € encaissés par le commerçant, 2 commandes à lui → 20 €.
        // Sans l'exclusion au dénominateur : 40 ÷ 3 = 13,33 € — deux nombres
        // différents, donc le jeu d'essai distingue bien les deux implémentations.
        $ticket = (float) preg_replace('/[^0-9.,]/u', '', (string) $rapport['average_ticket']);
        $ticket = (float) str_replace(',', '.', (string) $ticket);

        $this->assertEqualsWithDelta(
            20.0,
            $ticket,
            0.01,
            "Ticket moyen attendu 20,00 € (40 € ÷ 2 commandes du commerçant).\n"
            . "Sans l'exclusion Uber au dénominateur : 13,33 €. Mesuré au 14/08 sur la\n"
            . 'base réelle : 9,10 € affiché contre 22,09 € réels, soit 59 % de moins.'
        );
    }
}
