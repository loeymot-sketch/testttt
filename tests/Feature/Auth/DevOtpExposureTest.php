<?php

namespace Tests\Feature\Auth;

use App\Enums\Activity;
use App\Events\SendSmsCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * [W16 DEV-OTP 2026-07-20] Re-testabilité web staging après le P0 OTP-BYPASS.
 *
 * verify() exige désormais le VRAI code (lit otps.token) : en staging/local SANS
 * SMS câblé, l'owner ne peut plus le connaître → web plus testable. GuestSignupController::otp()
 * renvoie donc le code fraîchement généré dans `dev_code` UNIQUEMENT hors production
 * ET quand le SMS ne partira pas (site_phone_verification != ENABLE OU aucune gateway SMS).
 *
 * VERROU SÉCURITÉ testé explicitement : en production, `dev_code` est TOUJOURS absent,
 * même quand la condition « SMS non câblé » est vraie (la seule env-gate suffit). Doublé
 * en amont par le preflight go-live qui bloque déjà la prod si OTP OFF.
 */
class DevOtpExposureTest extends TestCase
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
            // Staging par défaut : OTP non câblé côté SMS (le web lit otps.token en table).
            ['key' => 'site_phone_verification', 'payload' => json_encode(Activity::DISABLE), 'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'site_default_branch',     'payload' => json_encode(1),                 'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'otp_expire_time',         'payload' => json_encode(5),                 'group' => 'otp',  'created_at' => now(), 'updated_at' => now()],
        ]);

        config(['app.api_key' => self::API_KEY]);
        $this->withHeaders([
            'x-api-key' => self::API_KEY,
            'Accept'    => 'application/json',
        ]);

        // Isole du réseau SMS : otp() dispatch toujours SendSmsCode après avoir créé la ligne otps.
        // On ne fake QUE cet event — Otp::create + tout le reste s'exécutent normalement.
        Event::fake([SendSmsCode::class]);
    }

    private function setSetting(string $key, string $group, int $value): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => $key, 'group' => $group],
            ['payload' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private function sendOtp(string $phone): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/guest-signup/otp', [
            'code'  => '+33',
            'phone' => $phone,
        ]);
    }

    /**
     * @test
     * HORS PROD + SMS non câblé (site_phone_verification=DISABLE) :
     * la réponse contient dev_code == otps.token (le code fraîchement généré).
     */
    public function dev_code_is_exposed_outside_production_when_sms_not_wired(): void
    {
        $phone = '0699444001';

        $res = $this->sendOtp($phone);

        $res->assertStatus(200)->assertJson(['status' => true]);

        $token = DB::table('otps')->where('phone', $phone)->latest('created_at')->value('token');
        $this->assertNotNull($token, 'Un OTP doit avoir été généré en base.');

        $res->assertJsonPath('dev_code', (string) $token);
    }

    /**
     * @test
     * SÉCURITÉ — EN PRODUCTION : dev_code TOUJOURS absent, MÊME avec la condition
     * « SMS non câblé » vraie (DISABLE). Seule l'env-gate suffit à le supprimer.
     */
    public function dev_code_is_absent_in_production_even_when_sms_not_wired(): void
    {
        $phone = '0699444002';

        // Force l'environnement applicatif sur production (app()->environment('production') === true).
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertTrue($this->app->environment('production'), 'Pré-condition : env doit être production.');

        $res = $this->sendOtp($phone);

        $res->assertStatus(200)->assertJson(['status' => true]);

        // L'OTP est bien généré (le flux marche) MAIS le code ne fuit jamais en prod.
        $token = DB::table('otps')->where('phone', $phone)->latest('created_at')->value('token');
        $this->assertNotNull($token, 'Le flux OTP fonctionne toujours en prod (ligne créée).');

        $res->assertJsonMissingPath('dev_code');
    }

    /**
     * @test
     * HORS PROD mais SMS PLEINEMENT câblé (phone_verification=ENABLE + gateway posée) :
     * dev_code absent — le vrai SMS partira, pas besoin de fuiter le code.
     */
    public function dev_code_is_absent_outside_production_when_sms_is_fully_wired(): void
    {
        $phone = '0699444003';

        $this->setSetting('site_phone_verification', 'site', Activity::ENABLE);
        $this->setSetting('site_default_sms_gateway', 'site', 1);

        $res = $this->sendOtp($phone);

        $res->assertStatus(200)->assertJson(['status' => true]);
        $res->assertJsonMissingPath('dev_code');
    }
}
