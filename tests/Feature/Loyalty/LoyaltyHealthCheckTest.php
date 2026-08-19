<?php

namespace Tests\Feature\Loyalty;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Status;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [FIDÉLITÉ 2026-08-19] LE VÉRIFICATEUR DOIT VRAIMENT VOIR CE QU'IL PRÉTEND VOIR.
 *
 * `fidelite:verifier` est un outil de surveillance : l'exploitant s'y fiera pour dormir
 * tranquille. Un outil de surveillance qui rate ce qu'il annonce surveiller est PIRE que pas
 * d'outil du tout — il transforme une inquiétude saine en fausse sérénité.
 *
 * Chaque test plante donc une anomalie réelle et exige que la commande la nomme. Sans ces
 * tests, la commande pourrait afficher « tout va bien » sur une base malade et personne ne le
 * saurait avant la réclamation d'un client.
 */
class LoyaltyHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        Settings::group('loyalty_setup')->set([
            'loyalty_points_per_euro' => 10,
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points' => 300,
        ]);
    }

    private function client(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'branch_id' => 0,
            'status' => Status::ACTIVE,
            'is_guest' => Ask::YES,
            'loyalty_code' => strtoupper(substr(md5(uniqid('', true)), 0, 8)),
            'loyalty_points' => 0,
        ], $attrs));
    }

    public function test_une_base_saine_ne_declenche_aucune_alarme(): void
    {
        $client = $this->client(['loyalty_points' => 500, 'phone' => '0611111111']);
        LoyaltyTransaction::create([
            'user_id' => $client->id,
            'loyalty_code' => $client->loyalty_code,
            'order_id' => null,
            'type' => 'manual_add',
            'points' => 500,
            'balance_after' => 500,
            'source_surface' => 'admin',
            'description' => 'ouverture',
        ]);

        $this->artisan('fidelite:verifier')
            ->expectsOutputToContain('Soldes : tous cohérents')
            ->expectsOutputToContain('aucun client présent en plusieurs exemplaires')
            ->assertExitCode(0);
    }

    /** UN SOLDE QUE SON HISTOIRE N'EXPLIQUE PAS EST UN SOLDE INDÉFENDABLE. */
    public function test_un_solde_sans_histoire_est_signale(): void
    {
        $this->client(['loyalty_points' => 750, 'phone' => '0622222222']); // aucune écriture

        $this->artisan('fidelite:verifier')
            ->expectsOutputToContain('Soldes incohérents')
            ->assertExitCode(1);
    }

    /** UN CLIENT COUPÉ EN DEUX : SES POINTS SONT SUR LE COMPTE QU'IL NE PRÉSENTE PAS. */
    public function test_un_client_en_double_est_signale(): void
    {
        $this->client(['loyalty_points' => 0, 'phone' => '0633333333']);
        $this->client(['loyalty_points' => 0, 'phone' => '+33633333333']);

        $this->artisan('fidelite:verifier')
            ->expectsOutputToContain('Téléphones en double')
            ->assertExitCode(1);
    }

    /**
     * LE PERSONNEL N'EST PAS UN CLIENT EN DOUBLE.
     *
     * Sans cette distinction, l'outil crie au loup sur les comptes de service qui partagent un
     * numéro de test — et un outil qui crie tout le temps n'est plus lu.
     */
    public function test_le_personnel_partageant_un_numero_n_est_pas_signale(): void
    {
        $caissier = $this->client(['loyalty_points' => 0, 'phone' => '0644444444', 'is_guest' => Ask::NO]);
        $caissier->assignRole('POS Operator');
        $this->client(['loyalty_points' => 0, 'phone' => '+33644444444', 'is_guest' => Ask::NO])
            ->assignRole('Chef');

        $this->artisan('fidelite:verifier')
            ->expectsOutputToContain('aucun client présent en plusieurs exemplaires')
            ->assertExitCode(0);
    }

    /**
     * LA PERTE LA PLUS INJUSTE : le caissier a fait le geste, le client a donné son numéro, et
     * rien n'est arrivé sur son compte.
     */
    public function test_une_vente_rattachee_non_creditee_est_signalee(): void
    {
        $client = $this->client(['loyalty_points' => 0, 'phone' => '0655555555']);

        $branche = \App\Models\Branch::factory()->create();
        Order::factory()->create([
            'branch_id' => $branche->id,
            'order_type' => OrderType::TAKEAWAY,
            'status' => OrderStatus::DELIVERED,
            'total' => 20.00,
            'loyalty_customer_code' => $client->loyalty_code,
            'loyalty_points_awarded' => null,
        ]);

        $this->artisan('fidelite:verifier')
            ->expectsOutputToContain('Ventes rattachées NON créditées')
            ->assertExitCode(1);
    }

    /** DES POINTS QUE PERSONNE NE PEUT DÉPENSER SONT DE L'ARGENT BLOQUÉ. */
    public function test_des_points_sans_code_fidelite_sont_signales(): void
    {
        User::factory()->create([
            'branch_id' => 0,
            'status' => Status::ACTIVE,
            'phone' => '0666666666',
            'loyalty_code' => null,
            'loyalty_points' => 400,
        ]);

        $this->artisan('fidelite:verifier')
            ->expectsOutputToContain('Points inatteignables')
            ->assertExitCode(1);
    }
}
