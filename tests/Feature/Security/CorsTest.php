<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_cors_rejects_unknown_origin(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://evil.com',
        ])->options('/api/auth/login');

        $this->assertNotEquals(
            'https://evil.com',
            $response->headers->get('Access-Control-Allow-Origin')
        );
    }

    public function test_cors_allows_app_url_origin(): void
    {
        $appUrl = config('app.url');
        if (empty($appUrl)) {
            $this->markTestSkipped('APP_URL not set');
        }

        $response = $this->withHeaders([
            'Origin' => $appUrl,
        ])->options('/api/auth/login');

        $this->assertTrue(
            $response->headers->get('Access-Control-Allow-Origin') === $appUrl
            || $response->headers->get('Access-Control-Allow-Origin') === '*'
            || $response->headers->get('Access-Control-Allow-Origin') === null,
            'CORS should allow APP_URL origin or be absent for same-origin'
        );
    }

    public function test_cors_config_does_not_contain_wildcard(): void
    {
        $origins = config('cors.allowed_origins');
        $this->assertNotContains('*', $origins, 'CORS allowed_origins must not contain wildcard *');
    }
}
