<?php

namespace Tests\Feature\Routes;

use Tests\TestCase;

/**
 * [ANTI-429 2026-10-29] Le site (www.lecayenne.fr) et l'application des stores appellent l'API depuis une autre
 * origine : sans `Access-Control-Expose-Headers: Retry-After`, le navigateur leur CACHE la durée d'attente d'un 429
 * et le rejeu silencieux (api.js) retombe sur son calendrier de repli. Les bancs du site simulaient cet en-tête
 * lisible alors que la production ne l'exposait pas — ce test fige la réalité.
 */
class CorsExposeRetryAfterTest extends TestCase
{
    public function test_la_config_expose_retry_after_aux_clients_d_autre_origine(): void
    {
        $exposed = array_map('strtolower', (array) config('cors.exposed_headers'));
        $this->assertContains('retry-after', $exposed);
        $this->assertContains('x-ratelimit-remaining', $exposed);
    }

    public function test_une_reponse_d_api_pour_une_origine_autorisee_annonce_l_en_tete_expose(): void
    {
        $origin = 'http://localhost:8011'; // origine du site en local, déjà autorisée par config/cors.php

        $res = $this->withHeaders(['Origin' => $origin, 'Accept' => 'application/json'])
            ->get('/api/frontend/setting');

        $res->assertHeader('Access-Control-Allow-Origin', $origin);
        $this->assertStringContainsString('Retry-After', (string) $res->headers->get('Access-Control-Expose-Headers'));
    }
}
