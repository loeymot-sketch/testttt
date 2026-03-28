<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\ActionLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * [C6] Tests for POST /api/frontend/kiosk-event
 * Verifies that kiosk machines can log structured events to ActionLog.
 */
class KioskEventTest extends TestCase
{
    use RefreshDatabase;

    private function setupKiosk(): array
    {
        $this->seedMinimalSettings();

        $branch = Branch::updateOrCreate(['id' => 1], [
            'name'      => 'HQ',
            'email'     => 'hq@foodking.com',
            'phone'     => '123',
            'latitude'  => '0',
            'longitude' => '0',
            'city'      => 'Paris',
            'state'     => 'IDF',
            'zip_code'  => '75',
            'address'   => '1 rue',
        ]);

        $user = User::factory()->create([
            'username'  => 'kiosk_evt_' . uniqid(),
            'branch_id' => $branch->id,
        ]);

        KioskMachine::create([
            'machine_id' => 'evt-test',
            'branch_id'  => $branch->id,
            'user_id'    => $user->id,
            'username'   => 'kiosk-evt',
            'password'   => bcrypt('123456'),
            'is_login'   => \App\Enums\Ask::NO,
            'status'     => \App\Enums\Status::ACTIVE,
        ]);

        // Create a kiosk token with kiosk:order ability
        $token = $user->createToken('kiosk-token', ['kiosk:order'])->plainTextToken;

        return [$user, $token];
    }

    public function test_kiosk_event_creates_action_log()
    {
        [$user, $token] = $this->setupKiosk();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/frontend/kiosk-event', [
                'type'  => 'order_abandoned',
                'count' => 2,
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => true]);

        $this->assertDatabaseHas('action_logs', [
            'user_id' => $user->id,
            'action'  => 'Kiosk event: order_abandoned',
            'resource' => 'Borne',
        ]);
    }

    public function test_kiosk_event_rejects_unknown_type()
    {
        [, $token] = $this->setupKiosk();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/frontend/kiosk-event', [
                'type' => 'unknown_event_type',
            ]);

        $response->assertStatus(422);
    }

    public function test_kiosk_event_requires_auth()
    {
        $response = $this->postJson('/api/frontend/kiosk-event', [
            'type' => 'order_abandoned',
        ]);

        $response->assertStatus(401);
    }

    public function test_kiosk_event_all_allowed_types_are_accepted()
    {
        [$user, $token] = $this->setupKiosk();

        $types = ['order_abandoned', 'sync_failed', 'auth_error', 'menu_cache_used', 'admin_action'];

        foreach ($types as $type) {
            $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
                ->postJson('/api/frontend/kiosk-event', ['type' => $type]);

            $response->assertStatus(200, "Type '{$type}' should be accepted");
        }
    }
}
