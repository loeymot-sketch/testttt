<?php

namespace Tests\Feature\Security;

use App\Http\Requests\VerifyPhoneRequest;
use App\Services\OtpManagerService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [NUIT 2026-07-03 — P2 anti-brute-force OTP] Verrou par identité (téléphone) : au-delà de N échecs, le
 * code est brûlé et une nouvelle demande est requise. Le succès légitime au 1er essai n'est jamais bloqué.
 */
class OtpBruteForceLockoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        Cache::flush();
    }

    private function seedOtp(string $phone, string $token): void
    {
        DB::table('otps')->insert([
            'phone' => $phone,
            'code' => '33',
            'token' => $token,
            'created_at' => now(),
        ]);
    }

    private function verifyRequest(string $phone, string $token): VerifyPhoneRequest
    {
        return VerifyPhoneRequest::create('/verify', 'POST', ['phone' => $phone, 'token' => $token]);
    }

    /** @test */
    public function un_code_correct_reussit_au_premier_essai(): void
    {
        $this->seedOtp('0600000001', '1234');
        $this->assertTrue(app(OtpManagerService::class)->verify($this->verifyRequest('0600000001', '1234')));
        $this->assertDatabaseMissing('otps', ['phone' => '0600000001']); // consommé au succès
    }

    /** @test */
    public function le_brute_force_brule_le_code_apres_le_max_de_tentatives(): void
    {
        $phone = '0600000002';
        $this->seedOtp($phone, '1234');
        $svc = app(OtpManagerService::class);

        // 5 tentatives ÉCHOUÉES (mauvais code) → chacune lève "invalide".
        for ($i = 0; $i < 5; $i++) {
            try {
                $svc->verify($this->verifyRequest($phone, '0000'));
                $this->fail('un mauvais code doit lever une exception');
            } catch (Exception $e) {
                // attendu
            }
        }

        // Au-delà du max, même le BON code échoue (l'OTP a été brûlé) + la ligne est supprimée.
        try {
            $svc->verify($this->verifyRequest($phone, '1234'));
            $this->fail('après le max de tentatives, même le bon code doit échouer (code brûlé)');
        } catch (Exception $e) {
            // attendu
        }
        $this->assertDatabaseMissing('otps', ['phone' => $phone]);
    }

    /** @test */
    public function un_bon_code_dans_la_limite_reste_accepte(): void
    {
        $phone = '0600000003';
        $this->seedOtp($phone, '1234');
        $svc = app(OtpManagerService::class);

        // 2 mauvaises tentatives (sous le max de 5) puis le bon code → DOIT réussir.
        for ($i = 0; $i < 2; $i++) {
            try { $svc->verify($this->verifyRequest($phone, '0000')); } catch (Exception $e) {}
        }
        $this->assertTrue($svc->verify($this->verifyRequest($phone, '1234')));
    }
}
