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

    /** @test */
    public function lookup_par_telephone_seul_ne_divulgue_pas_les_pii_d_un_compte_existant(): void
    {
        // Vecteur PRINCIPAL : phone est requis, email optionnel. Un compte existant trouvé par
        // téléphone ne doit PAS renvoyer name/loyalty_code/points.
        $victim = User::factory()->create([
            'phone' => '0699887766',
            'loyalty_code' => 'VICTIM77',
            'loyalty_points' => 250,
            'name' => 'Victime Test',
        ]);

        $res = $this->postJson('/api/frontend/loyalty/register', [
            'name'  => 'Peu importe',
            'phone' => '0699887766',
        ], ['x-api-key' => config('app.api_key')]);

        $body = $res->getContent();
        $this->assertStringNotContainsString('VICTIM77', $body, 'loyalty_code d autrui ne doit pas fuiter (phone)');
        $this->assertStringNotContainsString('250', $body, 'points d autrui ne doivent pas fuiter (phone)');
        $res->assertJsonMissingPath('data.loyalty_code');
        $res->assertJsonMissingPath('data.points');
    }

    /** @test */
    public function nouveau_compte_recoit_bien_son_code(): void
    {
        // Le flux légitime (NOUVEAU client) doit continuer à recevoir son propre code.
        $res = $this->postJson('/api/frontend/loyalty/register', [
            'name'  => 'Nouveau Client',
            'phone' => '0755443322',
        ], ['x-api-key' => config('app.api_key')]);

        $res->assertOk();
        $res->assertJsonPath('data.name', 'Nouveau Client');
        $this->assertNotEmpty($res->json('data.loyalty_code'), 'un nouveau compte doit recevoir son code');
    }
}
