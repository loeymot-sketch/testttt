<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * Feature guards for TASK_V1_SEC_CORS_RATELIMIT_001 — CORS whitelist.
 *
 * @see config/cors.php
 * @see docs/RATE_LIMITS_MATRIX.md §CORS Policy
 */
class CorsTest extends TestCase
{
    public function test_cors_config_does_not_contain_wildcard(): void
    {
        $origins = config('cors.allowed_origins');

        $this->assertIsArray($origins, 'cors.allowed_origins must be a list');
        $this->assertNotContains('*', $origins, 'CORS allowed_origins must not contain wildcard *');

        $patterns = config('cors.allowed_origins_patterns', []);
        $this->assertNotContains('.*', $patterns, 'CORS allowed_origins_patterns must not contain wildcard .*');
    }

    public function test_cors_allows_credentials_only_with_specific_origins(): void
    {
        $supportsCredentials = (bool) config('cors.supports_credentials');
        if ($supportsCredentials) {
            $origins = config('cors.allowed_origins');
            $this->assertNotContains(
                '*',
                $origins,
                'supports_credentials=true with wildcard origin is a CSRF-equivalent vulnerability'
            );
        } else {
            $this->markTestSkipped('supports_credentials disabled, nothing to guard');
        }
    }

    public function test_cors_preflight_rejects_unknown_origin(): void
    {
        $response = $this->call(
            'OPTIONS',
            '/api/auth/login',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => 'https://evil.example.com',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-api-key',
            ]
        );

        $allowOrigin = $response->headers->get('Access-Control-Allow-Origin');

        $this->assertNotEquals(
            'https://evil.example.com',
            $allowOrigin,
            'Non-whitelisted origin must not be echoed in Access-Control-Allow-Origin'
        );
        $this->assertNotEquals('*', $allowOrigin, 'Wildcard must never be returned in CORS response');
    }

    public function test_cors_preflight_allows_app_url_origin(): void
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl === '') {
            $this->markTestSkipped('APP_URL not set in test env');
        }

        config()->set('cors.allowed_origins', array_values(array_unique(array_merge(
            (array) config('cors.allowed_origins', []),
            [$appUrl]
        ))));

        $response = $this->call(
            'OPTIONS',
            '/api/auth/login',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => $appUrl,
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-api-key',
            ]
        );

        $allowOrigin = $response->headers->get('Access-Control-Allow-Origin');

        $this->assertSame(
            $appUrl,
            $allowOrigin,
            'Preflight from whitelisted APP_URL must echo the exact origin back'
        );
    }
}
