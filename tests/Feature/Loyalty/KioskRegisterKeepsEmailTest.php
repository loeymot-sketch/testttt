<?php

namespace Tests\Feature\Loyalty;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [FIDÉLITÉ BORNE 2026-08-19] L'EMAIL SAISI À LA BORNE EXISTE-T-IL VRAIMENT APRÈS COUP ?
 *
 * ── LE DÉFAUT REPRODUIT AVANT CORRECTION ─────────────────────────────────────────────────────
 * La borne demande nom + téléphone + email, les envoie, l'API répond 200 « inscrit »… et
 * `register()` écrasait l'email par `null`. Vérifié en direct sur la base réelle le
 * 2026-08-19 : `POST /api/frontend/loyalty/register` avec un email → `users.email = NULL`.
 *
 * Conséquence, et c'est le parcours exact signalé par le propriétaire : le client croyait
 * s'être inscrit avec son adresse et ne pouvait ensuite s'y connecter par AUCUN chemin. La
 * garde anti-« channel-confusion » de `GuestSignupController` livre le code de connexion à
 * l'email DU COMPTE ; sans email stocké elle tombait sur sa branche 2 (compte téléphone-seul
 * AYANT de la valeur → on ne livre à personne). Un client fidèle était donc structurellement
 * enfermé dehors.
 *
 * ── CE QUE CE FICHIER VERROUILLE ─────────────────────────────────────────────────────────────
 * L'email est conservé, MAIS uniquement là où c'est sûr — et les trois gardes qui encadrent
 * cette ouverture doivent rester debout :
 *   1. compte NEUF → email conservé, `email_verified_at` NULL (déclaration, pas preuve) ;
 *   2. compte EXISTANT → email JAMAIS repeint (fix hijack 2026-07-02) ;
 *   3. email déjà pris ailleurs → 409, aucune création ;
 *   4. réinitialisation de mot de passe refusée sur un compte invité — sinon l'email déclaré
 *      suffirait à s'emparer d'un compte porteur de points.
 */
class KioskRegisterKeepsEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function cle(): string
    {
        return (string) config('app.api_key');
    }

    public function test_inscription_borne_conserve_email_prenom_et_telephone(): void
    {
        $reponse = $this->withHeader('x-api-key', $this->cle())
            ->postJson('/api/frontend/loyalty/register', [
                'phone' => '0612345678',
                'name' => 'Karim',
                'email' => 'karim@exemple.fr',
            ]);

        $reponse->assertOk()->assertJsonPath('status', true);

        $client = User::where('phone', '0612345678')->first();
        $this->assertNotNull($client);
        $this->assertSame('Karim', $client->name);
        // LE CŒUR DU CORRECTIF : l'adresse survit à la requête.
        $this->assertSame('karim@exemple.fr', $client->email);
        $this->assertNotEmpty($client->loyalty_code);
        // …mais elle reste une DÉCLARATION : rien ici ne prouve que le client possède l'adresse.
        $this->assertNull($client->email_verified_at);
        $this->assertSame(Ask::YES, (int) $client->is_guest);
    }

    public function test_inscription_sans_email_reste_possible(): void
    {
        $this->withHeader('x-api-key', $this->cle())
            ->postJson('/api/frontend/loyalty/register', [
                'phone' => '0655554444',
                'name' => 'Sans Adresse',
            ])
            ->assertOk();

        $this->assertNull(User::where('phone', '0655554444')->first()->email);
    }

    /**
     * LA GARDE DE 2026-07-02 NE DOIT PAS TOMBER AVEC CETTE OUVERTURE.
     * Repeindre l'email d'un compte DÉJÀ EXISTANT depuis un endpoint public, c'est offrir la
     * récupération de ce compte à qui connaît le numéro.
     */
    public function test_un_compte_existant_ne_se_fait_pas_repeindre_son_email(): void
    {
        $existant = User::factory()->create([
            'phone' => '0611223344',
            'email' => 'proprietaire@exemple.fr',
            'branch_id' => 0,
            'status' => Status::ACTIVE,
            'is_guest' => Ask::YES,
        ]);

        $this->withHeader('x-api-key', $this->cle())
            ->postJson('/api/frontend/loyalty/register', [
                'phone' => '0611223344',
                'name' => 'Attaquant',
                'email' => 'attaquant@exemple.fr',
            ])
            ->assertOk()
            ->assertJsonPath('code', 'PHONE_EXISTS');

        $existant->refresh();
        $this->assertSame('proprietaire@exemple.fr', $existant->email);
    }

    public function test_un_email_deja_pris_ailleurs_est_refuse_sans_rien_creer(): void
    {
        User::factory()->create([
            'phone' => '0600000001',
            'email' => 'deja@exemple.fr',
            'branch_id' => 0,
        ]);

        $this->withHeader('x-api-key', $this->cle())
            ->postJson('/api/frontend/loyalty/register', [
                'phone' => '0699999999',
                'name' => 'Autre',
                'email' => 'deja@exemple.fr',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'EMAIL_EXISTS');

        $this->assertNull(User::where('phone', '0699999999')->first());
    }

    /**
     * LA CONTREPARTIE DÉFENSIVE. On ne pose pas un PREMIER mot de passe sur le compte d'un
     * autre en appelant ça « réinitialiser ».
     */
    public function test_pas_de_reinitialisation_de_mot_de_passe_sur_un_compte_invite(): void
    {
        User::factory()->create([
            'phone' => '0612345678',
            'email' => 'talon@exemple.fr',
            'branch_id' => 0,
            'status' => Status::ACTIVE,
            'is_guest' => Ask::YES,
            'loyalty_points' => 1500,
        ]);

        $this->withHeader('x-api-key', $this->cle())
            ->postJson('/api/auth/forgot-password', ['email' => 'talon@exemple.fr']);

        // Le refus se mesure à l'ABSENCE de code émis, pas au texte de la réponse : celle-ci
        // reste volontairement identique au cas « email inconnu » (anti-énumération).
        $this->assertDatabaseMissing('password_resets', ['email' => 'talon@exemple.fr']);
    }

    public function test_un_vrai_compte_client_garde_sa_recuperation_de_mot_de_passe(): void
    {
        User::factory()->create([
            'phone' => '0622334455',
            'email' => 'vrai.client@exemple.fr',
            'branch_id' => 0,
            'status' => Status::ACTIVE,
            'is_guest' => Ask::NO,
        ]);

        $this->withHeader('x-api-key', $this->cle())
            ->postJson('/api/auth/forgot-password', ['email' => 'vrai.client@exemple.fr']);

        $this->assertTrue(
            DB::table('password_resets')->where('email', 'vrai.client@exemple.fr')->exists(),
            'Fermer la porte aux talons ne doit PAS fermer celle des vrais comptes.'
        );
    }
}
