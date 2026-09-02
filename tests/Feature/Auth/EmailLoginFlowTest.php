<?php

namespace Tests\Feature\Auth;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Mail\SignupOtpMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * [APP MOBILE 2026-09-02 — GOAL_APP_MOBILE_APPSTORE §A] Parcours compte « e-mail d'abord ».
 *
 * Demande propriétaire : « s'il a un compte, juste un mail, il reçoit le code ; s'il n'a pas
 * de compte, sans changer de page, on lui demande le prénom et le téléphone, le code arrive
 * par e-mail ». Deux nouveautés serveur :
 *   - POST /api/auth/guest-signup/email-login : {email} → connu / inconnu ;
 *     {email, first_name, phone} → inscription (moteur email-otp, anti-usurpation conservée).
 *   - POST /api/auth/guest-signup/verify accepte {email, token} : le SERVEUR résout le compte,
 *     jamais le client (le téléphone d'un compte ne sort jamais vers l'appelant).
 *
 * Choix assumé : `known` révèle qu'un e-mail a un compte. Décision propriétaire (UX sans
 * page d'inscription), mitigée par le débit `otp-send` (5/min par e-mail, 20/min global).
 */
class EmailLoginFlowTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key';
    private const PHONE = '0699555002';
    private const EMAIL = 'sarah.dupont@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        if (!file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        DB::table('settings')->insert([
            ['key' => 'site_guest_login',        'payload' => json_encode(Activity::ENABLE),  'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'site_phone_verification', 'payload' => json_encode(Activity::DISABLE), 'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'site_default_branch',     'payload' => json_encode(1),                 'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'otp_expire_time',         'payload' => json_encode(5),                 'group' => 'otp',  'created_at' => now(), 'updated_at' => now()],
        ]);

        config(['app.api_key' => self::API_KEY]);
        $this->withHeaders([
            'x-api-key' => self::API_KEY,
            'Accept'    => 'application/json',
        ]);
    }

    private function guest(array $attrs = []): User
    {
        $user = User::create(array_merge([
            'name'           => 'Sarah Dupont',
            'username'       => 'sarah-'.uniqid(),
            'phone'          => self::PHONE,
            'country_code'   => '+33',
            'email'          => self::EMAIL,
            'branch_id'      => 0,
            'is_guest'       => Ask::YES,
            'loyalty_points' => 120,
            'status'         => \App\Enums\Status::ACTIVE,
            'password'       => bcrypt('x'),
        ], $attrs));
        $user->assignRole(\App\Enums\Role::CUSTOMER);
        // loyalty_points n'est pas assignable en masse (vérifié : ignoré par create()).
        $user->loyalty_points = (int) ($attrs['loyalty_points'] ?? 120);
        $user->save();

        return $user;
    }

    private function emailLogin(array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/guest-signup/email-login', $body);
    }

    private function tokenFor(string $key): ?string
    {
        $token = DB::table('otps')->where('phone', $key)->latest('created_at')->value('token');

        return $token === null ? null : (string) $token;
    }

    /** (1) E-mail connu → code sur le téléphone du compte, envoyé à l'e-mail DU COMPTE, verify par e-mail = connexion. */
    public function test_known_email_sends_code_to_account_email_and_verify_by_email_logs_in(): void
    {
        Mail::fake();
        $compte = $this->guest();

        $r = $this->emailLogin(['email' => self::EMAIL]);
        $r->assertStatus(200)->assertJsonPath('status', true)->assertJsonPath('known', true);

        $token = $this->tokenFor(self::PHONE);
        $this->assertNotNull($token, 'Le code doit être posé sur le téléphone du compte (clé fidélité).');
        Mail::assertSent(SignupOtpMail::class, fn (SignupOtpMail $m) => $m->hasTo(self::EMAIL) && $m->otpCode === $token);

        $v = $this->postJson('/api/auth/guest-signup/verify', ['email' => self::EMAIL, 'token' => $token]);
        $v->assertStatus(201);
        $this->assertNotEmpty($v->json('token'));
        $this->assertFalse((bool) $v->json('phone_required'));

        $this->assertSame(1, User::withoutGlobalScopes()->withTrashed()->count(), 'Aucun doublon de compte.');
        $this->assertSame('Sarah Dupont', $compte->fresh()->name);
        $this->assertSame(120, (int) $v->json('user.loyalty_points'), 'Le client retrouve SES points.');
    }

    /** (2) E-mail inconnu, sans identité → known:false, aucun code, aucun e-mail. */
    public function test_unknown_email_returns_known_false_and_sends_nothing(): void
    {
        Mail::fake();

        $this->emailLogin(['email' => 'personne@example.com'])
            ->assertStatus(200)->assertJsonPath('status', true)->assertJsonPath('known', false)->assertJsonPath('sent', false);

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('otps')->count());
        $this->assertSame(0, User::withoutGlobalScopes()->withTrashed()->count());
    }

    /** (3) E-mail inconnu + prénom + téléphone → code par e-mail, verify crée « Prénom » avec l'e-mail attaché. */
    public function test_unknown_email_with_first_name_and_phone_creates_account_after_verify(): void
    {
        Mail::fake();

        $r = $this->emailLogin(['email' => 'mourad@example.com', 'first_name' => 'Mourad', 'phone' => '0699555003']);
        $r->assertStatus(200)->assertJsonPath('known', false)->assertJsonPath('sent', true);

        $token = $this->tokenFor('0699555003');
        $this->assertNotNull($token);
        Mail::assertSent(SignupOtpMail::class, fn (SignupOtpMail $m) => $m->hasTo('mourad@example.com') && $m->otpCode === $token);

        $this->postJson('/api/auth/guest-signup/verify', ['code' => '+33', 'phone' => '0699555003', 'token' => $token])
            ->assertStatus(201);

        $user = User::withoutGlobalScopes()->where('phone', '0699555003')->first();
        $this->assertNotNull($user);
        $this->assertSame('Mourad', $user->name, 'Le prénom seul suffit (le nom de famille n\'est plus exigé).');
        $this->assertSame('mourad@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    /** (3b) Téléphone fourni sans prénom → 422 de validation, rien n'est envoyé. */
    public function test_signup_mode_requires_first_name_when_phone_is_given(): void
    {
        Mail::fake();
        $this->emailLogin(['email' => 'mourad@example.com', 'phone' => '0699555003'])
            ->assertStatus(422)->assertJsonValidationErrors(['first_name']);
        Mail::assertNothingSent();
    }

    /** (4) La casse de l'e-mail ne compte pas. */
    public function test_known_email_lookup_is_case_insensitive(): void
    {
        Mail::fake();
        $this->guest();
        $this->emailLogin(['email' => 'Sarah.Dupont@Example.COM'])->assertStatus(200)->assertJsonPath('known', true);
        Mail::assertSent(SignupOtpMail::class, fn (SignupOtpMail $m) => $m->hasTo(self::EMAIL));
    }

    /** (5) L'e-mail d'un compte NON invité (staff) est traité comme inconnu : jamais de code, jamais de token. */
    public function test_staff_email_is_treated_as_unknown_and_never_logs_in(): void
    {
        Mail::fake();
        User::create([
            'name' => 'Manager', 'username' => 'mgr-'.uniqid(), 'phone' => '0699555009',
            'email' => 'manager@cayenne.fr', 'branch_id' => 1, 'is_guest' => Ask::NO,
            'status' => \App\Enums\Status::ACTIVE, 'password' => bcrypt('secret-staff'),
        ]);

        $this->emailLogin(['email' => 'manager@cayenne.fr'])->assertStatus(200)->assertJsonPath('known', false);
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('otps')->count());

        $this->postJson('/api/auth/guest-signup/verify', ['email' => 'manager@cayenne.fr', 'token' => '1234'])
            ->assertStatus(422);
    }

    /** (6) Compte invité SANS numéro (connexion Apple/Google) : clé synthétique, pas de doublon, phone_required=true. */
    public function test_known_account_without_phone_uses_synthetic_key_and_creates_no_duplicate(): void
    {
        Mail::fake();
        $this->guest(['phone' => null, 'email' => 'social@example.com', 'name' => 'Léa']);

        $this->emailLogin(['email' => 'social@example.com'])->assertStatus(200)->assertJsonPath('known', true);

        $token = $this->tokenFor('email:social@example.com');
        $this->assertNotNull($token, 'Sans numéro, le code est posé sur la clé email:<adresse>.');
        Mail::assertSent(SignupOtpMail::class, fn (SignupOtpMail $m) => $m->hasTo('social@example.com'));

        $v = $this->postJson('/api/auth/guest-signup/verify', ['email' => 'social@example.com', 'token' => $token]);
        $v->assertStatus(201);
        $this->assertTrue((bool) $v->json('phone_required'), 'Le compte sans numéro doit être invité à en donner un.');
        $this->assertSame(1, User::withoutGlobalScopes()->withTrashed()->count());
        $this->assertNull(User::withoutGlobalScopes()->where('phone', 'email:social@example.com')->first(), 'La clé synthétique ne doit JAMAIS devenir un téléphone de compte.');
    }

    /**
     * (7) SENTINELLE — aucune porte dérobée d'examen App Store.
     *
     * J'avais posé un code de connexion FIXE pour une adresse configurée (APP_REVIEW_EMAIL /
     * APP_REVIEW_OTP), afin que l'examinateur Apple puisse se connecter sans lire nos e-mails.
     * `app/PUBLICATION.md §8` du dépôt du site tranchait déjà l'inverse : « un code fixe pour
     * une adresse connue est une porte qu'on oublie de refermer ». Et le garde-fou habituel du
     * projet — interdire en production — ne peut pas s'appliquer, puisque l'examen se fait
     * CONTRE la production. Le mécanisme a donc été retiré ; l'examinateur reçoit son code
     * dans une boîte de démonstration dédiée (procédure PUBLICATION.md §8).
     *
     * Ce test verrouille la décision : quelle que soit la configuration, le code d'un compte
     * connu reste ALÉATOIRE et part par e-mail. Il rougirait si quelqu'un remettait la porte.
     */
    public function test_aucun_code_fixe_de_revue_app_store(): void
    {
        Mail::fake();
        // On tente de rallumer l'ancienne porte par tous ses noms — elle ne doit plus exister.
        config([
            'auth.app_review.email'     => 'revue@lecayenne.fr',
            'auth.app_review.otp'       => '246810',
            'security.app_review.email' => 'revue@lecayenne.fr',
            'security.app_review.otp'   => '246810',
        ]);
        $this->guest(['email' => 'revue@lecayenne.fr', 'phone' => '0699555010', 'name' => 'Revue Apple']);

        $this->emailLogin(['email' => 'revue@lecayenne.fr'])->assertStatus(200)->assertJsonPath('known', true);

        $token = $this->tokenFor('0699555010');
        $this->assertNotSame('246810', $token, 'Un code fixe de revue a été réintroduit — porte dérobée.');
        $this->assertMatchesRegularExpression('/^\d{4,6}$/', (string) $token);
        Mail::assertSent(SignupOtpMail::class, fn (SignupOtpMail $m) => $m->hasTo('revue@lecayenne.fr'));

        // Et le code fixe ne doit ouvrir aucune session.
        $this->postJson('/api/auth/guest-signup/verify', ['email' => 'revue@lecayenne.fr', 'token' => '246810'])
            ->assertStatus(422);
    }

    /** (8) verify sans téléphone NI e-mail → 422, jamais de token. */
    public function test_verify_requires_phone_or_email(): void
    {
        $this->postJson('/api/auth/guest-signup/verify', ['token' => '1234'])->assertStatus(422);
    }

    /** (9) dev_code : renvoyé en `local` (banc E2E), absent ailleurs — même garde que otp(). */
    public function test_dev_code_only_in_local_environment(): void
    {
        Mail::fake();
        $this->guest();

        $this->app->detectEnvironment(fn () => 'local');
        $r = $this->emailLogin(['email' => self::EMAIL])->assertStatus(200);
        $this->assertSame($this->tokenFor(self::PHONE), $r->json('dev_code'));

        $this->app->detectEnvironment(fn () => 'staging');
        $r2 = $this->emailLogin(['email' => self::EMAIL])->assertStatus(200);
        $this->assertNull($r2->json('dev_code'), 'Hors local, le code ne sort JAMAIS dans la réponse.');
    }
}
