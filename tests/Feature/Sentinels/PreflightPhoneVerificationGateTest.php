<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [P0 OTP-BYPASS 2026-07-20] Gate go-live : `app:preflight-production` doit
 * refuser un lancement PRODUCTION avec site_phone_verification != ENABLE.
 * Historiquement DISABLE faisait blind-accepter n'importe quel code par
 * GuestSignupController::verify() (bypass d'auth). Le controller est durci,
 * mais un go-live prod sans OTP SMS réel reste interdit : le preflight le
 * bloque en CRITICAL. Hors production (staging lit otps.token en table),
 * simple WARNING.
 */
class PreflightPhoneVerificationGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
    }

    private function setPhoneVerification(?int $mode): void
    {
        if ($mode === null) {
            DB::table('settings')->where('key', 'site_phone_verification')->delete();

            return;
        }

        DB::table('settings')->updateOrInsert(
            ['key' => 'site_phone_verification', 'group' => 'site'],
            ['payload' => json_encode($mode), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /** @test — prod + DISABLE → finding CRITICAL présent (bloque le go-live). */
    public function production_with_phone_verification_disabled_is_critical(): void
    {
        Config::set('app.env', 'production');
        $this->setPhoneVerification(Activity::DISABLE);

        // 'auth bypass surface' vit sur la ligne 'PHONE_VERIFICATION:' rendue par
        // addFinding — une seule assertion suffit (et évite le quirk PendingCommand
        // sur deux expectsOutputToContain positifs chaînés).
        $this->artisan('app:preflight-production')
            ->expectsOutputToContain('PHONE_VERIFICATION: site_phone_verification is NOT ENABLE');
    }

    /** @test — prod + réglage ABSENT → fail-closed : CRITICAL aussi. */
    public function production_with_missing_phone_verification_is_critical(): void
    {
        Config::set('app.env', 'production');
        $this->setPhoneVerification(null);

        $this->artisan('app:preflight-production')
            ->expectsOutputToContain('auth bypass surface');
    }

    /** @test — prod + ENABLE → check OK, aucun finding auth-bypass. */
    public function production_with_phone_verification_enabled_passes(): void
    {
        Config::set('app.env', 'production');
        $this->setPhoneVerification(Activity::ENABLE);

        $this->artisan('app:preflight-production')
            ->expectsOutputToContain('guest OTP enforced')
            ->doesntExpectOutputToContain('auth bypass surface');
    }

    /** @test — hors production (testing) + DISABLE → WARNING seulement, pas de CRITICAL. */
    public function non_production_with_disabled_phone_verification_warns_only(): void
    {
        $this->setPhoneVerification(Activity::DISABLE);

        $this->artisan('app:preflight-production')
            ->expectsOutputToContain('MUST be ENABLE in production')
            ->doesntExpectOutputToContain('auth bypass surface');
    }
}
