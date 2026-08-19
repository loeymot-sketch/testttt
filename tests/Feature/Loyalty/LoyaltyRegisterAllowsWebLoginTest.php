<?php

namespace Tests\Feature\Loyalty;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [REGISTRE finding P2-v — /loyalty/register verrouillait le login web d'un téléphone]
 *
 * LoyaltyController::register() créait un compte fidélité-seul (username uniqid('kiosk_'),
 * mot de passe aléatoire, AUCUN rôle) SANS renseigner is_guest → défaut colonne Ask::NO(10).
 * Conséquence : les portillons de login web refusaient ce numéro à jamais —
 *   - GuestSignupController:102 : `is_guest != YES` → throw 422 (traité comme staff/admin) ;
 *   - SignupController:88       : ne revendique QUE `is_guest === YES` → 422 ;
 *   - SignupRequest / SignupPhoneRequest : `unique(phone)->where('is_guest', NO)` → phone « pris ».
 *
 * Fix (Option A) : un compte fidélité-seul EST un invité en attente d'un vrai login →
 * register() le marque `is_guest = YES(5)`, ce qui le rend revendicable par les 4 portillons
 * existants (déjà audités) SANS élargir leur surface d'attaque : un VRAI compte staff/web
 * (is_guest=NO + rôle + mot de passe établi) reste is_guest=NO donc TOUJOURS refusé.
 */
class LoyaltyRegisterAllowsWebLoginTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key';

    protected function setUp(): void
    {
        parent::setUp();

        if (!file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        // GuestSignupController::register() dépend de ces 3 réglages site.
        DB::table('settings')->insert([
            ['key' => 'site_guest_login',        'payload' => json_encode(Activity::ENABLE),  'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'site_phone_verification', 'payload' => json_encode(Activity::DISABLE), 'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'site_default_branch',     'payload' => json_encode(1),                 'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            // [P0 OTP-BYPASS 2026-07-20] verify() passe désormais TOUJOURS par
            // OtpManagerService::verify() ; otp_expire_time doit être posé (sinon `* 60` = 0).
            ['key' => 'otp_expire_time',         'payload' => json_encode(5),                 'group' => 'otp',  'created_at' => now(), 'updated_at' => now()],
        ]);

        config(['app.api_key' => self::API_KEY]);
        $this->withHeaders([
            'x-api-key' => self::API_KEY,
            'Accept'    => 'application/json',
        ]);
    }

    /** [P0 OTP-BYPASS 2026-07-20] Pose un OTP réel non-expiré (pattern « OTP lu en table »). */
    private function seedOtp(string $phone, string $token = 'test-token'): void
    {
        DB::table('otps')->insert([
            'phone'      => $phone,
            'code'       => '+33',
            'token'      => $token,
            'created_at' => now(),
        ]);
    }

    private function setPhoneVerification(int $mode): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'site_phone_verification', 'group' => 'site'],
            ['payload' => json_encode($mode), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private function registerLoyalty(string $phone, ?string $name = 'Client Loyalty'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/frontend/loyalty/register', array_filter([
            'phone' => $phone,
            'name'  => $name,
        ]));
    }

    /**
     * @test
     * (c) NON-RÉGRESSION : register() crée toujours le compte fidélité (code + 0 points).
     */
    public function loyalty_register_still_creates_a_loyalty_account(): void
    {
        $res = $this->registerLoyalty('0699000010', 'Alice');

        $res->assertStatus(200)->assertJsonPath('status', true);

        $u = User::where('phone', '0699000010')->first();
        $this->assertNotNull($u, 'Le compte fidélité doit être créé.');
        $this->assertNotEmpty($u->loyalty_code, 'Un loyalty_code doit être généré.');
        $this->assertSame(0, (int) $u->loyalty_points, 'Le solde de points démarre à 0.');
    }

    /**
     * @test
     * Coeur du fix : un compte fidélité-créé est REVENDICABLE (is_guest = YES),
     * sans rôle et avec un mot de passe non-établi (aléatoire).
     */
    public function loyalty_created_account_is_claimable_is_guest_yes(): void
    {
        $this->registerLoyalty('0699000011');

        $u = User::where('phone', '0699000011')->first();
        $this->assertNotNull($u);
        $this->assertSame(Ask::YES, (int) $u->is_guest, 'Un compte fidélité-seul doit être revendicable (is_guest=YES).');
        $this->assertCount(0, $u->roles, 'Un compte fidélité-seul ne doit avoir AUCUN rôle.');
    }

    /**
     * @test
     * (a) register() PUIS guest-signup/verify sur ce téléphone → SUCCÈS (login web possible).
     */
    public function loyalty_phone_can_then_login_via_guest_signup(): void
    {
        $this->setPhoneVerification(Activity::DISABLE);
        $this->registerLoyalty('0699000012', 'Bob');
        $this->seedOtp('0699000012'); // verify() exige un OTP réel (durcissement P0)

        $res = $this->postJson('/api/auth/guest-signup/verify', [
            'code'  => '+33',
            'phone' => '0699000012',
            'token' => 'test-token',
        ]);

        $res->assertStatus(201)->assertJsonStructure(['token']);
    }

    /**
     * @test
     * (a') register() PUIS signup/register (vrai compte web) → SUCCÈS + fidélité PRÉSERVÉE.
     */
    public function loyalty_phone_can_then_create_full_web_account_and_keeps_loyalty(): void
    {
        $this->setPhoneVerification(Activity::ENABLE);
        $this->registerLoyalty('0699000013', 'Carol');
        $loyaltyCode = User::where('phone', '0699000013')->first()->loyalty_code;
        $this->assertNotEmpty($loyaltyCode);

        // Simule un verify() OTP réussi (marqueur one-time consommé par SignupController).
        Cache::put('phone_verified:0699000013', true, now()->addMinutes(5));

        $res = $this->postJson('/api/auth/signup/register', [
            'first_name'   => 'Carol',
            'last_name'    => 'Web',
            'email'        => 'carol-web@local.test',
            'phone'        => '0699000013',
            'country_code' => '33',
            'password'     => 'web-password-123456',
        ]);

        $res->assertStatus(201);

        $u = User::where('phone', '0699000013')->first();
        $this->assertSame(Ask::NO, (int) $u->is_guest, 'Le compte est promu en compte plein.');
        $this->assertSame($loyaltyCode, $u->loyalty_code, 'Le loyalty_code (et les points) sont PRÉSERVÉS lors de l\'upgrade.');
        $this->assertTrue(Hash::check('web-password-123456', $u->password), 'Le mot de passe web réel est posé.');
    }

    /**
     * @test
     * (b) SÉCURITÉ : un VRAI compte web établi (is_guest=NO + rôle + mot de passe) n'est PAS
     * revendicable — ni via guest-signup, ni via signup (pas de prise de contrôle).
     */
    public function an_established_web_account_is_not_claimable(): void
    {
        $victim = User::factory()->create([
            'phone'    => '0699000014',
            'is_guest' => Ask::NO,
            'email'    => 'victim-web@local.test',
            'password' => Hash::make('victim-real-secret'),
        ]);
        $victim->assignRole('Customer');
        $origPassword = $victim->password;

        // (b1) guest-signup ne peut PAS revendiquer un compte plein → 422.
        // OTP réel posé : verify() PASSE, puis register() rejette sur is_guest=NO —
        // on prouve ainsi le VRAI portillon (compte plein), pas juste un OTP manquant.
        $this->setPhoneVerification(Activity::DISABLE);
        $this->seedOtp('0699000014');
        $g = $this->postJson('/api/auth/guest-signup/verify', [
            'code'  => '+33',
            'phone' => '0699000014',
            'token' => 'test-token',
        ]);
        $g->assertStatus(422);

        // (b2) signup ne peut PAS écraser un compte plein, MÊME avec un téléphone « prouvé » → 422.
        Cache::put('phone_verified:0699000014', true, now()->addMinutes(5));
        $s = $this->postJson('/api/auth/signup/register', [
            'first_name'   => 'Mallory',
            'last_name'    => 'Attacker',
            'email'        => 'attacker@evil.com',
            'phone'        => '0699000014',
            'country_code' => '33',
            'password'     => 'attacker-password-123456',
        ]);
        $s->assertStatus(422);

        $victim->refresh();
        $this->assertSame($origPassword, $victim->password, 'Mot de passe de la victime INTACT.');
        $this->assertSame(Ask::NO, (int) $victim->is_guest, 'Compte plein NON rétrogradé/altéré.');
        $this->assertNotSame('attacker@evil.com', $victim->email, 'Email de la victime INTACT.');
    }

    /**
     * [P1-1 SÉCU 2026-08-04, PARAMÉTRÉ LE 2026-08-19] SQUATTING sur un NOUVEAU numéro.
     *
     * Un attaquant enrôle en fidélité (endpoint PUBLIC non-auth) un téléphone tiers avec SON
     * email. S'il est écrit sur le compte, la garde channel-confusion de l'email-OTP livrera
     * ensuite le code de connexion au squatteur.
     *
     * ── POURQUOI CE TEST A CHANGÉ DE FORME ───────────────────────────────────────────────────
     * Il assertait `email === null` en dur. Cette prudence avait un coût qui n'avait jamais été
     * mesuré : la borne demande un email, l'API répondait « inscrit » et le jetait, si bien
     * qu'un client inscrit à la borne n'avait ENSUITE aucun moyen de se connecter — ni par son
     * email (jamais stocké), ni par celui qu'il retapait (la même garde, branche 2, refuse de
     * livrer à l'email de l'appelant dès que le compte porte des points). Le propriétaire a
     * tranché le 2026-08-19 : le parcours doit fonctionner.
     *
     * La décision de sécurité n'est pas effacée, elle est devenue un RÉGLAGE
     * (`loyalty.kiosk_email_capture`). Ce test éprouve donc LES DEUX positions — sinon la
     * position prudente deviendrait du code mort que plus rien ne vérifie.
     */
    public function test_public_register_does_not_bind_unverified_attacker_email_when_capture_disabled(): void
    {
        // Position prudente (LOYALTY_KIOSK_EMAIL_CAPTURE=false) : comportement du 2026-08-04.
        config(['loyalty.kiosk_email_capture' => false]);

        $this->postJson('/api/frontend/loyalty/register', [
            'name'  => 'Victime',
            'phone' => '+33699000777',
            'email' => 'attacker@evil.com',
        ])->assertStatus(200);

        // [2026-08-19] `register()` enregistre desormais la forme canonique : « +33699000777 »
        // est stocke « 0699000777 ». Chercher l'ecriture brute ne trouverait plus rien — et ce
        // test conclurait a tort que le compte n'a pas ete cree.
        $created = \App\Models\User::whereIn('phone', app(\App\Services\Identity\PhoneIdentity::class)->variants('+33699000777'))->first();
        $this->assertNotNull($created, 'compte fidélité créé');
        $this->assertNull($created->email, 'réglage OFF : l\'email non vérifié NE doit PAS être lié au compte');
    }

    /**
     * Position par défaut (arbitrage propriétaire) : l'email EST conservé — mais il reste une
     * DÉCLARATION, et la porte par laquelle le risque deviendrait une prise de contrôle
     * complète (poser un premier mot de passe sur le compte d'un autre) est fermée.
     */
    public function test_capture_enabled_stores_email_but_leaves_it_unverified_and_closes_password_reset(): void
    {
        config(['loyalty.kiosk_email_capture' => true]);

        $this->postJson('/api/frontend/loyalty/register', [
            'name'  => 'Client Borne',
            'phone' => '+33699000778',
            'email' => 'client.borne@exemple.fr',
        ])->assertStatus(200);

        $cree = \App\Models\User::whereIn('phone', app(\App\Services\Identity\PhoneIdentity::class)->variants('+33699000778'))->first();
        $this->assertNotNull($cree);
        $this->assertSame('client.borne@exemple.fr', $cree->email, 'réglage ON : l\'email saisi est conservé');
        $this->assertNull($cree->email_verified_at, 'une adresse déclarée à la borne n\'est PAS une preuve');

        // La contrepartie défensive : pas de « réinitialisation » sur un talon invité.
        $this->postJson('/api/auth/forgot-password', ['email' => 'client.borne@exemple.fr']);
        $this->assertDatabaseMissing('password_resets', ['email' => 'client.borne@exemple.fr']);
    }

}
