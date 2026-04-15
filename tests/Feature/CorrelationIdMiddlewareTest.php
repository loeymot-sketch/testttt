<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorrelationIdMiddlewareTest extends TestCase
{
    public function test_generates_correlation_id_if_absent(): void
    {
        $response = $this->getJson('/api/health/live');
        $this->assertNotEmpty($response->headers->get('X-Correlation-ID'));
    }

    public function test_propagates_correlation_id_from_request(): void
    {
        $cid = 'test-cid-'.uniqid();
        $response = $this->withHeaders(['X-Correlation-ID' => $cid])
            ->getJson('/api/health/live');
        $this->assertEquals($cid, $response->headers->get('X-Correlation-ID'));
    }
}
