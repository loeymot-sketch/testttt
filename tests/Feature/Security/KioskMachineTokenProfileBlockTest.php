<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [TERRAIN-HEAL 2026-07-16 · KIOSK-PROFILE-ESCALATION — P1 regression lock]
 *
 * Un token de MACHINE borne (name='kiosk-token', émis par KioskMachineLoginController sur le user
 * support auquel la borne est rattachée) NE DOIT PAS accéder à /api/profile/* — sinon une borne
 * (creds semés kiosk123) peut lire/modifier l'email du staff/admin lié = takeover de compte.
 *
 * Le middleware block_kiosk_machine_profile distingue par NOM de token :
 *   - 'kiosk-token' (machine)  → 403
 *   - 'auth_token'  (client web/app, MÊME ability kiosk:order) → autorisé (gère SON profil)
 *
 * Ces tests verrouillent les deux côtés du contrat.
 */
class KioskMachineTokenProfileBlockTest extends TestCase
{
    use RefreshDatabase;

    private function apiHeaders(): array
    {
        return ['x-api-key' => config('app.api_key'), 'Accept' => 'application/json'];
    }

    public function test_kiosk_machine_token_is_blocked_from_profile_read(): void
    {
        $support = User::factory()->create();
        $machineToken = $support->createToken('kiosk-token', ['kiosk:order'])->plainTextToken;

        $this->withHeaders($this->apiHeaders() + ['Authorization' => "Bearer {$machineToken}"])
            ->getJson('/api/profile/')
            ->assertStatus(403)
            ->assertJsonPath('code', 'KIOSK_MACHINE_TOKEN_FORBIDDEN_ON_PROFILE');
    }

    public function test_kiosk_machine_token_is_blocked_from_profile_update(): void
    {
        $support = User::factory()->create(['email' => 'support@lecayenne.fr']);
        $machineToken = $support->createToken('kiosk-token', ['kiosk:order'])->plainTextToken;

        $this->withHeaders($this->apiHeaders() + ['Authorization' => "Bearer {$machineToken}"])
            ->putJson('/api/profile/', ['email' => 'attacker@evil.com', 'name' => 'x'])
            ->assertStatus(403);

        // L'email du user support NE DOIT PAS avoir changé.
        $this->assertSame('support@lecayenne.fr', $support->fresh()->email);
    }

    public function test_customer_auth_token_still_reaches_profile(): void
    {
        // Même ability kiosk:order, mais name='auth_token' (client web/app) → NON bloqué.
        $customer = User::factory()->create();
        $customerToken = $customer->createToken('auth_token', ['kiosk:order'])->plainTextToken;

        $this->withHeaders($this->apiHeaders() + ['Authorization' => "Bearer {$customerToken}"])
            ->getJson('/api/profile/')
            ->assertStatus(200);
    }
}
