<?php

namespace Tests\Feature\KioskPhase1;

use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kiosk Design V1 — Phase 1.9 : /kiosk/event (slash) doit fonctionner comme
 * /kiosk-event (tiret) — alias master-prompt. Aucune régression sur tiret.
 */
class KioskEventAliasTest extends TestCase
{
    use RefreshDatabase;

    public function test_slash_alias_is_accepted(): void
    {
        [$user, $token] = $this->setupKiosk();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/frontend/kiosk/event', [
                'type' => 'order_abandoned',
            ]);

        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_legacy_dash_route_still_works(): void
    {
        [$user, $token] = $this->setupKiosk();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/frontend/kiosk-event', [
                'type' => 'order_abandoned',
            ]);

        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_alias_requires_auth(): void
    {
        $response = $this->postJson('/api/frontend/kiosk/event', ['type' => 'order_abandoned']);
        $response->assertStatus(401);
    }

    private function setupKiosk(): array
    {
        $this->seedMinimalSettings();

        $branch = Branch::updateOrCreate(['id' => 1], [
            'name' => 'HQ', 'email' => 'h@f.com', 'phone' => '1',
            'latitude' => '0', 'longitude' => '0', 'city' => 'Paris',
            'state' => 'IDF', 'zip_code' => '75', 'address' => '1 rue',
        ]);
        $user = User::factory()->create([
            'username' => 'kiosk_evt_'.uniqid(),
            'branch_id' => $branch->id,
        ]);
        KioskMachine::create([
            'machine_id' => 'evt-test', 'branch_id' => $branch->id,
            'user_id' => $user->id, 'username' => 'kiosk-evt',
            'password' => bcrypt('x'), 'is_login' => \App\Enums\Ask::NO,
            'status' => \App\Enums\Status::ACTIVE,
        ]);
        $token = $user->createToken('kiosk-token', ['kiosk:order'])->plainTextToken;
        return [$user, $token];
    }
}
