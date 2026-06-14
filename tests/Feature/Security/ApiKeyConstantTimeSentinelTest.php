<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * SEC-APIKEY-TIMING (ultra-review 2026-06-14) — the shared API key MUST be compared
 * in constant time. `===` on strings short-circuits at the first differing byte,
 * leaking the key one byte at a time via response timing. Lock hash_equals + the
 * null/empty-config guard so a regression to `===` (or a misconfigured key granting
 * access) fails CI.
 */
class ApiKeyConstantTimeSentinelTest extends TestCase
{
    public function test_api_key_middleware_uses_constant_time_compare(): void
    {
        $src = file_get_contents(base_path('app/Http/Middleware/ApiKeyMiddleware.php'));

        $this->assertStringContainsString(
            'hash_equals(',
            $src,
            'ApiKeyMiddleware must compare the API key with hash_equals (constant-time).'
        );
        // The key compare must NOT use === / == against the header (timing oracle).
        $this->assertDoesNotMatchRegularExpression(
            "/header\(['\"]x-api-key['\"]\)\s*===?/i",
            $src,
            'ApiKeyMiddleware must NOT compare the x-api-key header with ===/== (non-constant-time).'
        );
        // A null/empty configured key must never reach hash_equals as a valid match.
        $this->assertMatchesRegularExpression(
            "/is_string\(\\\$validApiKey\)\s*&&\s*\\\$validApiKey\s*!==\s*''/",
            $src,
            'ApiKeyMiddleware must guard against a null/empty configured key before comparing.'
        );
    }
}
