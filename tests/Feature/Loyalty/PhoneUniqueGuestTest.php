<?php

namespace Tests\Feature\Loyalty;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [W6 H1 — UNICITÉ TÉLÉPHONE app-level (audit fidélité)]
 *
 * `users.phone` n'a PAS de contrainte UNIQUE en DB (une contrainte casserait les
 * données e2e existantes) → la garantie « 1 téléphone = 1 compte client » repose
 * sur l'app-level. Ce test VERROUILLE le comportement déjà présent dans le flux
 * OTP guest web (GuestSignupController::register, lignes 98-127) :
 *
 *   - lookup AVANT création : `User::withoutGlobalScopes()->withTrashed()
 *     ->where('phone', ...)->first()` ([GAP-32-3] — couvre soft-deleted +
 *     cross-branch) ;
 *   - création d'un nouveau user UNIQUEMENT si aucun compte n'existe (`!$user`) ;
 *   - compte guest soft-deleted → restore (pas de doublon).
 *
 * Idem côté /api/frontend/loyalty/register (LoyaltyController:143-190 : where
 * phone first, create seulement si absent). Ce test prouve le point d'entrée
 * OTP web — le chemin qui émet le token de session invité.
 */
class PhoneUniqueGuestTest extends TestCase
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
        // site_phone_verification=DISABLE → verify() enchaîne direct sur register()
        // (pas de vrai SMS) : exactement le chemin de création de compte à prouver.
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

    private function verifyOtp(string $phone): \Illuminate\Testing\TestResponse
    {
        // [P0 OTP-BYPASS 2026-07-20] verify() exige un OTP réel non-expiré (consommé
        // one-time). On (re)pose la ligne otps à chaque appel — pattern « OTP lu en
        // table » sans SMS : le 2e login re-sème son propre code.
        DB::table('otps')->insert([
            'phone'      => $phone,
            'code'       => '+33',
            'token'      => 'test-token',
            'created_at' => now(),
        ]);

        return $this->postJson('/api/auth/guest-signup/verify', [
            'code'  => '+33',
            'phone' => $phone,
            'token' => 'test-token',
        ]);
    }

    private function countUsersWithPhone(string $phone): int
    {
        // withoutGlobalScopes + withTrashed : compter TOUS les comptes, y compris
        // hors-branche / soft-deleted — un doublon caché par un scope reste un doublon.
        return User::withoutGlobalScopes()->withTrashed()->where('phone', $phone)->count();
    }

    /**
     * @test
     * Cœur H1 : 2 vérifications OTP successives avec le MÊME numéro
     * → 1 SEUL user en DB (le 2e login réutilise le compte existant).
     */
    public function two_otp_verifications_same_phone_create_only_one_user(): void
    {
        $phone = '0699111001';

        $first = $this->verifyOtp($phone);
        $first->assertStatus(201)->assertJsonStructure(['token']);
        $this->assertSame(1, $this->countUsersWithPhone($phone), 'La 1re vérification crée exactement 1 compte.');
        $firstId = User::withoutGlobalScopes()->where('phone', $phone)->first()->id;

        $second = $this->verifyOtp($phone);
        $second->assertStatus(201)->assertJsonStructure(['token']);

        $this->assertSame(
            1,
            $this->countUsersWithPhone($phone),
            'Un même numéro ne doit JAMAIS créer un 2e user — le compte existant est réutilisé.'
        );
        $this->assertSame(
            $firstId,
            User::withoutGlobalScopes()->where('phone', $phone)->first()->id,
            'Le 2e login renvoie le MÊME user (même id), pas un compte neuf.'
        );
    }

    /**
     * @test
     * Contre-épreuve : 2 numéros DIFFÉRENTS → 2 users distincts
     * (la déduplication ne sur-fusionne pas des clients différents).
     */
    public function two_different_phones_create_two_distinct_users(): void
    {
        $phoneA = '0699111002';
        $phoneB = '0699111003';

        $this->verifyOtp($phoneA)->assertStatus(201);
        $this->verifyOtp($phoneB)->assertStatus(201);

        $this->assertSame(1, $this->countUsersWithPhone($phoneA));
        $this->assertSame(1, $this->countUsersWithPhone($phoneB));

        $a = User::withoutGlobalScopes()->where('phone', $phoneA)->first();
        $b = User::withoutGlobalScopes()->where('phone', $phoneB)->first();
        $this->assertNotSame($a->id, $b->id, '2 numéros différents = 2 comptes distincts.');
        $this->assertSame(Ask::YES, (int) $a->is_guest);
        $this->assertSame(Ask::YES, (int) $b->is_guest);
    }
}
