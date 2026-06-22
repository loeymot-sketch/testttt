<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Status;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [WJ-3 WI-5 SEC-RST-01 V1.0.1 P1 — 2026-05-19]
 *
 * Sentinel locking the password-reset endpoint to revoke ALL Sanctum tokens
 * tied to the resetting user, so that previously-issued tokens (which may be
 * in attacker hands and motivated the reset in the first place) cannot
 * outlive the password change.
 *
 * Vulnerability (pre-fix):
 *   `ForgotPasswordController::resetPassword` updates the password hash but
 *   leaves the user's `personal_access_tokens` rows untouched. Sanctum tokens
 *   keep their 480-minute TTL — an attacker who already exfiltrated a token
 *   retains full session privileges for hours after the legitimate user
 *   completed the reset.
 *
 * Reference parity: LoginController re-login flow already revokes prior
 *   `auth_token` rows for the same reason (Sprint 5D Z6-01 2026-05-16). The
 *   reset path was overlooked.
 *
 * Source audit: reports/audit/wave-j-2026-05-19/WJ-3-WI5-SECRST01-TOKEN-REVOKE/STATUS.md
 *
 * Mode: behavioral (HTTP + real DB). Source-pin would not have caught the
 * timing issue (delete must happen BEFORE createToken, not after, or the
 * fresh session token gets nuked).
 */
