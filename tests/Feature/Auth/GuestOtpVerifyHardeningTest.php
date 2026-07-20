<?php

namespace Tests\Feature\Auth;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [P0 OTP-BYPASS 2026-07-20] GuestSignupController::verify() acceptait
 * N'IMPORTE quel code (même sans OTP jamais demandé) dès que
 * site_phone_verification == Activity::DISABLE : la branche DISABLE supprimait
 * les otps du téléphone puis enchaînait register() SANS vérifier le code →
 * mint d'un token Sanctum kiosk:order pour tout numéro = bypass d'auth.
 *
 * Sémantique verrouillée ici : site_phone_verification ne pilote QUE l'envoi
 * SMS — JAMAIS la vérification. verify() passe TOUJOURS par
 * OtpManagerService::verify() (lit otps.token, expiry, anti-brute-force
 * GAP-20, consommation one-time). Un code faux ou un numéro jamais-demandé
 * → 422, aucun user, aucun token. Compatible « OTP lu en table » : staging/e2e
 * lisent otps.token et le soumettent → verify OK, sans SMS.
 */
class GuestOtpVerifyHardeningTest extends TestCase
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

        DB::table('settings')->insert([
            ['key' => 'site_guest_login',        'payload' => json_encode(Activity::ENABLE),  'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'site_phone_verification', 'payload' => json_encode(Activity::DISABLE), 'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'site_default_branch',     'payload' => json_encode(1),                 'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            // Fenêtre d'expiration déterministe pour OtpManagerService::verify().
            ['key' => 'otp_expire_time',         'payload' => json_encode(5),                 'group' => 'otp',  'created_at' => now(), 'updated_at' => now()],
        ]);

        config(['app.api_key' => self::API_KEY]);
        $this->withHeaders([
            'x-api-key' => self::API_KEY,
            'Accept'    => 'application/json',
        ]);
    }

    private function setPhoneVerification(int $mode): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'site_phone_verification', 'group' => 'site'],
            ['payload' => json_encode($mode), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /** Simule un OTP demandé : ligne otps posée comme le fait OtpManagerService::otp(). */
    private function insertOtp(string $phone, string $token, ?\Carbon\Carbon $createdAt = null): void
    {
        DB::table('otps')->insert([
            'phone'      => $phone,
            'code'       => '+33',
            'token'      => $token,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    private function verify(string $phone, string $token): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/guest-signup/verify', [
            'code'  => '+33',
            'phone' => $phone,
            'token' => $token,
        ]);
    }

    private function assertNoUserNoToken(string $phone): void
    {
        $this->assertSame(
            0,
            User::withoutGlobalScopes()->withTrashed()->where('phone', $phone)->count(),
            'Aucun user ne doit être créé quand le code OTP est refusé.'
        );
        $this->assertSame(
            0,
            (int) DB::table('personal_access_tokens')->count(),
            'Aucun token Sanctum ne doit être minté quand le code OTP est refusé.'
        );
    }

    /**
     * @test
     * (a) LE BYPASS : DISABLE + code FAUX (un OTP existe mais ne correspond pas)
     * → 422, aucun user, aucun token. Avant fix : 201 + token (blind-accept).
     */
    public function disable_mode_wrong_code_is_rejected_without_user_or_token(): void
    {
        $phone = '0699333001';
        $this->insertOtp($phone, '445566');

        $res = $this->verify($phone, '999999');

        $res->assertStatus(422);
        $this->assertNoUserNoToken($phone);
    }

    /**
     * @test
     * (b) DISABLE + numéro pour lequel AUCUN OTP n'a jamais été demandé
     * → 422, aucun user, aucun token. Avant fix : 201 + token.
     */
    public function disable_mode_phone_without_any_requested_otp_is_rejected(): void
    {
        $phone = '0699333002';

        $res = $this->verify($phone, '123456');

        $res->assertStatus(422);
        $this->assertNoUserNoToken($phone);
    }

    /**
     * @test
     * (c) DISABLE + BON code (otps.token lu en table — pattern staging sans SMS)
     * → 201, user invité créé, token kiosk:order minté, OTP consommé one-time.
     */
    public function disable_mode_correct_code_from_table_still_logs_in(): void
    {
        $phone = '0699333003';
        $this->insertOtp($phone, '445566');

        $res = $this->verify($phone, '445566');

        $res->assertStatus(201)->assertJsonStructure(['token']);

        $user = User::withoutGlobalScopes()->where('phone', $phone)->first();
        $this->assertNotNull($user, 'Le bon code doit créer le compte invité.');
        $this->assertSame(Ask::YES, (int) $user->is_guest);
        $this->assertSame(
            1,
            (int) $user->tokens()->count(),
            'Le bon code doit minter exactement 1 token.'
        );
        $this->assertSame(
            0,
            (int) DB::table('otps')->where('phone', $phone)->count(),
            'L\'OTP doit être consommé (one-time) après un verify réussi.'
        );
    }

    /**
     * @test
     * (d1) ENABLE + bon code : comportement inchangé (201 + token).
     */
    public function enable_mode_correct_code_still_logs_in(): void
    {
        $phone = '0699333004';
        $this->setPhoneVerification(Activity::ENABLE);
        $this->insertOtp($phone, '112233');

        $res = $this->verify($phone, '112233');

        $res->assertStatus(201)->assertJsonStructure(['token']);
        $this->assertNotNull(User::withoutGlobalScopes()->where('phone', $phone)->first());
    }

    /**
     * @test
     * (d2) ENABLE + code faux : comportement inchangé (422, rien de créé).
     */
    public function enable_mode_wrong_code_still_rejected(): void
    {
        $phone = '0699333005';
        $this->setPhoneVerification(Activity::ENABLE);
        $this->insertOtp($phone, '112233');

        $res = $this->verify($phone, '999999');

        $res->assertStatus(422);
        $this->assertNoUserNoToken($phone);
    }

    /**
     * @test
     * (e) DISABLE + bon code mais EXPIRÉ (créé il y a 10 min, fenêtre 5 min)
     * → 422, aucun user, aucun token (l'expiry d'OtpManagerService s'applique).
     */
    public function disable_mode_expired_code_is_rejected(): void
    {
        $phone = '0699333006';
        $this->insertOtp($phone, '445566', now()->subMinutes(10));

        $res = $this->verify($phone, '445566');

        $res->assertStatus(422);
        $this->assertNoUserNoToken($phone);
    }
}
