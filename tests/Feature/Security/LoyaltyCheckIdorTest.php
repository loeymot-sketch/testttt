<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [ULTRA-AUDIT V3 2026-07-02 — P2 IDOR JUMEAU] /loyalty/check fuyait name+loyalty_code+points de
 * n'importe quel code/téléphone à TOUT token Sanctum (reverse phone→nom + énumération). Même classe
 * que /register et /scan colmatés en V2, mais /check oublié. Parité /redeem : borne/staff/owner only.
 */
class LoyaltyCheckIdorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function apiKey(): string
    {
        return config('app.api_key', env('MIX_API_KEY', 'test-api-key'));
    }

    /** @test */
    public function un_guest_ne_peut_pas_consulter_le_compte_fidelite_d_autrui(): void
    {
        $victim = User::factory()->create([
            'loyalty_code' => 'VICT9999',
            'loyalty_points' => 250,
            'name' => 'Victime Secrète',
            'phone' => '0611002200',
            'status' => 5,
        ]);
        // Attaquant : token guest (kiosk:order) mais AUCUNE KioskMachine, aucun rôle staff.
        $attacker = User::factory()->create(['status' => 5]);
        Sanctum::actingAs($attacker, ['kiosk:order']);

        // Par loyalty_code d'autrui → ne doit RIEN divulguer (404).
        $r1 = $this->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/frontend/loyalty/check', ['code' => 'VICT9999']);
        $r1->assertStatus(404);
        $this->assertStringNotContainsString('Victime Secrète', $r1->getContent());
        $this->assertStringNotContainsString('250', $r1->getContent());

        // Par téléphone d'autrui (reverse phone→nom) → 404 aussi.
        $r2 = $this->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/frontend/loyalty/check', ['code' => '0611002200']);
        $r2->assertStatus(404);
        $this->assertStringNotContainsString('Victime Secrète', $r2->getContent());
    }

    /** @test */
    public function le_proprietaire_peut_consulter_son_propre_compte(): void
    {
        $owner = User::factory()->create([
            'loyalty_code' => 'MINE1234',
            'loyalty_points' => 40,
            'name' => 'Moi Même',
            'status' => 5,
        ]);
        Sanctum::actingAs($owner, ['kiosk:order']);

        $res = $this->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/frontend/loyalty/check', ['code' => 'MINE1234']);

        $res->assertStatus(200);
        $res->assertJsonPath('data.loyalty_code', 'MINE1234');
    }
}
