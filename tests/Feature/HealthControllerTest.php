<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthControllerTest extends TestCase
{
    public function test_health_live_returns_200(): void
    {
        $response = $this->get('/api/health/live');
        $response->assertStatus(200);
        $response->assertSee('OK');
    }

    public function test_health_ready_returns_json(): void
    {
        $response = $this->getJson('/api/health/ready');
        $response->assertStatus(200);
        $response->assertJsonStructure(['status', 'subsystems']);
    }

    public function test_health_full_returns_json(): void
    {
        $response = $this->getJson('/api/health');
        $response->assertJsonStructure([
            'status',
            'version',
            'timestamp',
            'subsystems' => ['db', 'redis', 'queue', 'broadcast'],
        ]);
    }
}
