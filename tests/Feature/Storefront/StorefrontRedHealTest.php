<?php

namespace Tests\Feature\Storefront;

use App\Models\Branch;
use App\Models\Coupon;
use App\Models\StorefrontBanner;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [APP HYBRIDE W2a · relecture adverse 2026-11-03] Constats de reports/test-e2e/app-hybride-2026-11/W2/RED-W2a.md, chacun
 * reproduit par la sonde du relecteur avant correctif :
 *   P2-1 une date illisible renvoyait 500 au lieu d'un 422 en français ;
 *   P2-2 un administrateur publiait le brouillon partagé tel que modifié par un AUTRE, sans l'avoir vu (seule la version
 *        publiée était comparée) → empreinte du brouillon exigée à la publication, revérifiée sous verrou ;
 *   P3   un bandeau terminé ne pouvait plus être désactivé ; un code promo expiré / inactif était accepté ; un double envoi
 *        avec la même clé d'idempotence créait deux bandeaux.
 */
class StorefrontRedHealTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config(['app.api_key' => '123456']);
        Branch::factory()->create(['id' => 1]);
        app(\App\Services\Delivery\DeliveryAvailability::class)->set(true);
        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00', 'Europe/Paris'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(): User
    {
        $u = User::factory()->create(['branch_id' => 1]);
        $u->givePermissionTo('settings');
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    private function api(string $method, string $uri = '', array $data = [], array $headers = [])
    {
        return $this->withHeaders(['x-api-key' => '123456'] + $headers)->json($method, self::BASE.$uri, $data);
    }

    private function banner(array $over = []): array
    {
        return $over + ['title' => 'Bandeau', 'type' => 'info', 'starts_at' => '2026-12-01T00:00', 'ends_at' => '2026-12-26T00:00', 'priority' => 1, 'active' => true];
    }

    public function test_une_date_illisible_donne_un_422_en_francais_jamais_un_500(): void
    {
        $this->owner();
        $this->api('POST', '/banners', $this->banner(['starts_at' => 'pas-une-date']))->assertStatus(422)->assertJsonValidationErrors('starts_at');
        $this->api('POST', '/banners', $this->banner(['ends_at' => '31/12/2026 23:00']))->assertStatus(422)->assertJsonValidationErrors('ends_at');
    }

    public function test_publier_exige_l_empreinte_du_brouillon_vu(): void
    {
        $this->owner();
        $this->api('PUT', '/settings', ['enabled' => true, 'theme' => null])->assertOk();
        $this->api('POST', '/publish', ['base_version' => 0])->assertStatus(422)->assertJsonValidationErrors('draft_hash');
        $hash = $this->api('GET')->json('data.draft_hash');
        $this->assertIsString($hash);
        $this->api('POST', '/publish', ['base_version' => 0, 'draft_hash' => $hash])->assertOk();
    }

    public function test_un_brouillon_modifie_par_un_autre_administrateur_n_est_pas_publie_a_l_aveugle(): void
    {
        $a = $this->owner();
        $vu = $this->api('GET');
        $b = User::factory()->create(['branch_id' => 1]);
        $b->givePermissionTo('settings');
        Sanctum::actingAs($b, ['*']);
        $this->api('PUT', '/settings', ['enabled' => true, 'theme' => null])->assertOk();
        $this->api('POST', '/banners', $this->banner(['title' => 'Ajout de B jamais vu par A']))->assertCreated();
        Sanctum::actingAs($a, ['*']);
        $r = $this->api('POST', '/publish', ['base_version' => $vu->json('data.published.version'), 'draft_hash' => $vu->json('data.draft_hash')]);
        $r->assertStatus(409);
        $this->assertStringContainsString('brouillon', mb_strtolower($r->json('message')));
        $this->withHeader('x-api-key', '123456')->getJson('/api/frontend/storefront')->assertJsonPath('version', 0);
    }

    public function test_un_bandeau_termine_peut_encore_etre_desactive(): void
    {
        $this->owner();
        $id = $this->api('POST', '/banners', $this->banner(['ends_at' => '2026-12-02T00:00']))->assertCreated()->json('data.banners.0.id');
        Carbon::setTestNow(Carbon::parse('2026-12-03 10:00:00', 'Europe/Paris'));
        $this->api('PUT', "/banners/{$id}", $this->banner(['ends_at' => '2026-12-02T00:00', 'active' => false]))->assertOk();
        $this->assertFalse((bool) StorefrontBanner::query()->find($id)->active);
        // le réactiver avec une fin passée reste refusé (il ne s'afficherait jamais)
        $this->api('PUT', "/banners/{$id}", $this->banner(['ends_at' => '2026-12-02T00:00', 'active' => true]))->assertStatus(422);
    }

    public function test_un_code_promo_expire_ou_inactif_est_refuse(): void
    {
        $this->owner();
        $base = ['discount' => 5, 'discount_type' => 10, 'minimum_order' => 0, 'maximum_discount' => 0, 'limit_per_user' => 1];
        Coupon::query()->forceCreate($base + ['name' => 'Expiré', 'code' => 'ETE2026', 'status' => 5, 'start_date' => '2026-06-01 00:00:00', 'end_date' => '2026-08-31 23:59:59']);
        Coupon::query()->forceCreate($base + ['name' => 'Coupé', 'code' => 'COUPE', 'status' => 10, 'start_date' => '2026-11-01 00:00:00', 'end_date' => '2027-01-31 23:59:59']);
        Coupon::query()->forceCreate($base + ['name' => 'Noël', 'code' => 'NOEL', 'status' => 5, 'start_date' => '2026-11-01 00:00:00', 'end_date' => '2027-01-31 23:59:59']);
        $this->api('POST', '/banners', $this->banner(['type' => 'flash', 'coupon_code' => 'ETE2026']))->assertStatus(422)->assertJsonValidationErrors('coupon_code');
        $this->api('POST', '/banners', $this->banner(['type' => 'flash', 'coupon_code' => 'COUPE']))->assertStatus(422)->assertJsonValidationErrors('coupon_code');
        $this->api('POST', '/banners', $this->banner(['type' => 'flash', 'coupon_code' => 'noel']))->assertCreated();
    }

    public function test_un_double_envoi_avec_la_meme_cle_ne_cree_qu_un_bandeau(): void
    {
        // Allumé comme en production (garde de démarrage AppServiceProvider : IDEMPOTENCY_MIDDLEWARE_ENABLED obligatoire).
        config(['idempotency.enabled' => true]);
        $this->owner();
        $h = ['X-Idempotency-Key' => 'cle-unique-bandeau-1'];
        $this->api('POST', '/banners', $this->banner(), $h)->assertCreated();
        $this->api('POST', '/banners', $this->banner(), $h);
        $this->assertSame(1, StorefrontBanner::query()->count());
    }
}
