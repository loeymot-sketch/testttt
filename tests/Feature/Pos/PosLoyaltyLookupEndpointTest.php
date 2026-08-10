<?php

namespace Tests\Feature\Pos;

use App\Enums\Ask;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * LA PORTE DE L'IDENTIFICATION AU COMPTOIR.
 *
 * Le service de recherche est éprouvé par `PosCustomerLookupTest`. Ici on éprouve la ROUTE : qui
 * peut l'ouvrir, ce qu'elle laisse fuir, et ce qui l'empêche de devenir un carnet d'adresses.
 *
 * Cette route dit si un numéro possède un compte : c'est un oracle d'énumération. Elle ne peut donc
 * pas être seulement « pratique » — elle doit être fermée, bornée, et muette sur la PII.
 */
class PosLoyaltyLookupEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/pos-loyalty/lookup';

    private User $caissier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('loyalty.qr.secret', 'test-qr-secret-'.str_repeat('c', 40));
        Settings::group('loyalty_setup')->set([
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points'          => 1000,
        ]);

        $branche = Branch::factory()->create();
        $this->caissier = User::factory()->create(['branch_id' => $branche->id, 'phone' => '0100000001']);
        $this->caissier->assignRole('POS Operator');

        RateLimiter::clear('pos-loyalty-lookup');
    }

    private function client(string $phone, int $points, string $code, array $extra = []): User
    {
        $u = User::factory()->create(array_merge(['phone' => $phone, 'is_guest' => Ask::NO], $extra));
        $u->assignRole('Customer');
        DB::table('users')->where('id', $u->id)->update(['loyalty_code' => $code, 'loyalty_points' => $points]);

        return $u->fresh();
    }

    // ── LA PORTE ─────────────────────────────────────────────────────────────────────────────

    /** Sans session, rien. Une route qui rend un solde de client ne s'ouvre pas au public. */
    public function test_sans_authentification_la_porte_est_fermee(): void
    {
        $this->client('0612345678', 1500, 'PORTE01');

        $this->postJson(self::URL, ['phone' => '0612345678'])->assertStatus(401);
    }

    /** Un compte sans le droit « pos » n'identifie personne — même authentifié. */
    public function test_un_compte_sans_le_droit_caisse_est_refuse(): void
    {
        $this->client('0612345678', 1500, 'PORTE02');

        $quidam = User::factory()->create(['is_guest' => Ask::NO]);
        $quidam->assignRole('Customer');

        $this->actingAs($quidam, 'sanctum')
            ->postJson(self::URL, ['phone' => '0612345678'])
            ->assertStatus(403);
    }

    /** Le caissier, lui, passe. */
    public function test_le_caissier_identifie_son_client(): void
    {
        $this->client('0612345678', 2350, 'OK00001', ['name' => 'Karim B']);

        $r = $this->actingAs($this->caissier, 'sanctum')
            ->postJson(self::URL, ['phone' => '0612345678'])
            ->assertOk()
            ->json('data');

        $this->assertSame('found', $r['status']);
        $this->assertSame('Karim B', $r['customer']['name']);
        $this->assertSame(2350, $r['customer']['balance']);
        $this->assertSame(2300, $r['customer']['usable_points'], 'le reste de 50 points n\'est pas offert');
        $this->assertTrue($r['customer']['can_use']);
    }

    // ── CE QUI NE DOIT PAS SORTIR ────────────────────────────────────────────────────────────

    /**
     * NI NUMÉRO NI E-MAIL EN CLAIR DANS LA RÉPONSE. La file d'attente lit par-dessus l'épaule du
     * caissier, et une session volée ne doit pas rendre un carnet d'adresses.
     */
    public function test_la_reponse_ne_contient_aucune_donnee_en_clair(): void
    {
        $this->client('0612345678', 1500, 'PII0002', ['email' => 'karim.bensalah@example.com']);

        $brut = $this->actingAs($this->caissier, 'sanctum')
            ->postJson(self::URL, ['code' => 'PII0002'])
            ->assertOk()
            ->getContent();

        // On lit le corps BRUT pour les fuites : c'est l'octet qui part sur le réseau qui compte,
        // pas la vue décodée. (Les puces du masque y sont échappées en \u2022 — on les vérifie donc
        // sur la valeur décodée, juste en dessous.)
        $this->assertStringNotContainsString('0612345678', $brut);
        $this->assertStringNotContainsString('karim.bensalah', $brut);

        $client = json_decode($brut, true)['data']['customer'];
        $this->assertSame('06 •• •• •• 78', $client['phone_masked']);
        $this->assertSame('k•••@example.com', $client['email_masked']);
    }

    /** « Pas de compte » est une information de comptoir, pas une panne : 200 et une phrase. */
    public function test_un_client_inconnu_repond_200_et_pas_une_erreur(): void
    {
        $this->actingAs($this->caissier, 'sanctum')
            ->postJson(self::URL, ['phone' => '0699999999'])
            ->assertOk()
            ->assertJsonPath('data.status', 'not_found')
            ->assertJsonPath('data.error_code', 'NO_ACCOUNT');
    }

    // ── L'INTENTION DU CAISSIER ──────────────────────────────────────────────────────────────

    /** Aucun critère : on refuse au lieu de rendre la base entière. */
    public function test_sans_critere_la_requete_est_refusee(): void
    {
        $this->actingAs($this->caissier, 'sanctum')
            ->postJson(self::URL, [])
            ->assertStatus(422);
    }

    /**
     * DEUX CRITÈRES À LA FOIS : on refuse au lieu de deviner. Un numéro et un code qui désignent
     * deux personnes différentes, et la machine qui tranche, c'est le solde de quelqu'un d'autre.
     */
    public function test_deux_criteres_a_la_fois_sont_refuses_au_lieu_d_etre_devines(): void
    {
        $this->client('0612345678', 1500, 'UN00001');
        $this->client('0612000002', 900,  'DEUX001');

        $this->actingAs($this->caissier, 'sanctum')
            ->postJson(self::URL, ['phone' => '0612345678', 'code' => 'DEUX001'])
            ->assertStatus(422);
    }

    // ── L'ORACLE D'ÉNUMÉRATION ───────────────────────────────────────────────────────────────

    /**
     * LE BALAYAGE EST BORNÉ. Un comptoir cherche quelques clients par service ; trente appels par
     * minute est déjà généreux, au-delà ce n'est plus un comptoir.
     */
    public function test_le_balayage_de_numeros_est_borne(): void
    {
        Config::set('pos.rate_limit.loyalty_lookup', 5);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->caissier, 'sanctum')
                ->postJson(self::URL, ['phone' => '060000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)])
                ->assertOk();
        }

        $this->actingAs($this->caissier, 'sanctum')
            ->postJson(self::URL, ['phone' => '0600009999'])
            ->assertStatus(429);
    }

    /**
     * ET LE JOURNAL NE GARDE PAS LES NUMÉROS TAPÉS. Un journal qui les contient est un carnet
     * d'adresses en clair, conservé sans limite et lisible par quiconque lit les journaux.
     */
    public function test_le_journal_ne_conserve_pas_le_numero_cherche(): void
    {
        $lignes = [];
        \Illuminate\Support\Facades\Log::listen(function ($e) use (&$lignes) {
            $lignes[] = $e->message . ' ' . json_encode($e->context);
        });

        $this->client('0612345678', 1500, 'JOURN01');

        $this->actingAs($this->caissier, 'sanctum')
            ->postJson(self::URL, ['phone' => '0612345678'])
            ->assertOk();

        $this->assertNotEmpty($lignes, 'la recherche doit laisser une trace : elle expose de la PII');
        foreach ($lignes as $l) {
            $this->assertStringNotContainsString('0612345678', $l,
                'le numéro cherché est écrit dans le journal');
        }
    }
}
