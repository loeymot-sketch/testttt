<?php

namespace Tests\Feature\Security;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [V1 SYNC_ROBUSTNESS 3.4] /api/broadcasting/auth was unthrottled, opening
 * a token-bruteforce / replay-amplification surface (an attacker with a
 * leaked Sanctum token could enumerate channel subscriptions or DOS the
 * broadcaster). We add a throttle limiter at 20/min keyed by user/IP.
 */
class BroadcastAuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_broadcasting_auth_blocks_after_20_per_minute(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        $payload = [
            'channel_name' => 'private-branch.'.$branch->id,
            'socket_id' => '1234.5678',
        ];

        $lastResponse = null;

        // 20 hits should be allowed (whatever their auth result), 21st must be 429.
        for ($i = 0; $i < 21; $i++) {
            $lastResponse = $this->actingAs($user, 'sanctum')
                ->postJson('/api/broadcasting/auth', $payload);
        }

        $this->assertEquals(429, $lastResponse->status());
    }
}
