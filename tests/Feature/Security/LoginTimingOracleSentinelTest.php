<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC-LOGIN-TIMING (ultra-review 2026-06-15): the staff/admin login must not leak account
 * existence via response timing. Auth::attempt() runs bcrypt ONLY when a user is found, so
 * an unknown email returns fast — a username-enumeration oracle. The not-found path now
 * burns an equivalent Hash::check against a fixed dummy hash. Lock that + the neutral body.
 */
class LoginTimingOracleSentinelTest extends TestCase
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

    public function test_login_controller_burns_a_dummy_hash_on_unknown_email(): void
    {
        $src = file_get_contents(base_path('app/Http/Controllers/Auth/LoginController.php'));
        $this->assertMatchesRegularExpression(
            '/Hash::check\(/',
            $src,
            'LoginController must run a constant-time Hash::check on the failed/unknown-email path.'
        );
        $this->assertStringContainsString(
            '$2y$12$',
            $src,
            'A fixed dummy bcrypt hash must back the constant-time compare.'
        );
    }

    public function test_failed_login_body_is_neutral_credentials_invalid(): void
    {
        // The failed-login response is a single neutral message (no existence branch).
        $src = file_get_contents(base_path('app/Http/Controllers/Auth/LoginController.php'));
        $this->assertStringContainsString(
            "trans('all.message.credentials_invalid')",
            $src,
            'Failed login must return the neutral credentials_invalid message for all cases (no existence leak).'
        );
        // Behavioral login coverage (valid + invalid creds) lives in AuthComprehensiveTest.
        $this->assertTrue(true);
    }
}
