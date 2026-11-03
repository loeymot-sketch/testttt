<?php

namespace Tests\Feature\Storefront;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\StorefrontBanner;
use App\Models\StorefrontSetting;
use App\Models\StorefrontVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [APP HYBRIDE W2a · 2026-11-03] Administration de la vitrine distante.
 *
 * Porte : `permission:settings` (réservé au responsable — même droit que les alertes d'hygiène et les réglages ;
 * les offres utilisent `offers`, module désactivé en V1). Les bandeaux et les couleurs sont un BROUILLON :
 * rien n'atteint l'application avant « Publier », qui écrit une version +1, la garde dans l'historique
 * (`storefront_versions`) et la trace au journal d'audit chaîné (AuditLogService::write, API publique, fichier gelé intact).
 * « Retour à la version précédente » republie l'instantané précédent comme une NOUVELLE version : l'historique
 * ne se réécrit jamais.
 */
class StorefrontAdminTest extends TestCase
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
        Branch::factory()->create(['id' => 2]);
        app(\App\Services\Delivery\DeliveryAvailability::class)->set(true);
        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00', 'Europe/Paris'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(int $branch = 1): User
    {
        $u = User::factory()->create(['branch_id' => $branch, 'name' => "Responsable {$branch}"]);
        $u->givePermissionTo('settings');
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    private function api(string $method, string $uri = '', array $data = [])
    {
        return $this->withHeader('x-api-key', '123456')->json($method, self::BASE.$uri, $data);
    }

    private function bannerPayload(array $over = []): array
    {
        return $over + [
            'title' => 'Offre de Noël', 'body' => 'Un dessert offert avec le code NOEL.', 'type' => 'info',
            'starts_at' => '2026-12-20T00:00', 'ends_at' => '2026-12-26T00:00', 'priority' => 10, 'active' => true,
        ];
    }

    private function publicGet()
    {
        return $this->withHeader('x-api-key', '123456')->getJson('/api/frontend/storefront');
    }

    // ------------------------------------------------------------------ accès

    public function test_sans_authentification_ou_sans_le_droit_settings_c_est_refuse(): void
    {
        $this->api('GET')->assertUnauthorized();

        $cashier = User::factory()->create(['branch_id' => 1]);
        $cashier->givePermissionTo(['pos', 'pos-orders', 'offers']);
        Sanctum::actingAs($cashier, ['*']);
        $this->api('GET')->assertForbidden();
        $this->api('POST', '/banners', $this->bannerPayload())->assertForbidden();
        $this->api('PUT', '/settings', ['enabled' => true])->assertForbidden();
        $this->api('POST', '/publish', ['base_version' => 0])->assertForbidden();
        $this->api('POST', '/rollback', ['base_version' => 0])->assertForbidden();
        $this->assertSame(0, StorefrontBanner::withoutGlobalScopes()->count());
        $this->assertSame(0, StorefrontVersion::withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------ bandeaux

    public function test_un_bandeau_cree_reste_un_brouillon_jusqu_a_la_publication(): void
    {
        $this->owner();
        $r = $this->api('POST', '/banners', $this->bannerPayload(['starts_at' => null, 'ends_at' => null]))->assertCreated();
        $r->assertJsonPath('data.banners.0.title', 'Offre de Noël')->assertJsonPath('data.has_unpublished_changes', true)
            ->assertJsonPath('data.published.version', 0);
        $this->publicGet()->assertJsonPath('banners', [])->assertJsonPath('enabled', false);
    }

    public function test_dates_saisies_en_heure_de_paris_et_validation_en_francais(): void
    {
        $this->owner();
        $ok = $this->api('POST', '/banners', $this->bannerPayload())->assertCreated();
        $this->assertSame('2026-12-20T00:00:00+01:00', $ok->json('data.banners.0.starts_at'));

        $cases = [
            'title'        => [['title' => ''], 'titre'],
            'ends_at'      => [['starts_at' => '2026-12-26T00:00', 'ends_at' => '2026-12-20T00:00'], 'après'],
            'button_link'  => [['button_label' => 'Voir', 'button_link' => 'https://exemple.fr'], 'interne'],
        ];
        foreach ($cases as $field => [$over, $word]) {
            $r = $this->api('POST', '/banners', $this->bannerPayload($over))->assertStatus(422);
            $this->assertStringContainsString($word, $r->json("errors.{$field}.0"), "champ {$field}");
        }

        // Fin déjà passée, offre flash sans fin, lien javascript:, adresse //, balise, code promo inconnu, bouton sans lien.
        $this->api('POST', '/banners', $this->bannerPayload(['starts_at' => null, 'ends_at' => '2026-11-30T23:00']))
            ->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
        $this->api('POST', '/banners', $this->bannerPayload(['type' => 'flash', 'ends_at' => null]))
            ->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
        foreach (['javascript:alert(1)', '//evil.fr', '#paiement', '#menu/<x>', 'menu'] as $bad) {
            $this->api('POST', '/banners', $this->bannerPayload(['button_label' => 'Voir', 'button_link' => $bad]))
                ->assertStatus(422)->assertJsonValidationErrors(['button_link']);
        }
        $html = $this->api('POST', '/banners', $this->bannerPayload(['title' => '<b>Promo</b>']))->assertStatus(422);
        $this->assertStringContainsString('balise', $html->json('errors.title.0'));
        $coupon = $this->api('POST', '/banners', $this->bannerPayload(['coupon_code' => 'INCONNU']))->assertStatus(422);
        $this->assertStringContainsString('code promo', $coupon->json('errors.coupon_code.0'));
        $this->api('POST', '/banners', $this->bannerPayload(['button_label' => 'Voir']))
            ->assertStatus(422)->assertJsonValidationErrors(['button_link']);
        $this->api('POST', '/banners', $this->bannerPayload(['type' => 'promo']))
            ->assertStatus(422)->assertJsonValidationErrors(['type']);

        $this->assertSame(1, StorefrontBanner::query()->count());
    }

    public function test_offre_flash_avec_code_promo_existant_et_lien_interne(): void
    {
        $this->owner();
        Coupon::query()->forceCreate([
            'name' => 'Noël', 'code' => 'NOEL5', 'discount' => 5, 'discount_type' => 10,
            'start_date' => '2026-12-01 00:00:00', 'end_date' => '2026-12-31 23:59:59', 'minimum_order' => 0, 'maximum_discount' => 0, 'limit_per_user' => 1,
        ]);
        $this->api('POST', '/banners', $this->bannerPayload([
            'type' => 'flash', 'coupon_code' => 'noel5', 'button_label' => 'Voir le produit', 'button_link' => '#menu/tacos-xl',
        ]))->assertCreated()->assertJsonPath('data.banners.0.coupon_code', 'NOEL5')
            ->assertJsonPath('data.banners.0.button_link', '#menu/tacos-xl');
    }

    public function test_modifier_et_supprimer_un_bandeau_suppression_douce(): void
    {
        $this->owner();
        $id = $this->api('POST', '/banners', $this->bannerPayload())->json('data.banners.0.id');
        $this->api('PUT', "/banners/{$id}", $this->bannerPayload(['title' => 'Offre du Nouvel An']))->assertOk()
            ->assertJsonPath('data.banners.0.title', 'Offre du Nouvel An');
        $this->api('DELETE', "/banners/{$id}")->assertOk()->assertJsonPath('data.banners', []);
        $this->assertSame(1, StorefrontBanner::withTrashed()->count());
    }

    // ------------------------------------------------------------------ publication, journal, concurrence

    public function test_publier_ecrit_la_version_suivante_l_historique_et_le_journal_d_audit(): void
    {
        $owner = $this->owner();
        $this->api('PUT', '/settings', ['enabled' => true, 'theme' => config('storefront.default_theme')])->assertOk();
        $this->api('POST', '/banners', $this->bannerPayload(['starts_at' => null]))->assertCreated();

        $r = $this->api('POST', '/publish', ['base_version' => 0])->assertOk()
            ->assertJsonPath('data.published.version', 1)
            ->assertJsonPath('data.published.published_by', 'Responsable 1')
            ->assertJsonPath('data.has_unpublished_changes', false);
        $this->assertSame(1, $r->json('data.history.0.version'));

        $this->publicGet()->assertJsonPath('version', 1)->assertJsonPath('banners.0.title', 'Offre de Noël');

        $v = StorefrontVersion::query()->where('branch_id', 1)->where('version', 1)->firstOrFail();
        $this->assertSame($owner->id, $v->published_by);
        $this->assertSame('Offre de Noël', $v->snapshot['banners'][0]['title']);

        $audit = AuditLog::query()->where('action', 'storefront.published')->firstOrFail();
        $this->assertSame(1, $audit->branch_id);
        $this->assertSame($owner->id, $audit->user_id);
        $this->assertSame(0, $audit->payload['from_version']);
        $this->assertSame(1, $audit->payload['to_version']);
        $this->assertNull($audit->payload['before']);
        $this->assertSame('Offre de Noël', $audit->payload['after']['banners'][0]['title']);

        // Double clic / deuxième administrateur : la base annoncée n'est plus la version courante → 409, rien d'écrit.
        $stale = $this->api('POST', '/publish', ['base_version' => 0])->assertStatus(409);
        $this->assertStringContainsString('Une autre publication', $stale->json('message'));
        $this->assertSame(1, StorefrontVersion::query()->count());
    }

    public function test_publication_refusee_si_le_theme_enregistre_n_est_plus_lisible(): void
    {
        $this->owner();
        // Valeur glissée hors du formulaire (base modifiée à la main) : la publication revalide tout.
        StorefrontSetting::query()->create(['branch_id' => 1, 'enabled' => true, 'theme' => ['text' => '#FFFFFF'] + config('storefront.default_theme')]);
        $r = $this->api('POST', '/publish', ['base_version' => 0])->assertStatus(422);
        $this->assertStringContainsString('Contraste insuffisant', $r->json('errors')['theme.text'][0]);
        $this->assertSame(0, StorefrontVersion::query()->count());
    }

    public function test_desactiver_puis_publier_eteint_la_vitrine(): void
    {
        $this->owner();
        $this->api('PUT', '/settings', ['enabled' => true, 'theme' => config('storefront.default_theme')]);
        $this->api('POST', '/banners', $this->bannerPayload(['starts_at' => null]));
        $this->api('POST', '/publish', ['base_version' => 0])->assertOk();
        $this->publicGet()->assertJsonPath('enabled', true)->assertJsonCount(1, 'banners');

        $this->api('PUT', '/settings', ['enabled' => false, 'theme' => config('storefront.default_theme')])->assertOk();
        $this->api('POST', '/publish', ['base_version' => 1])->assertOk();
        $this->publicGet()->assertJsonPath('version', 2)->assertJsonPath('enabled', false)
            ->assertJsonPath('theme', null)->assertJsonPath('banners', []);
    }

    public function test_retour_a_la_version_precedente_republie_et_garde_l_historique(): void
    {
        $this->owner();
        $this->api('PUT', '/settings', ['enabled' => true, 'theme' => config('storefront.default_theme')]);
        $a = $this->api('POST', '/banners', $this->bannerPayload(['title' => 'Version A', 'starts_at' => null]))->json('data.banners.0.id');
        $this->api('POST', '/publish', ['base_version' => 0])->assertOk();

        $this->api('PUT', "/banners/{$a}", $this->bannerPayload(['title' => 'Version B ratée', 'starts_at' => null]));
        $this->api('PUT', '/settings', ['enabled' => true, 'theme' => ['primary' => '#C2410C', 'on_primary' => '#FFFFFF'] + config('storefront.default_theme')])->assertOk();
        $this->api('POST', '/publish', ['base_version' => 1])->assertOk();
        $this->publicGet()->assertJsonPath('banners.0.title', 'Version B ratée')->assertJsonPath('theme.primary', '#C2410C');

        $r = $this->api('POST', '/rollback', ['base_version' => 2])->assertOk()
            ->assertJsonPath('data.published.version', 3)
            ->assertJsonPath('data.has_unpublished_changes', false)
            ->assertJsonPath('data.banners.0.title', 'Version A')     // le brouillon revient aussi : « Publier » ne repousse pas la version ratée
            ->assertJsonPath('data.draft.theme.primary', '#FF5A1F');
        $this->assertSame([3, 2, 1], array_column($r->json('data.history'), 'version'));
        $this->assertSame('rollback', $r->json('data.history.0.action'));
        $this->assertSame(1, $r->json('data.history.0.source_version'));

        $this->publicGet()->assertJsonPath('version', 3)->assertJsonPath('banners.0.title', 'Version A')->assertJsonPath('theme.primary', '#FF5A1F');
        $this->assertSame(3, StorefrontVersion::query()->count());
        $audit = AuditLog::query()->where('action', 'storefront.rolled_back')->firstOrFail();
        $this->assertSame(['from_version' => 2, 'to_version' => 3, 'restored_version' => 1], array_intersect_key($audit->payload, array_flip(['from_version', 'to_version', 'restored_version'])));
    }

    public function test_retour_arriere_impossible_sans_version_precedente(): void
    {
        $this->owner();
        $r = $this->api('POST', '/rollback', ['base_version' => 0])->assertStatus(422);
        $this->assertStringContainsString('Aucune version précédente', $r->json('message'));
        $this->api('POST', '/publish', ['base_version' => 0])->assertOk();
        $this->api('POST', '/rollback', ['base_version' => 1])->assertStatus(422);
    }

    public function test_apres_publication_rien_n_est_en_attente_meme_si_mysql_reordonne_les_cles_json(): void
    {
        // Constaté en navigateur sur MySQL le 2026-11-03 : une colonne JSON MySQL TRIE les clés des objets
        // ({"id","body","type","title",…}). Comparer des chaînes JSON affichait « Modifications non publiées »
        // juste après « Publier ». SQLite garde l'ordre : on simule donc la réécriture de MySQL.
        $this->owner();
        $this->api('PUT', '/settings', ['enabled' => true, 'theme' => config('storefront.default_theme')]);
        $this->api('POST', '/banners', $this->bannerPayload(['starts_at' => null]));
        $this->api('POST', '/publish', ['base_version' => 0])->assertOk();

        $row = \Illuminate\Support\Facades\DB::table('storefront_versions')->where('version', 1)->first();
        $ksortDeep = function ($v) use (&$ksortDeep) {
            if (! is_array($v)) {
                return $v;
            }
            $v = array_map($ksortDeep, $v);
            if (array_keys($v) !== range(0, count($v) - 1)) {
                uksort($v, fn ($a, $b) => [strlen($a), $a] <=> [strlen($b), $b]); // ordre de MySQL : longueur puis alphabet
            }

            return $v;
        };
        \Illuminate\Support\Facades\DB::table('storefront_versions')->where('id', $row->id)
            ->update(['snapshot' => json_encode($ksortDeep(json_decode($row->snapshot, true)))]);

        $this->api('GET')->assertOk()->assertJsonPath('data.has_unpublished_changes', false);
    }

    // ------------------------------------------------------------------ branche

    public function test_isolation_par_branche(): void
    {
        $this->owner(1);
        $id1 = $this->api('POST', '/banners', $this->bannerPayload(['title' => 'Cayenne', 'starts_at' => null]))->json('data.banners.0.id');
        $this->api('PUT', '/settings', ['enabled' => true, 'theme' => null]);
        $this->api('POST', '/publish', ['base_version' => 0])->assertOk();

        $this->owner(2); // responsable d'une AUTRE branche, même droit
        $this->api('GET')->assertOk()->assertJsonPath('data.banners', [])->assertJsonPath('data.published.version', 0);
        $this->api('PUT', "/banners/{$id1}", $this->bannerPayload(['title' => 'Piraté']))->assertNotFound();
        $this->api('DELETE', "/banners/{$id1}")->assertNotFound();
        $this->api('POST', '/banners', $this->bannerPayload(['title' => 'Branche 2', 'starts_at' => null]))->assertCreated();
        $this->api('PUT', '/settings', ['enabled' => true, 'theme' => null]);
        $this->api('POST', '/publish', ['base_version' => 0])->assertOk()->assertJsonPath('data.published.version', 1);

        $this->assertSame('Cayenne', StorefrontBanner::withoutGlobalScopes()->find($id1)->title);
        $this->publicGet()->assertJsonPath('banners.0.title', 'Cayenne')->assertJsonCount(1, 'banners'); // le site sert la branche 1
    }

    public function test_l_administrateur_sans_branche_travaille_sur_la_branche_du_site(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->givePermissionTo('settings');
        Sanctum::actingAs($admin, ['*']);
        $this->api('POST', '/banners', $this->bannerPayload(['starts_at' => null]))->assertCreated();
        $this->assertSame(1, StorefrontBanner::withoutGlobalScopes()->first()->branch_id);
    }

    // ------------------------------------------------------------------ aperçu = ce que l'app recevra

    public function test_l_apercu_est_construit_par_le_meme_code_que_l_api_publique(): void
    {
        $this->owner();
        $this->api('PUT', '/settings', ['enabled' => true, 'theme' => config('storefront.default_theme'), 'delivery_on_message' => 'Livraison lancée !']);
        $this->api('POST', '/banners', $this->bannerPayload(['starts_at' => null]));
        $preview = $this->api('GET')->assertOk()->json('data.preview');
        $this->api('POST', '/publish', ['base_version' => 0]);
        $public = $this->publicGet()->json();

        unset($preview['version'], $public['version'], $preview['published_at'], $public['published_at']);
        $this->assertSame($public, $preview);
        $this->assertSame('Livraison lancée !', $public['delivery']['message']);
    }

    public function test_les_meta_de_l_ecran_viennent_du_serveur(): void
    {
        $this->owner();
        $meta = $this->api('GET')->assertOk()->json('data.meta');
        $this->assertSame(array_keys(config('storefront.theme_tokens')), array_column($meta['tokens'], 'key'));
        $this->assertEquals(4.5, $meta['min_contrast']);
        $this->assertContains('menu', array_column($meta['link_routes'], 'route'));
        $this->assertSame('Europe/Paris', $meta['timezone']);
    }
}
