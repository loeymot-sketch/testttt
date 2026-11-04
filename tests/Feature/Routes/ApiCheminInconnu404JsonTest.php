<?php

namespace Tests\Feature\Routes;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [W7-D D-011 · cycle C 2026-11-04] `GET /api/frontend/order/track/abc` (jeton mal formé) répondait la coquille SPA
 * (200 text/html) à un client qui demande du JSON. Tout chemin `/api/…` sans route répond 404 JSON ; les pages du
 * site passent toujours par l'attrape-tout SPA.
 */
class ApiCheminInconnu404JsonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (!file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }
        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key']);
        $this->withHeaders(['x-api-key' => 'test-api-key', 'Accept' => 'application/json']);
    }

    /** @test */
    public function un_jeton_de_suivi_mal_forme_repond_404_json(): void
    {
        $this->getJson('/api/frontend/order/track/abc')
            ->assertStatus(404)
            ->assertHeader('content-type', 'application/json')
            ->assertJson(['status' => false]);
    }

    /** @test */
    public function un_chemin_api_inconnu_repond_404_json(): void
    {
        $this->getJson('/api/nexiste/pas')
            ->assertStatus(404)
            ->assertHeader('content-type', 'application/json');
    }

    /** @test */
    public function un_jeton_bien_forme_atteint_toujours_le_controleur(): void
    {
        $this->getJson('/api/frontend/order/track/' . str_repeat('a', 48))
            ->assertStatus(200)
            ->assertJson(['found' => false]);
    }
}
