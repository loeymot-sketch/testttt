<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OSSReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_oss_system_is_strictly_read_only()
    {
        // OSS Token should exclusively permit GET on specific OSS feeds.
        $headers = [
            'Accept' => 'application/json',
            'x-api-key' => '123456' // standard api key
        ];

        // Try POST
        $responsePost = $this->withHeaders($headers)
            ->postJson('/api/admin/oss-order/fake-action', ['status' => 'DELIVERED']);

        $this->assertEquals(405, $responsePost->status());

        // Try PUT
        $responsePut = $this->withHeaders($headers)
            ->putJson('/api/admin/order/1', ['status' => 'DELIVERED']);

        $this->assertTrue(in_array($responsePut->status(), [401, 403, 405]));
    }
}
