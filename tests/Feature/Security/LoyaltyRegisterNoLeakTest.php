<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [SEC-LOYALTY-LEAK 2026-07-02] L'endpoint PUBLIC api/frontend/loyalty/register NE DOIT PAS
 * divulguer le loyalty_code + phone du titulaire d'un email (fuite PII par énumération d'emails).
 * Trouvé par l'ultra-review Fable (P2), reproduit et corrigé.
 */
class LoyaltyRegisterNoLeakTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function conflit_email_ne_divulgue_pas_le_code_ni_le_telephone_d_autrui(): void
    {
        // Un titulaire existant avec email + téléphone + code fidélité (la victime).
        $victim = User::factory()->create([
            'email' => 'victime@example.com',
            'phone' => '0611223344',
            'loyalty_code' => 'SECRET99',
        ]);

        // Un attaquant tente de s'inscrire avec l'email de la victime depuis un AUTRE téléphone.
        $res = $this->postJson('/api/frontend/loyalty/register', [
            'name'  => 'Attaquant',
            'phone' => '0700000000',
            'email' => 'victime@example.com',
        ], ['x-api-key' => config('app.api_key')]);

        $res->assertStatus(409);
        // Le message d'existence est OK ; les données d'autrui NE DOIVENT PAS fuiter.
        $body = $res->getContent();
        $this->assertStringNotContainsString('SECRET99', $body, 'le loyalty_code d autrui ne doit pas fuiter');
        $this->assertStringNotContainsString('0611223344', $body, 'le téléphone d autrui ne doit pas fuiter');
        $res->assertJsonMissingPath('data.existing_loyalty_code');
        $res->assertJsonMissingPath('data.existing_phone');
    }
}
