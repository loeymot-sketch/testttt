<?php

namespace Tests\Feature\Auth;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Mail\SignupOtpMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * [WAVE C EMAIL-OTP 2026-07-28 — GOAL_WEB_COMMANDE_CLIENT §5]
 * Le signup web était structurellement mort en prod : OtpManagerService::otp()
 * dispatch SendSmsCode SANS provider SMS (mandat owner : pas de SMS, coût).
 * Nouveau canal : POST /api/auth/guest-signup/email-otp {phone, email, code?}
 * → même ligne otps (phone=clé, token=code), code envoyé par EMAIL (Mailable
 * SignupOtpMail, sync, pas de queue) + liaison Cache email_otp_email:<phone>.
 * Verify INCHANGÉ (POST /api/auth/guest-signup/verify) : au succès, l'email
 * lié est persisté sur le User + email_verified_at posé. Le contrat cache
 * phone_verified:<phone> (consommé par SignupController::register:72) reste
 * intact — le flux SMS legacy n'est PAS modifié.
 */
class EmailOtpSignupTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key';
    private const PHONE = '0699555001';
    private const EMAIL = 'client.web@example.com';

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

    private function requestEmailOtp(string $phone = self::PHONE, string $email = self::EMAIL): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/guest-signup/email-otp', [
            'phone' => $phone,
            'email' => $email,
            'code'  => '+33',
        ]);
    }

    private function dbToken(string $phone = self::PHONE): ?string
    {
        $token = DB::table('otps')->where('phone', $phone)->latest('created_at')->value('token');

        return $token === null ? null : (string) $token;
    }

    private function verify(string $phone, string $token): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/guest-signup/verify', [
            'code'  => '+33',
            'phone' => $phone,
            'token' => $token,
        ]);
    }

    /** (1) email-otp envoie SignupOtpMail avec le code à la bonne adresse + crée la ligne otps. */
    public function test_email_otp_sends_mail_with_code_and_creates_otp_row(): void
    {
        Mail::fake();

        $response = $this->requestEmailOtp();
        $response->assertStatus(200)->assertJsonPath('status', true);

        $token = $this->dbToken();
        $this->assertNotNull($token, 'La ligne otps doit être créée (phone=clé, token=code).');

        Mail::assertSent(SignupOtpMail::class, function (SignupOtpMail $mail) use ($token) {
            return $mail->hasTo(self::EMAIL) && $mail->otpCode === $token;
        });
    }

    /** (2) verify avec le bon code → 201 + token Sanctum + email persisté + email_verified_at posé. */
    public function test_verify_with_correct_code_returns_token_and_persists_verified_email(): void
    {
        Mail::fake();

        $this->requestEmailOtp()->assertStatus(200);
        $token = $this->dbToken();
        $this->assertNotNull($token);

        $response = $this->verify(self::PHONE, $token);
        $response->assertStatus(201);
        $this->assertNotEmpty($response->json('token'), 'Le verify doit émettre un token Sanctum.');

        $user = User::withoutGlobalScopes()->where('phone', self::PHONE)->first();
        $this->assertNotNull($user);
        $this->assertSame(self::EMAIL, $user->email, "L'email vérifié doit être persisté sur le compte.");
        $this->assertNotNull($user->email_verified_at, 'email_verified_at doit être posé à la vérification email.');
    }

    /** [HEAL SIGNUP 2026-07-30] email persisté depuis la REQUÊTE verify quand le cache a
     *  expiré (client qui cherche dans ses spams > TTL, ou renvoi) — plus de « email non renseigné ». */
    public function test_verify_persists_email_from_request_when_cache_expired(): void
    {
        Mail::fake();
        $this->requestEmailOtp()->assertStatus(200);
        $token = $this->dbToken();
        $this->assertNotNull($token);

        // Simule l'expiration du cache email (TTL dépassé) : le SEUL email disponible est celui du verify.
        Cache::forget('email_otp_email:'.self::PHONE);

        $response = $this->postJson('/api/auth/guest-signup/verify', [
            'code' => '+33', 'phone' => self::PHONE, 'token' => $token,
            'email' => self::EMAIL, 'first_name' => 'Mourad',
        ]);
        $response->assertStatus(201);

        $user = User::withoutGlobalScopes()->where('phone', self::PHONE)->first();
        $this->assertNotNull($user);
        $this->assertSame(self::EMAIL, $user->email, "L'email doit être persisté depuis la requête verify (fallback cache expiré).");
        $this->assertNotNull($user->email_verified_at);
    }

    /** [HEAL SIGNUP 2026-07-30] le prénom saisi à l'inscription est enregistré (avant : « Guest User » figé). */
    public function test_verify_persists_first_name(): void
    {
        Mail::fake();
        $this->requestEmailOtp()->assertStatus(200);
        $token = $this->dbToken();

        $this->postJson('/api/auth/guest-signup/verify', [
            'code' => '+33', 'phone' => self::PHONE, 'token' => $token,
            'email' => self::EMAIL, 'first_name' => 'Mourad',
        ])->assertStatus(201);

        $user = User::withoutGlobalScopes()->where('phone', self::PHONE)->first();
        $this->assertSame('Mourad', $user->name, 'Le prénom fourni doit devenir le nom du compte, pas « Guest User ».');
    }

    /** (3a) code faux → 422, aucun compte créé, email non persisté. */
    public function test_verify_with_wrong_code_fails(): void
    {
        Mail::fake();

        $this->requestEmailOtp()->assertStatus(200);

        // token réel ∈ [1000, 9999] → '0000' est toujours faux.
        $this->verify(self::PHONE, '0000')->assertStatus(422);

        $this->assertSame(
            0,
            User::withoutGlobalScopes()->withTrashed()->where('phone', self::PHONE)->count(),
            'Un code faux ne doit créer aucun compte.'
        );
    }

    /** (3b) code expiré → 422. */
    public function test_verify_with_expired_code_fails(): void
    {
        Mail::fake();

        $this->requestEmailOtp()->assertStatus(200);
        $token = $this->dbToken();
        $this->assertNotNull($token);

        // Vieillit l'OTP au-delà de la fenêtre otp_expire_time (5 min).
        DB::table('otps')->where('phone', self::PHONE)->update([
            'created_at' => now()->subMinutes(10),
        ]);

        $this->verify(self::PHONE, $token)->assertStatus(422);
        $this->assertSame(
            0,
            User::withoutGlobalScopes()->withTrashed()->where('phone', self::PHONE)->count()
        );
    }

    /** (4) throttle 5/min : le 6e appel → 429. */
    public function test_email_otp_throttled_on_sixth_call(): void
    {
        Mail::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->requestEmailOtp()->assertStatus(200);
        }

        $this->requestEmailOtp()->assertStatus(429);
    }

    /** (5) contrat cache phone_verified:<phone> intact — SignupController::register consomme le marqueur. */
    public function test_phone_verified_cache_contract_register_upgrade_still_works(): void
    {
        Mail::fake();

        // Vérification téléphone ACTIVE → register exige le marqueur phone_verified.
        DB::table('settings')->updateOrInsert(
            ['key' => 'site_phone_verification', 'group' => 'site'],
            ['payload' => json_encode(Activity::ENABLE), 'updated_at' => now(), 'created_at' => now()]
        );

        $this->requestEmailOtp()->assertStatus(200);
        $token = $this->dbToken();
        $this->verify(self::PHONE, $token)->assertStatus(201);

        // OtpManagerService::verify a posé le marqueur — preuve positive de possession.
        $this->assertTrue((bool) Cache::get('phone_verified:'.self::PHONE));

        // Upgrade invité → compte plein via SignupController::register (pull du marqueur).
        $response = $this->postJson('/api/auth/signup/register', [
            'first_name'   => 'Client',
            'last_name'    => 'Web',
            'email'        => self::EMAIL,
            'phone'        => self::PHONE,
            'country_code' => '33',
            'password'     => 'secret123',
        ]);
        $response->assertStatus(201);

        $user = User::withoutGlobalScopes()->where('phone', self::PHONE)->first();
        $this->assertSame((string) Ask::NO, (string) $user->is_guest, 'Le compte invité doit être promu compte plein.');
    }

    /** (6) un email déjà porté par un AUTRE compte n'est jamais écrasé/attaché (pas de leak, pas de vol). */
    public function test_email_belonging_to_another_account_is_not_attached(): void
    {
        Mail::fake();

        User::create([
            'name'      => 'Autre Client',
            'username'  => 'autre-client',
            'email'     => self::EMAIL,
            'phone'     => '0699555099',
            'branch_id' => 0,
            'is_guest'  => Ask::NO,
            'password'  => bcrypt('secret123'),
        ]);

        // La demande ne révèle PAS que l'email existe (réponse identique).
        $this->requestEmailOtp()->assertStatus(200)->assertJsonPath('status', true);

        $token = $this->dbToken();
        $this->verify(self::PHONE, $token)->assertStatus(201);

        $user = User::withoutGlobalScopes()->where('phone', self::PHONE)->first();
        $this->assertNotNull($user);
        $this->assertNotSame(self::EMAIL, $user->email, "L'email d'un autre compte ne doit pas être attaché.");
    }

    /** (7) email invalide → 422 validation. */
    public function test_invalid_email_rejected(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/guest-signup/email-otp', [
            'phone' => self::PHONE,
            'email' => 'pas-un-email',
            'code'  => '+33',
        ])->assertStatus(422);

        Mail::assertNothingSent();
    }

    /**
     * (8) guest login désactivé (site_guest_login=DISABLE) → l'endpoint de
     * demande de code REFUSE (403) : sinon on peut déclencher des envois
     * d'emails OTP (spam / coût SMTP) alors que register() les refusera de
     * toute façon. Même toggle que GuestSignupController::register:168.
     */
    public function test_email_otp_refused_when_guest_login_disabled(): void
    {
        Mail::fake();

        DB::table('settings')->insert([
            'key'        => 'site_guest_login',
            'payload'    => json_encode(Activity::DISABLE),
            'group'      => 'site',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->requestEmailOtp()->assertStatus(403);

        $this->assertNull($this->dbToken(), 'Aucun code OTP ne doit être créé quand le guest login est désactivé.');
        Mail::assertNothingSent();
    }

    /**
     * (9) SÉCURITÉ [CHANNEL-CONFUSION 2026-07-30] — Si le téléphone est DÉJÀ rattaché à un
     * compte invité PORTANT un email, la demande de code (même avec un AUTRE email fourni par
     * l'appelant) envoie le code à l'email DU COMPTE, jamais à celui fourni. Sinon un attaquant
     * connaissant le numéro d'une victime se fait livrer le code sur SON email → verify →
     * loginUsingId(victime) = prise de contrôle du compte + points fidélité + PII.
     */
    public function test_channel_confusion_otp_goes_to_bound_account_email_not_attacker(): void
    {
        Mail::fake();

        // Victime : compte invité existant avec email lié.
        User::create([
            'name'      => 'Victime',
            'username'  => 'victime-guest',
            'email'     => 'victime@example.com',
            'phone'     => self::PHONE,
            'branch_id' => 0,
            'is_guest'  => Ask::YES,
            'password'  => bcrypt('secret123'),
        ]);

        // Attaquant : demande le code pour le numéro de la victime, avec SON PROPRE email.
        // Réponse identique (anti-énumération) — l'attaquant ne sait pas où part le code.
        $this->postJson('/api/auth/guest-signup/email-otp', [
            'phone' => self::PHONE,
            'email' => 'attaquant@evil.com',
            'code'  => '+33',
        ])->assertStatus(200)->assertJsonPath('status', true);

        $token = $this->dbToken();
        $this->assertNotNull($token, 'Un OTP est bien généré (le flux fonctionne).');

        // Le code part vers l'email DU COMPTE VICTIME, JAMAIS vers celui de l'attaquant.
        Mail::assertSent(SignupOtpMail::class, fn (SignupOtpMail $mail) => $mail->hasTo('victime@example.com'));
        Mail::assertNotSent(SignupOtpMail::class, fn (SignupOtpMail $mail) => $mail->hasTo('attaquant@evil.com'));
    }
}
