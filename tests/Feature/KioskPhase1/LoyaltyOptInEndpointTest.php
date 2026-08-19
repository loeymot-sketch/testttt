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

        /*
         * [SUPERVISION 2026-08-19] CE TEST CHERCHAIT LE NUMÉRO TEL QU'IL L'AVAIT ENVOYÉ.
         *
         * Depuis le 2026-08-19, l'inscription enregistre la FORME CANONIQUE du numéro
         * (« 0612345678 ») et non plus la forme saisie — décision documentée dans
         * `LoyaltyController::register` : réparer la lecture sans corriger l'écriture
         * reviendrait à semer indéfiniment des écritures divergentes. Le compte EST donc bien
         * créé ; c'est la recherche par égalité exacte de ce test qui ne le trouvait plus.
         *
         * Cause racine du défaut d'origine : « 06… » et « +33 6… » créaient DEUX comptes pour
         * la même personne — un cas réel mesuré avec 500 points d'un côté et 0 de l'autre.
         *
         * On cherche donc comme le code cherche : par toutes les écritures du même numéro.
         * Épingler une forme de stockage précise ici ferait de ce test un frein au jour où
         * la normalisation évoluera, alors que ce qu'il doit prouver est le CONSENTEMENT RGPD.
         */
        $tel = app(\App\Services\Identity\PhoneIdentity::class);
        $user = User::whereIn('phone', $tel->variants('+33612345678'))->first();
        $this->assertNotNull($user, 'Le compte doit exister, quelle que soit l’écriture stockée du numéro.');
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
