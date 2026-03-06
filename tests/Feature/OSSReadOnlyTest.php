<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OSSReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_oss_system_is_read_only()
    {
        // OSS = Order Status Screen (L'écran qui montre les numéros de ticket aux clients)
        // Normalement accessible via apiKey

        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'x-api-key' => '123456' // clé standard par défaut
        ])->postJson('/api/admin/oss-order/fake-action', [
                    'status' => 'DELIVERED'
                ]);

        // L'écran client ne doit RIEN pouvoir envoyer en POST vers les routes OSS.
        $this->assertEquals(405, $response->status()); // La route ne supporte pas POST (Method Not Allowed), prouvant read-only
    }
}
