<?php

namespace Tests\Feature\Routing;

use Tests\TestCase;

/**
 * [test-e2e goal4-predeploy C-001 2026-07-17] Le catch-all SPA absorbait les
 * ASSETS manquants (/js/chunk-périmé.js, /storage/img.png…) en 200 text/html →
 * pages blanches « tout vert » (classe exacte de l'incident paiement borne
 * 2026-07-07 chunk stale) + harnais aveugle (network ne loggue que ≥400).
 * Un chemin d'ASSET inexistant doit répondre 404 ; les routes SPA restent 200.
 */
class SpaFallbackAssetNotFoundTest extends TestCase
{
    public function test_missing_asset_paths_get_a_real_404(): void
    {
        foreach ([
            '/js/chunk-inexistant-xyz.js',
            '/css/inexistant.css',
            '/images/menu/inexistante.png',
            '/storage/18/disparue.jpg',
            '/fonts/absente.woff2',
        ] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_spa_routes_still_match_the_catch_all(): void
    {
        // Le rendu complet exige les settings d'une install — hors sujet ici.
        // On prouve la décision de ROUTING : une route SPA n'est PAS 404.
        $status = $this->get('/une-route-spa-inconnue')->getStatusCode();
        $this->assertNotSame(404, $status, 'le catch-all doit toujours matcher les routes SPA');
    }
}
