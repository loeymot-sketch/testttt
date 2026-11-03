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

    /**
     * [W6 cycle C · C2] Un coupon coupé APRÈS la publication (ou arrivé à sa fin) n'est plus promis par la vitrine publique :
     * le bandeau reste, sans code ; la fin du coupon entre dans next_change_at.
     */
    public function test_la_vitrine_ne_sert_plus_un_code_coupe_ou_expire_apres_publication(): void
    {
        config(['pos.coupon_codes_enabled' => true]);
        $this->owner();
        $base = ['discount' => 5, 'discount_type' => 10, 'minimum_order' => 0, 'maximum_discount' => 0, 'limit_per_user' => 1];
        $noel = Coupon::query()->forceCreate($base + ['name' => 'Noël', 'code' => 'NOEL', 'status' => 5, 'start_date' => '2026-11-01 00:00:00', 'end_date' => '2026-12-10 23:59:59']);
        $this->api('PUT', '/settings', ['enabled' => true, 'theme' => null])->assertOk();
        $this->api('POST', '/banners', $this->banner(['type' => 'flash', 'coupon_code' => 'noel']))->assertCreated();
        $h = $this->api('GET')->json('data.draft_hash');
        $this->api('POST', '/publish', ['base_version' => 0, 'draft_hash' => $h])->assertOk();
        $lire = fn () => $this->withHeader('x-api-key', '123456')->getJson('/api/frontend/storefront');

        $r = $lire()->assertJsonPath('banners.0.coupon_code', 'NOEL');
        $this->assertLessThanOrEqual(Carbon::parse('2026-12-10 23:59:59')->getTimestamp(), Carbon::parse($r->json('next_change_at'))->getTimestamp(), 'la fin du coupon déclenche une relecture');

        $noel->forceFill(['status' => 10])->save();          // coupé dans « Coupons », vitrine NON republiée
        $lire()->assertJsonPath('banners.0.title', 'Bandeau')->assertJsonPath('banners.0.coupon_code', null);

        $noel->forceFill(['status' => 5])->save();
        Carbon::setTestNow(Carbon::parse('2026-12-11 10:00:00', 'Europe/Paris'));   // coupon arrivé à sa fin, bandeau encore en cours
        $lire()->assertJsonPath('banners.0.title', 'Bandeau')->assertJsonPath('banners.0.coupon_code', null);
    }

    /**
     * [W6 cycle D · D1 + D3] La vitrine ne promet un code que si le PAIEMENT de l'application l'acceptera : interrupteur général
     * des codes promo, puis Coupon::isUsableNow($branch, 'web') (canal, branche, heures). La saisie admin refuse un code réservé
     * à un autre canal.
     */
    public function test_la_vitrine_ne_promet_que_ce_que_le_paiement_acceptera(): void
    {
        config(['pos.coupon_codes_enabled' => true]);
        $this->owner();
        $base = ['discount' => 5, 'discount_type' => 10, 'minimum_order' => 0, 'maximum_discount' => 0, 'limit_per_user' => 1,
            'status' => 5, 'start_date' => '2026-11-01 00:00:00', 'end_date' => '2027-01-31 23:59:59'];
        Coupon::query()->forceCreate($base + ['name' => 'Borne', 'code' => 'BORNE', 'surfaces' => ['kiosk']]);
        $this->api('POST', '/banners', $this->banner(['type' => 'flash', 'coupon_code' => 'borne']))->assertStatus(422)->assertJsonValidationErrors('coupon_code');

        $soir = Coupon::query()->forceCreate($base + ['name' => 'Soir', 'code' => 'SOIR', 'surfaces' => ['web', 'kiosk'], 'valid_hours_start' => '18:00', 'valid_hours_end' => '22:00']);
        $this->api('PUT', '/settings', ['enabled' => true, 'theme' => null])->assertOk();
        $this->api('POST', '/banners', $this->banner(['type' => 'flash', 'coupon_code' => 'soir']))->assertCreated();   // préparée le matin
        $h = $this->api('GET')->json('data.draft_hash');
        $this->api('POST', '/publish', ['base_version' => 0, 'draft_hash' => $h])->assertOk();
        $lire = fn () => $this->withHeader('x-api-key', '123456')->getJson('/api/frontend/storefront');

        $lire()->assertJsonPath('banners.0.coupon_code', null);              // 10 h : hors de la plage du code
        Carbon::setTestNow(Carbon::parse('2026-12-01 19:00:00', 'Europe/Paris'));
        $r = $lire()->assertJsonPath('banners.0.coupon_code', 'SOIR');        // 19 h : dans la plage
        $this->assertSame('2026-12-01T22:00', substr((string) $r->json('next_change_at'), 0, 16), 'relire à la fermeture de la plage');

        $soir->forceFill(['surfaces' => ['kiosk']])->save();                  // réservé à la borne APRÈS publication
        $lire()->assertJsonPath('banners.0.coupon_code', null);
        $soir->forceFill(['surfaces' => ['web']])->save();
        config(['pos.coupon_codes_enabled' => false, 'pos.manual_discount_enabled' => false]);   // interrupteur général fermé
        $lire()->assertJsonPath('banners.0.title', 'Bandeau')->assertJsonPath('banners.0.coupon_code', null);
    }

    /** [RED cycle 3 · P2-4] L'application n'affiche le message de livraison que s'il a été SAISI en admin (sinon son texte d'origine). */
    public function test_le_message_de_livraison_dit_s_il_est_personnalise(): void
    {
        $this->owner();
        $this->api('PUT', '/settings', ['enabled' => true, 'theme' => null])->assertOk();
        $h = $this->api('GET')->json('data.draft_hash');
        $this->api('POST', '/publish', ['base_version' => 0, 'draft_hash' => $h])->assertOk();
        $this->withHeader('x-api-key', '123456')->getJson('/api/frontend/storefront')
            ->assertJsonPath('delivery.custom', false)->assertJsonPath('delivery.message', 'Livraison disponible.');
        $this->api('PUT', '/settings', ['enabled' => true, 'theme' => null, 'delivery_on_message' => 'Livraison offerte dès 25 € ce week-end.'])->assertOk();
        $h = $this->api('GET')->json('data.draft_hash');
        $this->api('POST', '/publish', ['base_version' => 1, 'draft_hash' => $h])->assertOk();
        $this->withHeader('x-api-key', '123456')->getJson('/api/frontend/storefront')
            ->assertJsonPath('delivery.custom', true)->assertJsonPath('delivery.message', 'Livraison offerte dès 25 € ce week-end.');
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
