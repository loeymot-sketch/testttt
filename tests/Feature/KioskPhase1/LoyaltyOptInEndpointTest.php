<?php

namespace Tests\Feature\KioskPhase1;

use App\Models\LoyaltyConsent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kiosk Design V1 — Phase 1.8 : POST /api/frontend/loyalty/opt-in.
 * Consentement RGPD explicite obligatoire + log IP/UA hashés.
 */
class LoyaltyOptInEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_opt_in_creates_user_and_consent_log(): void
    {
        $this->seedMinimalSettings();

        $response = $this->postJson('/api/frontend/loyalty/opt-in', [
            'phone' => '+33612345678',
            'name'  => 'Alice Tester',
            'consent_accepted'       => true,
            'privacy_notice_version' => '2026-04-01',
        ]);

        $response->assertStatus(200)->assertJson(['status' => true]);

        $user = User::where('phone', '+33612345678')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->loyalty_code);

        $consent = LoyaltyConsent::where('user_id', $user->id)->first();
        $this->assertNotNull($consent, 'Un log RGPD doit avoir été créé.');
        $this->assertTrue($consent->consent_accepted);
        $this->assertSame('2026-04-01', $consent->privacy_notice_version);
        $this->assertSame(64, strlen($consent->ip_hash));
        $this->assertSame(64, strlen($consent->user_agent_hash));
    }

    public function test_opt_in_rejects_without_consent(): void
    {
        $this->seedMinimalSettings();

        $response = $this->postJson('/api/frontend/loyalty/opt-in', [
            'phone' => '+33612345678',
            'privacy_notice_version' => '2026-04-01',
            // consent_accepted absent
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, LoyaltyConsent::count(), 'Aucun consentement loggué si refus.');
    }

    public function test_opt_in_rejects_consent_false(): void
    {
        $this->seedMinimalSettings();

        $response = $this->postJson('/api/frontend/loyalty/opt-in', [
            'phone' => '+33612345678',
            'consent_accepted' => false,
            'privacy_notice_version' => '2026-04-01',
        ]);

        $response->assertStatus(422);
    }

    public function test_opt_in_rejects_without_privacy_notice_version(): void
    {
        $this->seedMinimalSettings();

        $response = $this->postJson('/api/frontend/loyalty/opt-in', [
            'phone' => '+33612345678',
            'consent_accepted' => true,
        ]);

        $response->assertStatus(422);
    }

    public function test_opt_in_requires_phone_or_email(): void
    {
        $this->seedMinimalSettings();

        $response = $this->postJson('/api/frontend/loyalty/opt-in', [
            'consent_accepted' => true,
            'privacy_notice_version' => '2026-04-01',
        ]);

        $response->assertStatus(422);
    }

    public function test_ip_is_stored_hashed_not_raw(): void
    {
        $this->seedMinimalSettings();

        $this->serverVariables['REMOTE_ADDR'] = '203.0.113.42';

        $this->postJson('/api/frontend/loyalty/opt-in', [
            'phone' => '+33612345000',
            'consent_accepted' => true,
            'privacy_notice_version' => '2026-04-01',
        ]);

        $consent = LoyaltyConsent::latest()->first();
        $this->assertNotNull($consent);
        $this->assertStringNotContainsString('203.0.113', $consent->ip_hash, 'IP brute interdite en DB.');
    }
}
