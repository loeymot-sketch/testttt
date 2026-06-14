<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC-ENUM (ultra-audit P1, 2026-06-14) — the forgot-password endpoint must NOT
 * leak account existence. Pre-heal it returned 200 'check_your_email' for a known
 * email vs 400 'email_does_not_exist' for an unknown one — a definitive enumeration
 * oracle (distinct status AND body). Now both return an identical neutral 200; the
 * DB lookup + token insert + dispatch happen only for a real user. Mirrors the
 * kiosk-login enumeration hardening.
 */
class ForgotPasswordEnumerationSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config(['app.api_key' => '123456']);
        $this->withHeaders(['x-api-key' => '123456', 'Accept' => 'application/json']);
    }

    public function test_existing_and_unknown_email_get_identical_neutral_response(): void
    {
        User::factory()->create(['email' => 'real@lecayenne.fr']);

        $existing = $this->postJson('/api/auth/forgot-password', ['email' => 'real@lecayenne.fr']);
        $unknown  = $this->postJson('/api/auth/forgot-password', ['email' => 'nope-99999@nowhere.test']);

        $existing->assertStatus(200);
        $unknown->assertStatus(200);
        $this->assertSame(
            $existing->json(),
            $unknown->json(),
            'No account-existence leak: response body must be identical for known and unknown emails.'
        );

        // The real work (token row) happens ONLY for a real user — never leaked via HTTP.
        $this->assertDatabaseHas('password_resets', ['email' => 'real@lecayenne.fr']);
        $this->assertDatabaseMissing('password_resets', ['email' => 'nope-99999@nowhere.test']);
    }
}
