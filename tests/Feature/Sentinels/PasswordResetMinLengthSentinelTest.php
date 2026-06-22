<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * [F-2 AUTH R1 V1.0.1 quick win — 2026-05-19]
 *
 * Sentinel locking the password-reset endpoint to a minimum of 12 characters
 * to match the staff create/update path. Without this gate, a user could
 * reset their password to a 6-character string (the previous bound), which
 * undercut the bcrypt-rounds-12 hardening shipped in Wave 5G.
 *
 * Source audit: reports/audit/foundation-2026-05-18/round-1/F-2-AUTH/STATUS.md
 *               §5.1 V1.0.1 quick wins → R1 (P1)
 *
 * Mode: source-pin (static read of ForgotPasswordController.php). Cheap,
 * deterministic, no HTTP boot required. Detects regression at PHPUnit time.
 */
class PasswordResetMinLengthSentinelTest extends TestCase
{
    public function test_reset_password_validator_requires_min_12_characters(): void
    {
        $source = file_get_contents(
            base_path('app/Http/Controllers/Auth/ForgotPasswordController.php')
        );

        $this->assertNotFalse($source, 'ForgotPasswordController.php missing');

        // resetPassword() rules block (lines 120-125) must declare min:12.
        // Greedy match against the resetPassword Validator::make array.
        $this->assertMatchesRegularExpression(
            "/public function resetPassword.*?'password'\s*=>\s*\[[^\]]*'min:12'[^\]]*\]/s",
            $source,
            'resetPassword() must enforce password min:12 (parity with staff create/update). '
                . 'See reports/audit/foundation-2026-05-18/round-1/F-2-AUTH/STATUS.md §5.1 R1.'
        );

        $this->assertMatchesRegularExpression(
            "/public function resetPassword.*?'password_confirmation'\s*=>\s*\[[^\]]*'min:12'[^\]]*\]/s",
            $source,
            'resetPassword() must enforce password_confirmation min:12 (mirror of password rule).'
        );

        // Defense-in-depth: NO surviving min:6 in resetPassword() block.
        $resetBlock = preg_match(
            "/public function resetPassword.*?public function /s",
            $source,
            $m
        );
        if ($resetBlock === 1) {
            $this->assertStringNotContainsString(
                "'min:6'",
                $m[0],
                'resetPassword() must NOT carry the legacy min:6 string (would silently weaken bcrypt-12 hardening).'
            );
        }
    }
}
