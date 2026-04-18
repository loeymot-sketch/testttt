<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Contract tests for HealthController — V1 OBS_HEALTH_CORR task.
 *
 * @see app/Http/Controllers/HealthController.php
 * @see docs/OBSERVABILITY.md
 */
class HealthControllerTest extends TestCase
{
    public function test_health_live_returns_200_plain_text_ok(): void
    {
        $response = $this->get('/api/health/live');

        $response->assertStatus(200);
        $this->assertSame('OK', trim($response->getContent()));
    }

    public function test_health_live_carries_correlation_id_header(): void
    {
        $response = $this->get('/api/health/live');

        $this->assertNotEmpty(
            $response->headers->get('X-Correlation-ID'),
            'Liveness probe must propagate X-Correlation-ID for log tracing'
        );
    }

    public function test_health_ready_returns_full_schema_when_healthy(): void
    {
        $response = $this->getJson('/api/health/ready');

        $response->assertJsonStructure([
            'status',
            'subsystems' => ['db', 'redis'],
        ]);

        $status = $response->json('status');
        $this->assertContains(
            $status,
            ['ok', 'degraded'],
            'ready status must be one of the documented values'
        );
    }

    public function test_health_ready_returns_503_when_degraded(): void
    {
        $response = $this->getJson('/api/health/ready');

        if ($response->json('status') === 'degraded') {
            $response->assertStatus(503);
        } else {
            $response->assertStatus(200);
        }
    }

    public function test_health_full_returns_documented_schema(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertJsonStructure([
            'status',
            'version',
            'timestamp',
            'subsystems' => ['db', 'redis', 'queue', 'broadcast'],
        ]);

        $this->assertIsString(
            $response->json('timestamp'),
            'timestamp must be a string'
        );
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T/',
            (string) $response->json('timestamp'),
            'timestamp must be ISO-8601'
        );
    }

    public function test_health_full_subsystem_reports_have_status_key(): void
    {
        $response = $this->getJson('/api/health');

        foreach (['db', 'redis', 'queue', 'broadcast'] as $key) {
            $this->assertNotNull(
                $response->json("subsystems.$key.status"),
                "subsystems.$key.status is required by the observability contract"
            );
        }
    }

    public function test_health_full_rejects_non_whitelisted_ip_when_whitelist_set(): void
    {
        config()->set('app.env', 'production');
        putenv('HEALTH_IPS_ALLOWED=10.0.0.1,10.0.0.2');
        try {
            $response = $this->call(
                'GET',
                '/api/health',
                [],
                [],
                [],
                [
                    'REMOTE_ADDR' => '203.0.113.7',
                    'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
                ]
            );

            $this->assertSame(
                403,
                $response->getStatusCode(),
                'Full /health must 403 non-whitelisted IPs when HEALTH_IPS_ALLOWED is set'
            );
        } finally {
            putenv('HEALTH_IPS_ALLOWED');
        }
    }
}
