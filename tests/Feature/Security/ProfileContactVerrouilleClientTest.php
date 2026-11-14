<?php

namespace Tests\Feature\Security;

use App\Enums\Ask;
use App\Enums\Role as EnumRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [W7-D D-003 2026-11-04] `PUT /api/profile` laissait un CLIENT poser n'importe quel e-mail / téléphone non encore
 * inscrit, sans vérification : un attaquant pré-revendiquait le contact d'une future cible ; l'inscription ultérieure de
 * la cible (par e-mail OU par téléphone, `GuestSignupController` restaure le compte connu) atterrissait dans le compte
 * de l'attaquant, qui gardait ses jetons. Le site et l'application ne proposent d'ailleurs pas ce changement
 * (« Pour modifier ton nom, ton e-mail ou ton téléphone, demande en caisse », `loyalty-v2.jsx`).
 *
 * Règle : un compte client (`is_guest = OUI` ou rôle Client) ne change ni son e-mail ni son téléphone par cette route ;
 * son nom, oui. Le personnel (admin, caissier) garde le comportement existant.
 */
class ProfileContactVerrouilleClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key']);
        $this->withHeaders(['x-api-key' => 'test-api-key', 'Accept' => 'application/json']);
    }

    private function client(): User
    {
        $u = User::factory()->create([
            'name'         => 'Client Un',
            'branch_id'    => 0,
            'status'       => 5,
            'is_guest'     => Ask::YES,
            'email'        => 'client-un@example.test',
            'phone'        => '0612345678',
            'country_code' => '+33',
        ]);
        $u->assignRole(EnumRole::CUSTOMER);

        return $u;
    }

    private function charge(array $sur = []): array
    {
        return $sur + [
            'first_name'   => 'Client',
            'last_name'    => 'Un',
            'email'        => 'client-un@example.test',
            'phone'        => '0612345678',
            'country_code' => '+33',
        ];
    }

    /** @test */
    public function un_client_peut_changer_son_nom_sans_toucher_a_son_contact(): void
    {
        $u = $this->client();
        Sanctum::actingAs($u, ['kiosk:order']);

        $this->putJson('/api/profile', $this->charge(['first_name' => 'Nouveau', 'last_name' => 'Nom']))
            ->assertStatus(200);

        $this->assertSame('Nouveau Nom', $u->fresh()->name);
    }

    /** @test */
    public function un_client_ne_peut_pas_revendiquer_un_email_non_inscrit(): void
    {
        $u = $this->client();
        $avant = $u->fresh()->email_verified_at;
        Sanctum::actingAs($u, ['kiosk:order']);

        $r = $this->putJson('/api/profile', $this->charge(['email' => 'victime@example.test']));

        $r->assertStatus(422);
        $this->assertStringContainsString('caisse', mb_strtolower((string) $r->getContent()));
        $frais = $u->fresh();
        $this->assertSame('client-un@example.test', $frais->email);
        $this->assertEquals($avant, $frais->email_verified_at);
    }

    /** @test */
    public function un_client_ne_peut_pas_revendiquer_un_telephone_non_inscrit(): void
    {
        $u = $this->client();
        Sanctum::actingAs($u, ['kiosk:order']);

        $this->putJson('/api/profile', $this->charge(['phone' => '0699770009']))
            ->assertStatus(422);

        $this->assertSame('0612345678', $u->fresh()->phone);
    }

    /**
     * [W7-D D-009 · cycle B 2026-11-04] Le MÊME numéro écrit autrement (« +33 6 12 34 56 78 », « 06 12 34 56 78 ») passe,
     * et le numéro enregistré reste strictement « 0612345678 » (jamais réécrit avec des espaces : `guest-signup` cherche
     * `where('phone', …)` à l'octet près).
     *
     * @test
     */
    public function le_meme_numero_autrement_ecrit_passe_sans_reecrire_le_numero_enregistre(): void
    {
        $u = $this->client();
        Sanctum::actingAs($u, ['kiosk:order']);

        // ValidPhone n'accepte que les formes sans espace (E.164 ou national) : on teste celles qui passent la validation.
        foreach (['+33612345678', '0612345678', 'CLIENT-UN@EXAMPLE.TEST'] as $variante) {
            $charge = str_contains($variante, '@') ? $this->charge(['email' => $variante]) : $this->charge(['phone' => $variante]);
            $this->putJson('/api/profile', $charge)->assertStatus(200);
            $frais = $u->fresh();
            $this->assertSame('0612345678', $frais->phone, $variante);
            $this->assertSame('client-un@example.test', $frais->email, $variante);
        }
    }

    /**
     * [W7-D D-013 · cycle C 2026-11-04] L'indicatif pilote l'OTP : un client ne le change pas plus que son numéro.
     *
     * @test
     */
    public function un_client_ne_change_pas_son_indicatif(): void
    {
        $u = $this->client();
        Sanctum::actingAs($u, ['kiosk:order']);

        $this->putJson('/api/profile', $this->charge(['country_code' => '+32']))->assertStatus(422);
        $this->assertSame('+33', $u->fresh()->country_code);

        $this->putJson('/api/profile', $this->charge(['country_code' => '0033']))->assertStatus(200);
        $this->assertSame('+33', $u->fresh()->country_code);
    }

    /** @test */
    public function le_personnel_garde_le_comportement_existant(): void
    {
        $staff = User::factory()->create([
            'name'         => 'Admin Test',
            'branch_id'    => 0,
            'status'       => 5,
            'is_guest'     => Ask::NO,
            'email'        => 'admin-test@example.test',
            'phone'        => '0611111111',
            'country_code' => '+33',
        ]);
        $staff->assignRole(EnumRole::ADMIN);
        Sanctum::actingAs($staff);

        $this->putJson('/api/profile', [
            'first_name'   => 'Admin',
            'last_name'    => 'Test',
            'email'        => 'admin-nouveau@example.test',
            'phone'        => '0611111111',
            'country_code' => '+33',
        ])->assertStatus(200);

        $this->assertSame('admin-nouveau@example.test', $staff->fresh()->email);
    }
}
