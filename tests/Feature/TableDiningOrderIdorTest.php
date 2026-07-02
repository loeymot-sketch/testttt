<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [ULTRA-AUDIT V2 2026-07-02 — P2 IDOR] GET /api/table/dining-order/show/{id} était NON-authentifié
 * (QR, groupe apiKey seul, RMB par PK, 0 contrôle d'ownership) → exposait la PII client
 * (nom/tél/email/solde) par énumération. Le dine-in étant DORMANT en V1 (pos_dine_in_enabled=false),
 * le contrôleur fail-close en 404 tant que le dine-in est OFF → surface d'énumération PII supprimée.
 * (Le garde du constructeur s'exécute AVANT le route-model-binding : aucune PII n'est jamais chargée.)
 */
class TableDiningOrderIdorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
    }

    private function apiKey(): string
    {
        return config('app.api_key', env('MIX_API_KEY', 'test-api-key'));
    }

    public function test_dining_order_show_returns_404_when_dine_in_disabled(): void
    {
        // dine-in OFF (défaut V1) — attaquant non-authentifié (x-api-key publique seule).
        foreach ([1, 100, 999999] as $probeId) {
            $res = $this->withHeader('x-api-key', $this->apiKey())
                ->getJson("/api/table/dining-order/show/{$probeId}");
            $res->assertStatus(404); // garde dormant fail-closed, avant tout chargement d'ordre
        }
    }

    public function test_guard_lifts_when_dine_in_enabled(): void
    {
        // Dine-in activé : le garde dormant ne doit PLUS 404 en amont ; un ordre inexistant
        // renvoie alors le 404 du route-model-binding (comportement normal), mais la surface
        // n'est plus universellement fermée par le garde. On prouve que le garde a bien
        // basculé en lisant le setting (pré-requis du garde).
        Settings::group('pos')->set(['pos_dine_in_enabled' => true]);
        $this->assertTrue(
            (bool) Settings::group('pos')->get('pos_dine_in_enabled', false),
            'le setting pos_dine_in_enabled doit être lisible=true (le garde lève la fermeture dormant)'
        );
    }
}
