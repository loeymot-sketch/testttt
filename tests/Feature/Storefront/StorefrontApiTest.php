<?php

namespace Tests\Feature\Storefront;

use App\Models\Branch;
use App\Models\StorefrontBanner;
use App\Models\StorefrontSetting;
use App\Services\Delivery\DeliveryAvailability;
use App\Services\Storefront\StorefrontService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * [APP HYBRIDE W2a · 2026-11-03] Vitrine distante — lecture PUBLIQUE par l'application et le site.
 *
 * Contrat (`GET /api/frontend/storefront`) :
 *   · rien de publié ⇒ vitrine éteinte : l'application garde son contenu embarqué ;
 *   · on ne sert QUE la dernière version PUBLIÉE (un brouillon en cours n'apparaît jamais) ;
 *   · bandeaux filtrés par le serveur, à l'heure de Paris, triés par priorité (plus grand = en premier) ;
 *   · vitrine désactivée ⇒ bandeaux vides et thème nul ;
 *   · livraison = interrupteur existant `order_setup_delivery`, relu en direct (jamais figé dans une version) ;
 *   · ETag + 304 : l'application peut relire souvent sans retélécharger ;
 *   · seule la branche du site est servie.
 */
class StorefrontApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/frontend/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        config(['app.api_key' => '123456']);
        Branch::factory()->create(['id' => 1]);
        Branch::factory()->create(['id' => 2]);
        app(DeliveryAvailability::class)->set(true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lire(array $headers = [])
    {
        return $this->withHeaders(['x-api-key' => '123456'] + $headers)->getJson(self::URL);
    }

    private function settings(int $branch = 1, array $over = []): StorefrontSetting
    {
        return StorefrontSetting::query()->updateOrCreate(['branch_id' => $branch], $over + [
            'enabled' => true,
            'theme'   => config('storefront.default_theme'),
        ]);
    }

    private function banner(array $over = [], int $branch = 1): StorefrontBanner
    {
        return StorefrontBanner::query()->create($over + [
            'branch_id' => $branch,
            'title'     => 'Bandeau',
            'type'      => 'info',
            'priority'  => 0,
            'active'    => true,
        ]);
    }

    private function publish(int $branch = 1): void
    {
        $current = (int) (StorefrontSetting::query()->where('branch_id', $branch)->value('version') ?? 0);
        app(StorefrontService::class)->publish($branch, null, $current);
    }

    public function test_sans_publication_la_vitrine_est_eteinte_et_la_livraison_est_lue_en_direct(): void
    {
        $this->lire()->assertOk()
            ->assertJsonPath('version', 0)
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('theme', null)
            ->assertJsonPath('banners', [])
            ->assertJsonPath('delivery.enabled', true)
            ->assertJsonPath('delivery.message', 'Livraison disponible.');
    }

    public function test_une_version_publiee_sert_theme_et_bandeaux_actifs_tries_par_priorite(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-20 12:00:00', 'Europe/Paris'));
        $this->settings();
        $this->banner(['title' => 'Petit', 'priority' => 5]);
        $this->banner(['title' => 'Grand', 'priority' => 10, 'type' => 'flash', 'coupon_code' => null,
            'button_label' => 'Voir la carte', 'button_link' => '#menu',
            'ends_at' => Carbon::parse('2026-12-24 23:00', 'Europe/Paris')]);
        $this->banner(['title' => 'Éteint', 'priority' => 99, 'active' => false]);
        $this->banner(['title' => 'Futur', 'priority' => 99, 'starts_at' => Carbon::parse('2026-12-21 00:00', 'Europe/Paris')]);
        $this->banner(['title' => 'Fini', 'priority' => 99, 'ends_at' => Carbon::parse('2026-12-19 00:00', 'Europe/Paris')]);
        $this->publish();

        $r = $this->lire()->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('theme.primary', '#FF5A1F')
            ->assertJsonPath('theme.banner_text', '#FFD93D');

        $this->assertSame(['Grand', 'Petit'], array_column($r->json('banners'), 'title'));
        $grand = $r->json('banners.0');
        $this->assertSame('flash', $grand['type']);
        $this->assertSame(['label' => 'Voir la carte', 'link' => '#menu'], $grand['button']);
        $this->assertSame('2026-12-24T23:00:00+01:00', $grand['ends_at']);
        $this->assertNull($r->json('banners.1.button'));
        // Aucune clé de thème hors liste blanche, aucune valeur qui ne soit pas une couleur.
        $this->assertSame(array_keys(config('storefront.theme_tokens')), array_keys($r->json('theme')));
        foreach ($r->json('theme') as $v) {
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $v);
        }
        // Le prochain changement visible (début du bandeau « Futur ») est annoncé, pour que l'app relise à temps.
        $this->assertSame('2026-12-21T00:00:00+01:00', $r->json('next_change_at'));
    }

    public function test_le_filtre_de_dates_suit_l_heure_de_paris_debut_inclus_fin_exclue(): void
    {
        $this->settings();
        $this->banner([
            'title' => 'Réveillon',
            'starts_at' => Carbon::parse('2026-12-24 18:00:00', 'Europe/Paris'),
            'ends_at'   => Carbon::parse('2026-12-24 23:00:00', 'Europe/Paris'),
        ]);
        $this->publish();

        Carbon::setTestNow(Carbon::parse('2026-12-24 17:59:59', 'Europe/Paris'));
        $this->assertSame([], $this->lire()->json('banners'));

        Carbon::setTestNow(Carbon::parse('2026-12-24 17:00:00', 'UTC')); // = 18:00 à Paris (UTC+1 en hiver)
        $this->assertSame(['Réveillon'], array_column($this->lire()->json('banners'), 'title'));

        Carbon::setTestNow(Carbon::parse('2026-12-24 22:59:59', 'Europe/Paris'));
        $this->assertCount(1, $this->lire()->json('banners'));

        Carbon::setTestNow(Carbon::parse('2026-12-24 23:00:00', 'Europe/Paris'));
        $this->assertSame([], $this->lire()->json('banners'));
    }

    public function test_etag_et_304_puis_nouvel_etag_quand_la_livraison_bascule(): void
    {
        $this->settings();
        $this->banner(['title' => 'Noël']);
        $this->publish();

        $first = $this->lire()->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertStringContainsString('must-revalidate', (string) $first->headers->get('Cache-Control'));

        $again = $this->lire(['If-None-Match' => $etag]);
        $again->assertStatus(304);
        $this->assertSame('', $again->getContent());
        $this->assertSame($etag, $again->headers->get('ETag'));
        $this->lire(['If-None-Match' => 'W/'.$etag])->assertStatus(304);   // forme faible acceptée
        $this->lire(['If-None-Match' => '"autre", '.$etag])->assertStatus(304); // liste acceptée

        app(DeliveryAvailability::class)->set(false);
        $off = $this->lire(['If-None-Match' => $etag])->assertOk()
            ->assertJsonPath('delivery.enabled', false)
            ->assertJsonPath('delivery.message', 'La livraison est momentanément suspendue. Vous pouvez commander à emporter.');
        $this->assertNotSame($etag, $off->headers->get('ETag'));
    }

    public function test_une_vitrine_desactivee_publiee_vide_les_bandeaux_et_le_theme(): void
    {
        $this->settings(1, ['enabled' => false, 'delivery_on_message' => 'Livraison lancée !']);
        $this->banner(['title' => 'Ne doit pas sortir']);
        $this->publish();

        $this->lire()->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('theme', null)
            ->assertJsonPath('banners', [])
            // Le message personnalisé fait partie de la vitrine : éteinte, on revient au texte par défaut.
            ->assertJsonPath('delivery.message', 'Livraison disponible.');
    }

    public function test_message_de_livraison_personnalise_quand_la_vitrine_est_active(): void
    {
        $this->settings(1, ['delivery_on_message' => 'Livraison lancée à Hénin-Beaumont !', 'delivery_off_message' => 'Pas de livraison ce soir.']);
        $this->publish();
        $this->lire()->assertJsonPath('delivery.message', 'Livraison lancée à Hénin-Beaumont !');
        app(DeliveryAvailability::class)->set(false);
        $this->lire()->assertJsonPath('delivery.message', 'Pas de livraison ce soir.');
    }

    public function test_seule_la_version_publiee_est_servie_jamais_le_brouillon(): void
    {
        $this->settings();
        $b = $this->banner(['title' => 'Publié']);
        $this->publish();

        $b->update(['title' => 'Brouillon']);
        $this->banner(['title' => 'Nouveau brouillon']);
        StorefrontSetting::query()->where('branch_id', 1)->update(['enabled' => false]);

        $this->lire()->assertJsonPath('enabled', true)->assertJsonPath('banners.0.title', 'Publié')->assertJsonCount(1, 'banners');
    }

    public function test_les_bandeaux_d_une_autre_branche_ne_sont_jamais_servis(): void
    {
        $this->settings(1);
        $this->banner(['title' => 'Cayenne'], 1);
        $this->publish(1);
        $this->settings(2);
        $this->banner(['title' => 'Autre restaurant', 'priority' => 50], 2);
        $this->publish(2);

        $this->assertSame(['Cayenne'], array_column($this->lire()->json('banners'), 'title'));
    }

    public function test_un_client_connecte_d_une_autre_branche_recoit_quand_meme_la_vitrine_du_site(): void
    {
        // Le site et l'application envoient le jeton du client connecté : la lecture publique ne doit pas dépendre du BranchScope.
        $this->settings(1);
        $this->banner(['title' => 'Cayenne'], 1);
        $this->publish(1);
        $client = \App\Models\User::factory()->create(['branch_id' => 2]);
        \Laravel\Sanctum\Sanctum::actingAs($client, ['*']);

        $this->assertSame(['Cayenne'], array_column($this->lire()->json('banners'), 'title'));
    }

    public function test_une_version_publiee_ne_se_reecrit_pas(): void
    {
        $this->settings(1);
        $this->publish(1);
        $v = \App\Models\StorefrontVersion::query()->firstOrFail();
        $this->assertFalse($v->update(['snapshot' => ['enabled' => false]]));
        $this->assertFalse($v->delete());
        $this->assertTrue(\App\Models\StorefrontVersion::query()->first()->snapshot['enabled']);
    }

    public function test_l_etag_est_lisible_depuis_l_application_d_une_autre_origine(): void
    {
        // L'application des stores tourne en https://localhost (autre origine) : sans Access-Control-Expose-Headers,
        // son JavaScript ne voit pas l'ETag et ne peut jamais envoyer If-None-Match (chaque lecture retélécharge tout).
        $this->settings();
        $this->publish();
        $origin = 'http://localhost:8011'; // origine locale déjà autorisée (même que CorsExposeRetryAfterTest)
        $r = $this->withHeaders(['x-api-key' => '123456', 'Origin' => $origin])->getJson(self::URL)->assertOk();
        $r->assertHeader('Access-Control-Allow-Origin', $origin);
        $this->assertStringContainsStringIgnoringCase('ETag', (string) $r->headers->get('Access-Control-Expose-Headers'));
    }

    public function test_route_publique_cle_api_et_compteur_propre(): void
    {
        $this->getJson(self::URL)->assertStatus(400); // sans x-api-key : refusé comme les autres routes frontend

        $route = Route::getRoutes()->getByName('frontend.storefront');
        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('throttle:300,1,storefront', $route->gatherMiddleware());
        $this->assertNotContains('auth:sanctum', $route->gatherMiddleware());
    }
}