class PasswordResetRevokesTokensSentinelTest extends TestCase
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

        // resetPassword reads (int)Settings::group('otp')->get('otp_expire_time')
        // to validate freshness of the reset_token row. Seed it explicitly so
        // the freshness check (lines 154-161) doesn't 400 us with "code_is_expired".
        Settings::group('otp')->set([
            'otp_type'         => 'email',
            'otp_digit_limit'  => 6,
            'otp_expire_time'  => 10,
        ]);

        $this->withHeaders([
            'x-api-key' => self::API_KEY,
            'Accept'    => 'application/json',
        ]);
    }

    /**
     * Insert a fresh `password_resets` row that matches the validator's
     * `size:64` requirement and returns the plaintext token so the request
     * payload can carry it.
     */
    private function seedResetToken(string $email): string
    {
        $token = Str::random(64);

        DB::table('password_resets')->insert([
            'email'      => $email,
            'token'      => $token,
            'created_at' => Carbon::now(),
        ]);

        return $token;
    }

    /**
     * @test
     * RED before fix: the user retains all their old Sanctum token rows in
     * `personal_access_tokens` after a successful password reset — the new
     * session token is the ONLY row that should remain.
     */
    public function test_reset_password_revokes_existing_sanctum_tokens(): void
    {
        $user = User::factory()->create([
            'email'  => 'reset-revoke-1@example.com',
            'status' => Status::ACTIVE,
        ]);

        // Pre-existing tokens that should be wiped by the reset.
        $oldAdminToken = $user->createToken('auth_token', ['*'])->plainTextToken;
        $oldKioskToken = $user->createToken('kiosk-token', ['kiosk:order'])->plainTextToken;

        $this->assertSame(2, $user->tokens()->count(), 'Test fixture must start with 2 tokens.');

        // Capture pre-reset token IDs to prove they were physically deleted
        // (not just rotated by name) once the reset completes.
        $oldIds = $user->tokens()->pluck('id')->all();

        $resetToken = $this->seedResetToken($user->email);

        $response = $this->postJson('/api/auth/forgot-password/reset-password', [
            'email'                 => $user->email,
            'reset_token'           => $resetToken,
            'password'              => 'NewStrongPass123!',
            'password_confirmation' => 'NewStrongPass123!',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['message', 'token']);

        // Refresh the user model from disk so $user->tokens() reads
        // post-transaction state, not the stale in-memory relation.
        $user->refresh();

        // The two ORIGINAL token rows must be gone after the reset. The
        // controller MAY return a brand-new session token in the JSON body —
        // that's expected — but none of the old IDs may survive.
        $survivingOldIds = $user->tokens()->whereIn('id', $oldIds)->pluck('id')->all();
        $this->assertEmpty(
            $survivingOldIds,
            'resetPassword() must revoke ALL pre-existing Sanctum tokens. '
            . 'Surviving IDs: ' . implode(',', $survivingOldIds) . '. '
            . 'See reports/audit/wave-j-2026-05-19/WJ-3-WI5-SECRST01-TOKEN-REVOKE/STATUS.md.'
        );

        // Cross-check: the only row that may remain is the freshly-minted
        // post-reset session token (returned in the JSON body). Anything
        // more = stale token sprawl.
        $remaining = $user->tokens()->count();
        $this->assertLessThanOrEqual(
            1,
            $remaining,
            "After reset, at most 1 token (the new session) may remain. Found {$remaining}."
        );
    }

    /**
     * @test
     * RED before fix: a stale token issued BEFORE the password reset still
     * grants access to `auth:sanctum`-gated endpoints after the reset — the
     * attacker-controlled session is not invalidated.
     */
    public function test_old_token_returns_401_on_authenticated_endpoint_after_reset(): void
    {
        $user = User::factory()->create([
            'email'  => 'reset-revoke-2@example.com',
            'status' => Status::ACTIVE,
        ]);

        $oldToken = $user->createToken('auth_token', ['*'])->plainTextToken;

        // Sanity check: pre-reset, the old token is alive (sanctum
        // resolves it to a User row in personal_access_tokens).
        $this->assertNotNull(
            PersonalAccessToken::findToken($oldToken),
            'Pre-reset token must be valid as a baseline.'
        );

        $resetToken = $this->seedResetToken($user->email);

        $this->postJson('/api/auth/forgot-password/reset-password', [
            'email'                 => $user->email,
            'reset_token'           => $resetToken,
            'password'              => 'NewStrongPass123!',
            'password_confirmation' => 'NewStrongPass123!',
        ])->assertStatus(200);

        // The pre-reset token must no longer resolve — Sanctum's findToken()
        // returns null when the row is deleted from personal_access_tokens.
        $this->assertNull(
            PersonalAccessToken::findToken($oldToken),
            'Old Sanctum token row must be physically deleted from personal_access_tokens after reset.'
        );

        // Behavioral cross-check: hit a real auth:sanctum endpoint with the
        // dead bearer and confirm we get 401 (not 200, not 500).
        $protectedHit = $this->withHeaders([
            'x-api-key'     => self::API_KEY,
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . $oldToken,
        ])->postJson('/api/auth/logout');

        $this->assertSame(
            401,
            $protectedHit->getStatusCode(),
            'Old token must be rejected by auth:sanctum after reset. Got: ' . $protectedHit->getStatusCode()
        );
    }

    /**
     * @test
     * Sanity / no-lockout: the reset response itself returns a fresh, usable
     * Sanctum token so the legitimate user is signed in immediately and is
     * NOT locked out — the security control must not break UX continuity.
     */
    public function test_user_receives_fresh_usable_token_in_reset_response(): void
    {
        $user = User::factory()->create([
            'email'  => 'reset-revoke-3@example.com',
            'status' => Status::ACTIVE,
        ]);

        // Start with one stale token.
        $user->createToken('auth_token', ['*'])->plainTextToken;

        $resetToken = $this->seedResetToken($user->email);

        $response = $this->postJson('/api/auth/forgot-password/reset-password', [
            'email'                 => $user->email,
            'reset_token'           => $resetToken,
            'password'              => 'NewStrongPass123!',
            'password_confirmation' => 'NewStrongPass123!',
        ]);

        $response->assertStatus(200);
        $newToken = $response->json('token');

        $this->assertIsString($newToken);
        $this->assertNotEmpty($newToken);

        // The token returned in the JSON body MUST be resolvable — i.e. the
        // fix did not accidentally nuke the freshly-issued session token as
        // a side-effect of the revoke (this would happen if delete() ran
        // AFTER createToken instead of BEFORE).
        $resolved = PersonalAccessToken::findToken($newToken);
        $this->assertNotNull(
            $resolved,
            'New post-reset token must be alive — placement bug if tokens()->delete() runs after createToken().'
        );
        $this->assertSame(
            (int) $user->id,
            (int) $resolved->tokenable_id,
            'New token must belong to the resetting user.'
        );
    }

    /**
     * @test
     * Idempotency on empty: a user with NO pre-existing tokens (e.g. fresh
     * signup who never logged in elsewhere) must still complete the reset
     * cleanly — `$user->tokens()->delete()` on an empty relation is a no-op
     * but the test guards against a future regression that might assume at
     * least one row exists.
     */
    public function test_reset_succeeds_when_user_has_no_existing_tokens(): void
    {
        $user = User::factory()->create([
            'email'  => 'reset-revoke-4@example.com',
            'status' => Status::ACTIVE,
        ]);

        $this->assertSame(0, $user->tokens()->count(), 'Fixture must start with zero tokens.');

        $resetToken = $this->seedResetToken($user->email);

        $response = $this->postJson('/api/auth/forgot-password/reset-password', [
            'email'                 => $user->email,
            'reset_token'           => $resetToken,
            'password'              => 'NewStrongPass123!',
            'password_confirmation' => 'NewStrongPass123!',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['message', 'token']);

        $user->refresh();
        // Exactly one token (the new session) should now exist.
        $this->assertSame(
            1,
            $user->tokens()->count(),
            'Empty-set reset must still mint one fresh session token, no more no less.'
        );
    }
}
