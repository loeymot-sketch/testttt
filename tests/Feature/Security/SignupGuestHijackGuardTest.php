<?php

namespace Tests\Feature\Security;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [SELF-AUDIT R5 P1 SÉCURITÉ 2026-07-05] /api/auth/signup/register pouvait DÉTOURNER un compte invité
 * existant par simple correspondance de téléphone : (1) portillon OTP inversé (`if (!$otp->exists())` →
 * autorisait quand aucun OTP n'existait, l'attaquant ne vérifiant JAMAIS passait), (2) écrasement de
 * email/mot de passe/is_guest d'un compte invité sans aucune preuve de possession → vol fidélité +
 * historique + verrouillage de la victime. Ce test verrouille : impossible d'écraser un compte sans
 * preuve de vérification (marqueur one-time posé par verify()).
 */
class SignupGuestHijackGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function setPhoneVerification(int $mode): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'site_phone_verification', 'group' => 'site'],
            ['payload' => json_encode($mode), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private function guestVictim(string $phone): User
    {
        return User::factory()->create([
            'phone' => $phone,
            'is_guest' => Ask::YES,
            'email' => 'victim-guest@local.test',
            'password' => Hash::make('victim-secret-original'),
        ]);
    }

    private function attackerPayload(string $phone): array
    {
        return [
            'first_name' => 'Mallory',
            'last_name' => 'Attacker',
            'email' => 'attacker@evil.com',
            'phone' => $phone,
            'country_code' => '33',
            'password' => 'attacker-password-123456',
        ];
    }

    /** @test — vérification DÉSACTIVÉE : le merge dans un compte invité est REFUSÉ (pas d'écrasement). */
    public function hijack_blocked_when_verification_disabled(): void
    {
        $this->setPhoneVerification(Activity::DISABLE);
        $victim = $this->guestVictim('0612349001');
        $origPassword = $victim->password;

        $res = $this->postJson('/api/auth/signup/register', $this->attackerPayload('0612349001'));

        $res->assertStatus(422);
        $victim->refresh();
        $this->assertSame(Ask::YES, (int) $victim->is_guest, 'Compte invité NON promu par l\'attaquant.');
        $this->assertSame($origPassword, $victim->password, 'Mot de passe de la victime INTACT.');
        $this->assertNotSame('attacker@evil.com', $victim->email, 'Email de la victime INTACT.');
    }

    /** @test — vérification ACTIVÉE + aucun OTP prouvé : refus (portillon corrigé, plus d'inversion). */
    public function hijack_blocked_when_verification_enabled_and_not_verified(): void
    {
        $this->setPhoneVerification(Activity::ENABLE);
        $victim = $this->guestVictim('0612349002');
        $origPassword = $victim->password;

        $res = $this->postJson('/api/auth/signup/register', $this->attackerPayload('0612349002'));

        $res->assertStatus(422);
        $victim->refresh();
        $this->assertSame(Ask::YES, (int) $victim->is_guest);
        $this->assertSame($origPassword, $victim->password);
    }

    /** @test — claim LÉGITIME : téléphone prouvé (marqueur one-time) → l'invité est promu. */
    public function verified_guest_claim_succeeds(): void
    {
        $this->setPhoneVerification(Activity::ENABLE);
        $guest = $this->guestVictim('0612349003');
        // Simule un verify() OTP réussi : marqueur one-time.
        Cache::put('phone_verified:0612349003', true, now()->addMinutes(5));

        $res = $this->postJson('/api/auth/signup/register', [
            'first_name' => 'Real',
            'last_name' => 'Owner',
            'email' => 'real-owner@local.test',
            'phone' => '0612349003',
            'country_code' => '33',
            'password' => 'owner-password-123456',
        ]);

        $res->assertStatus(201);
        $guest->refresh();
        $this->assertSame(Ask::NO, (int) $guest->is_guest, 'Un claim VÉRIFIÉ promeut l\'invité en compte plein.');
    }

    /** @test — inscription NEUVE (aucun compte existant) reste possible (vérif désactivée). */
    public function new_signup_still_works_when_verification_disabled(): void
    {
        $this->setPhoneVerification(Activity::DISABLE);

        $res = $this->postJson('/api/auth/signup/register', [
            'first_name' => 'New',
            'last_name' => 'Customer',
            'email' => 'new-customer@local.test',
            'phone' => '0612349004',
            'country_code' => '33',
            'password' => 'new-password-123456',
        ]);

        $res->assertStatus(201);
        $this->assertNotNull(User::where('phone', '0612349004')->where('is_guest', Ask::NO)->first());
    }
}
