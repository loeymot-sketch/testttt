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

    /**
     * [Root cause 2026-09-18, propriétaire : « parfois un client enregistré, je ne trouve pas
     * son compte »] Reproduit en local : un e-mail-signup dont l'ENVOI DU CODE échoue (SMTP
     * injoignable en environnement réel — bounce, provider en panne, adresse invalide) laisse
     * une ligne `otps` déjà écrite AVANT l'échec de Mail::send(), renvoie 422 au client, et ne
     * journalise RIEN nulle part. Le client ne reçoit jamais son code → `register()` (appelé
     * uniquement depuis `verify()`) ne s'exécute jamais → AUCUN compte n'est créé. Le
     * propriétaire, cherchant plus tard « il m'a dit qu'il s'était inscrit », ne trouve
     * personne et n'a aucune trace pour comprendre pourquoi.
     *
     * Ce test verrouille la correction : l'échec doit au moins laisser une trace journalisée,
     * exploitable pour retrouver CE numéro/e-mail précis quand le propriétaire signale le cas.
     */
    public function test_email_send_failure_during_signup_is_logged_for_later_investigation(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        \Illuminate\Support\Facades\Mail::shouldReceive('to')->once()->andReturnUsing(function () {
            throw new \Exception('Connection could not be established with host "smtp.example.com:587": simulated SMTP outage');
        });

        $r = $this->emailLogin(['email' => 'client-perdu@example.com', 'first_name' => 'Perdu', 'phone' => '0699555099']);
        $r->assertStatus(422);

        // L'état dangereux existe bel et bien : un jeton a été écrit avant l'échec d'envoi.
        $this->assertNotNull($this->tokenFor('0699555099'), 'sanity : la ligne otps existe malgré l\'échec');

        // Aucun compte n'a pu être créé (verify() n'a jamais eu lieu) — exactement le symptôme
        // rapporté : « client enregistré » introuvable, parce qu'il n'existe pas.
        $this->assertNull(User::withoutGlobalScopes()->where('phone', '0699555099')->first());

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) {
                return str_contains($message, 'guest_signup')
                    && ($context['phone'] ?? null) === '0699555099';
            })
            ->once();
    }

    /** (3b) Téléphone fourni sans prénom → 422 de validation, rien n'est envoyé. */
    public function test_signup_mode_requires_first_name_when_phone_is_given(): void
    {
        Mail::fake();
        $this->emailLogin(['email' => 'mourad@example.com', 'phone' => '0699555003'])
            ->assertStatus(422)->assertJsonValidationErrors(['first_name']);
        Mail::assertNothingSent();
    }

    /**
     * [Root cause 2026-09-19, propriétaire : « plusieurs personnes n'arrivent pas à créer un
     * compte, ils mettent les e-mails, ils reçoivent jamais le code par e-mail »]
     *
     * envoyerCodeParEmail() décide, à raison (anti-usurpation, GAP « channel-confusion »), de
     * n'envoyer AUCUN e-mail quand le TÉLÉPHONE saisi appartient déjà à un compte invité AYANT
     * DE LA VALEUR (points fidélité ou commandes) mais SANS e-mail au dossier — livrer vers
     * l'e-mail que l'appelant vient de taper prouverait la possession du TÉLÉPHONE, jamais de
     * l'E-MAIL, et ouvrirait un vol de compte. Le défaut n'est pas cette garde (à garder telle
     * quelle) : c'est que la réponse mentait quand même « code envoyé », sans aucun recours
     * pour le VRAI client (celui qui a réellement ce téléphone) qui essaie juste d'ajouter son
     * adresse à son propre compte. Reproduit ici exactement comme au comptoir : un compte
     * invité avec des points, un téléphone, aucun e-mail.
     */
    public function test_a_phone_already_holding_loyalty_value_without_an_email_gets_an_honest_fallback_message(): void
    {
        Mail::fake();

        $existing = User::create([
            'name' => 'Client Comptoir Sans Email',
            'username' => 'client-comptoir-sanse-2',
            'email' => null,
            'phone' => '0699555099',
            'country_code' => '33',
            'branch_id' => 0,
            'is_guest' => Ask::YES,
            'password' => bcrypt('whatever'),
        ]);
        $existing->loyalty_points = 250;
        $existing->save();

        $response = $this->emailLogin([
            'email' => 'nouveau.mail.reel@example.com',
            'first_name' => 'VraiClient',
            'phone' => '0699555099',
        ]);

        // La sécurité ne change pas : la réponse reste "réussie" en apparence (anti-énumération
        // — rien ne doit distinguer ce cas d'un envoi normal pour un appelant extérieur).
        $response->assertStatus(200)->assertJsonPath('sent', true);

        // ...MAIS aucun e-mail ne part réellement, et le client a maintenant un VRAI recours au
        // lieu d'attendre indéfiniment un code qui n'arrivera jamais.
        Mail::assertNothingSent();
        $response->assertJsonPath('message', trans('all.message.check_your_email_for_code_with_fallback'));
        // Preuve indépendante que le message n'est plus le message "silencieux" d'avant : le
        // recours (comptoir/counter) est bien un texte NOUVEAU, distinct de l'ancien.
        $this->assertNotSame(trans('all.message.check_your_email_for_code'), $response->json('message'));
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

    private function ouvrirPorteDeRevue(string $until): void
    {
        config(['auth.app_review.email' => 'revue@lecayenne.fr', 'auth.app_review.otp' => '2468', 'auth.app_review.until' => $until]);
    }

    /**
     * (7) SENTINELLE — compte de revue App Store : porte FERMÉE par défaut, et qui se referme seule.
     *
     * Historique : posée le 02/09, retirée le même jour (« une porte qu'on oublie de refermer »), puis
     * RÉ-OUVERTE sur décision du propriétaire le 2026-10-15 — Apple examine contre la production et ne
     * lit pas nos e-mails. La dérive redoutée est l'oubli : d'où la date de fin OBLIGATOIRE. Ces tests
     * verrouillent les garde-fous ; ils rougissent si l'un d'eux saute.
     */
    public function test_revue_porte_fermee_par_defaut(): void
    {
        Mail::fake();
        $this->guest(['email' => 'revue@lecayenne.fr', 'phone' => '0699555010', 'name' => 'Revue Apple', 'loyalty_points' => 0]);

        $this->emailLogin(['email' => 'revue@lecayenne.fr'])->assertStatus(200)->assertJsonPath('known', true);

        $this->assertNotSame('2468', $this->tokenFor('0699555010'));
        Mail::assertSent(SignupOtpMail::class, fn (SignupOtpMail $m) => $m->hasTo('revue@lecayenne.fr'));
    }

    public function test_revue_sans_date_de_fin_ou_expiree_reste_fermee(): void
    {
        Mail::fake();
        $this->guest(['email' => 'revue@lecayenne.fr', 'phone' => '0699555010', 'name' => 'Revue Apple', 'loyalty_points' => 0]);

        foreach (['', now()->subDay()->toDateString(), 'pas-une-date'] as $until) {
            $this->ouvrirPorteDeRevue($until);
            $this->emailLogin(['email' => 'revue@lecayenne.fr'])->assertStatus(200);
            $this->assertNotSame('2468', $this->tokenFor('0699555010'), "until='{$until}' doit laisser la porte fermée");
            $this->postJson('/api/auth/guest-signup/verify', ['email' => 'revue@lecayenne.fr', 'token' => '2468'])->assertStatus(422);
        }
    }

    public function test_revue_ouverte_code_fixe_pour_ce_seul_email_sans_envoi(): void
    {
        Mail::fake();
        $this->ouvrirPorteDeRevue(now()->addDays(14)->toDateString());
        $this->guest(['email' => 'revue@lecayenne.fr', 'phone' => '0699555010', 'name' => 'Revue Apple', 'loyalty_points' => 0]);

        $this->emailLogin(['email' => 'revue@lecayenne.fr'])->assertStatus(200)->assertJsonPath('known', true);
        $this->assertSame('2468', $this->tokenFor('0699555010'));
        Mail::assertNothingSent();

        $this->postJson('/api/auth/guest-signup/verify', ['email' => 'revue@lecayenne.fr', 'token' => '1111'])->assertStatus(422);
        $v = $this->postJson('/api/auth/guest-signup/verify', ['email' => 'revue@lecayenne.fr', 'token' => '2468']);
        $v->assertStatus(201);
        $this->assertNotEmpty($v->json('token'));
        $this->assertFalse((bool) $v->json('phone_required'), 'Le compte de revue a un téléphone : aucun écran bloquant.');
    }

    public function test_revue_ouverte_nautorise_aucun_autre_email_ni_staff_ni_code_a_6_chiffres(): void
    {
        Mail::fake();
        $this->ouvrirPorteDeRevue(now()->addDays(14)->toDateString());
        $this->guest(); // un vrai client : sarah.dupont@example.com

        $this->emailLogin(['email' => self::EMAIL])->assertStatus(200);
        $this->assertNotSame('2468', $this->tokenFor(self::PHONE), 'Un autre e-mail garde son code aléatoire.');
        Mail::assertSent(SignupOtpMail::class, fn (SignupOtpMail $m) => $m->hasTo(self::EMAIL));

        // Un compte non invité (staff) portant l'e-mail de revue reste « inconnu » : jamais de code fixe.
        User::withoutGlobalScopes()->where('email', self::EMAIL)->delete();
        $this->guest(['email' => 'revue@lecayenne.fr', 'phone' => '0699555011', 'is_guest' => Ask::NO]);
        $this->emailLogin(['email' => 'revue@lecayenne.fr'])->assertStatus(200)->assertJsonPath('known', false);

        // Un code mal formé (pas 4 chiffres) ne peut pas devenir un code fixe.
        config(['auth.app_review.otp' => '123456']);
        $this->guest(['email' => 'revue2@lecayenne.fr', 'phone' => '0699555012']);
        config(['auth.app_review.email' => 'revue2@lecayenne.fr']);
        $this->emailLogin(['email' => 'revue2@lecayenne.fr'])->assertStatus(200);
        $this->assertNotSame('123456', $this->tokenFor('0699555012'));
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
