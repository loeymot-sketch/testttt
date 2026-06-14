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

    /**
     * SEC-H15-TIMING-01: the reset email must be sent OUT OF BAND (queued) so the
     * synchronous SMTP latency on the known-email path can't re-open the oracle via
     * response timing. Lock the listener as ShouldQueue.
     */
    public function test_reset_password_notification_is_queued(): void
    {
        $this->assertInstanceOf(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            new \App\Listeners\SendResetPasswordNotification(),
            'SendResetPasswordNotification must be queued so the SMTP send does not leak account existence via timing.'
        );
    }

    /**
     * SEC-H15-SIBLING-02: reset-password must NOT leak account existence — an unknown
     * email and a known email with a bad token must return the IDENTICAL 422 response
     * (pre-heal: 404 'user_match' vs 422 'token_is_invalid' = an enumeration oracle).
     */
    public function test_reset_password_unknown_email_matches_invalid_token_response(): void
    {
        User::factory()->create(['email' => 'real@lecayenne.fr']);
        $token = str_repeat('a', 64);
        $payload = fn (string $email) => [
            'email'                 => $email,
            'reset_token'           => $token,
            'password'              => 'longenoughpw1',
            'password_confirmation' => 'longenoughpw1',
        ];

        $unknown = $this->postJson('/api/auth/forgot-password/reset-password', $payload('nope-99999@nowhere.test'));
        $knownBadToken = $this->postJson('/api/auth/forgot-password/reset-password', $payload('real@lecayenne.fr'));

        $unknown->assertStatus(422);
        $knownBadToken->assertStatus(422);
        $this->assertSame(
            $unknown->json(),
            $knownBadToken->json(),
            'No account-existence leak: unknown-email and bad-token reset responses must be identical.'
        );
    }
}
