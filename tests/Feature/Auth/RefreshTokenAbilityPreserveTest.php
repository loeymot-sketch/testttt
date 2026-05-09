<?php

namespace Tests\Feature\Auth;

use App\Enums\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * [iter15-P0-07] Privilege-escalation regression suite for /refresh-token.
 *
 * Before the fix, RefreshTokenController unconditionally created the new
 * token with abilities=['*'] — a kiosk token (ability `kiosk:order` only)
 * could be exchanged for a wildcard admin-equivalent token. The tests here
 * lock in: original abilities are preserved verbatim, no implicit wildcard,
 * and invalid tokens return 401 (not a 500 leaked through the catch).
 */
class RefreshTokenAbilityPreserveTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key';

    protected function setUp(): void
    {
        parent::setUp();

        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $this->seedMinimalSettings();

        config(['app.api_key' => self::API_KEY]);

        // Ensure subsequent withHeaders() calls send a fresh api key.
        $this->withHeaders([
            'x-api-key' => self::API_KEY,
            'Accept'    => 'application/json',
        ]);
    }

    /** @test */
    public function test_kiosk_token_refresh_preserves_kiosk_order_ability_only(): void
    {
        $user = User::factory()->create(['status' => Status::ACTIVE]);
        $plain = $user->createToken('kiosk-token', ['kiosk:order'])->plainTextToken;

        $response = $this->postJson('/api/refresh-token', ['token' => $plain]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['token']);

        $newPlain = $response->json('token');
        $this->assertNotSame($plain, $newPlain, 'New token must differ from the old one.');

        $stored = PersonalAccessToken::findToken($newPlain);
        $this->assertNotNull($stored, 'Refreshed token should be persisted.');
        $this->assertSame(['kiosk:order'], $stored->abilities, 'Refreshed token must preserve original kiosk:order ability only.');
        $this->assertNotContains('*', $stored->abilities, 'Refresh must NEVER grant wildcard ability when the source token had a restricted scope.');

        // Ensure the old token has been revoked (rotation).
        $this->assertNull(PersonalAccessToken::findToken($plain), 'Old token must be deleted after refresh.');
    }

    /** @test */
    public function test_admin_token_refresh_preserves_full_abilities(): void
    {
        $user = User::factory()->create(['status' => Status::ACTIVE]);
        $plain = $user->createToken('auth_token', ['*'])->plainTextToken;

        $response = $this->postJson('/api/refresh-token', ['token' => $plain]);

        $response->assertStatus(201);

        $stored = PersonalAccessToken::findToken($response->json('token'));
        $this->assertNotNull($stored);
        $this->assertSame(['*'], $stored->abilities, 'Admin/customer wildcard token must keep its wildcard abilities.');
    }

    /** @test */
    public function test_token_with_no_abilities_refresh_no_wildcard(): void
    {
        $user = User::factory()->create(['status' => Status::ACTIVE]);
        // Edge case: legacy / corrupted token with no abilities at all.
        $plain = $user->createToken('legacy', [])->plainTextToken;

        $response = $this->postJson('/api/refresh-token', ['token' => $plain]);

        $response->assertStatus(201);

        $stored = PersonalAccessToken::findToken($response->json('token'));
        $this->assertNotNull($stored);
        $this->assertSame([], $stored->abilities, 'No-ability source token must refresh to a no-ability token (least privilege).');
        $this->assertNotContains('*', $stored->abilities, 'Refresh must NEVER fall back to wildcard.');
    }

    /** @test */
    public function test_invalid_token_returns_401(): void
    {
        $response = $this->postJson('/api/refresh-token', ['token' => 'totally-bogus-token-string']);

        // Pre-fix this triggered a fatal masked as 422; post-fix we explicitly
        // return 401 to signal an authentication failure.
        $response->assertStatus(401);
        $response->assertJson(['status' => false]);
    }
}
