<?php

namespace Tests\Feature\Storefront;

use App\Models\Branch;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Delivery\DeliveryAvailability;
use App\Services\Storefront\StorefrontService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [GOAL LIVRAISON-TICKETS 2026-11-10 · T-3.1.1 / T-3.1.2] « Design d'un jour » : un thème de couleurs programmé s'applique
 * seul entre ses deux dates (heure de Paris, début inclus, fin exclue), remplace le thème de base pendant ce temps, se
 * défait seul à la fin ; l'application relit exactement à ces heures (`next_change_at`). Les couleurs obéissent aux mêmes
 * règles de contraste ; un thème illisible n'est ni enregistré ni publié ; un retour arrière remet les thèmes de la version.
 */
class StorefrontThemePlanifieTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/frontend/storefront';

    // Rouge de Noël, texte blanc dessus (5,9:1), accent doré lisible sur le noir fixe de l'application (≥ 4,5:1).
    private const NOEL = [
        'primary' => '#B91C1C', 'on_primary' => '#FFFFFF', 'accent' => '#FDE68A', 'background' => '#FFF7ED',
        'surface' => '#FFFFFF', 'text' => '#111827', 'banner_bg' => '#14532D', 'banner_text' => '#FFFFFF',
    ];

    // Texte blanc sur bouton blanc : refusé par la paire on_primary / primary.
    private const ILLISIBLE = [
        'primary' => '#FFFFFF', 'on_primary' => '#FFFFFF', 'accent' => '#FDE68A', 'background' => '#FFF7ED',
        'surface' => '#FFFFFF', 'text' => '#111827', 'banner_bg' => '#14532D', 'banner_text' => '#FFFFFF',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        config(['app.api_key' => '123456']);
        Branch::factory()->create(['id' => 1]);
        app(DeliveryAvailability::class)->set(true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lire()
    {
        return $this->withHeaders(['x-api-key' => '123456'])->getJson(self::URL);
    }

    private function admin(): User
    {
        $this->seedSpatieRoles();
        Permission::findOrCreate('settings', 'sanctum');
        $u = User::factory()->create(['branch_id' => 0]);
        $u->givePermissionTo('settings');

        return $u;
    }

    private function enAdmin(User $u)
    {
        return $this->actingAs($u, 'sanctum')->withHeader('x-api-key', '123456')->withHeader('X-Idempotency-Key', (string) Str::uuid());
    }

    private function publish(int $branch = 1): void
    {
        $current = (int) (StorefrontSetting::query()->where('branch_id', $branch)->value('version') ?? 0);
        app(StorefrontService::class)->publish($branch, null, $current);
    }

    private function theme(array $over = []): array
    {
        return $over + [
            'id' => 1, 'name' => 'Noël', 'priority' => 10, 'active' => true,
            'starts_at' => '2026-12-24T18:00', 'ends_at' => '2026-12-26T00:00', 'theme' => self::NOEL,
        ];
    }

    public function test_le_theme_programme_remplace_les_couleurs_entre_ses_dates_puis_le_theme_de_base_reprend_seul(): void
    {
        StorefrontSetting::query()->create(['branch_id' => 1, 'enabled' => true, 'theme' => config('storefront.default_theme'),
            'scheduled_themes' => [$this->theme()]]);
        $this->publish();

        // La veille : couleurs de base, prochaine relecture = début du thème (heure de Paris).
        Carbon::setTestNow(Carbon::parse('2026-12-23 12:00:00', 'Europe/Paris'));
        $this->lire()->assertOk()->assertJsonPath('theme.primary', '#FF5A1F')->assertJsonPath('theme_name', null)
            ->assertJsonPath('next_change_at', '2026-12-24T18:00:00+01:00');

        // À 18 h 00 pile (début inclus) : Noël, prochaine relecture = fin.
        Carbon::setTestNow(Carbon::parse('2026-12-24 18:00:00', 'Europe/Paris'));
        $r = $this->lire()->assertOk()->assertJsonPath('theme.primary', '#B91C1C')->assertJsonPath('theme_name', 'Noël')
            ->assertJsonPath('next_change_at', '2026-12-26T00:00:00+01:00');
        $this->assertSame(array_keys(config('storefront.theme_tokens')), array_keys($r->json('theme')));

        // À la fin (exclue) : le thème de base reprend sans qu'on touche à rien, plus rien à attendre.
        Carbon::setTestNow(Carbon::parse('2026-12-26 00:00:00', 'Europe/Paris'));
        $this->lire()->assertOk()->assertJsonPath('theme.primary', '#FF5A1F')->assertJsonPath('theme_name', null)
            ->assertJsonPath('next_change_at', null);
    }

    public function test_un_theme_programme_s_applique_meme_quand_le_restaurant_garde_les_couleurs_embarquees(): void
    {
        StorefrontSetting::query()->create(['branch_id' => 1, 'enabled' => true, 'theme' => null, 'scheduled_themes' => [$this->theme()]]);
        $this->publish();

        Carbon::setTestNow(Carbon::parse('2026-12-25 12:00:00', 'Europe/Paris'));
        $this->lire()->assertOk()->assertJsonPath('theme.primary', '#B91C1C')->assertJsonPath('theme_name', 'Noël');
        Carbon::setTestNow(Carbon::parse('2026-12-27 12:00:00', 'Europe/Paris'));
        $this->lire()->assertOk()->assertJsonPath('theme', null);
    }

    public function test_chevauchement_la_plus_grande_priorite_gagne_et_les_bornes_des_deux_nourrissent_next_change_at(): void
    {
        $fetes = $this->theme(['id' => 1, 'name' => 'Fêtes', 'priority' => 1, 'starts_at' => '2026-12-20T00:00', 'ends_at' => '2027-01-03T00:00']);
        $reveillon = $this->theme(['id' => 2, 'name' => 'Réveillon', 'priority' => 5, 'starts_at' => '2026-12-31T18:00', 'ends_at' => '2027-01-01T06:00',
            'theme' => ['primary' => '#1D4ED8'] + self::NOEL]);
        $eteint = $this->theme(['id' => 3, 'name' => 'Éteint', 'priority' => 99, 'active' => false, 'starts_at' => '2026-12-20T00:00', 'ends_at' => '2027-01-03T00:00']);
        StorefrontSetting::query()->create(['branch_id' => 1, 'enabled' => true, 'theme' => null, 'scheduled_themes' => [$fetes, $reveillon, $eteint]]);
        $this->publish();

        Carbon::setTestNow(Carbon::parse('2026-12-31 12:00:00', 'Europe/Paris'));
        $this->lire()->assertOk()->assertJsonPath('theme_name', 'Fêtes')->assertJsonPath('next_change_at', '2026-12-31T18:00:00+01:00');
        Carbon::setTestNow(Carbon::parse('2026-12-31 23:00:00', 'Europe/Paris'));
        $this->lire()->assertOk()->assertJsonPath('theme_name', 'Réveillon')->assertJsonPath('theme.primary', '#1D4ED8')
            ->assertJsonPath('next_change_at', '2027-01-01T06:00:00+01:00');
        Carbon::setTestNow(Carbon::parse('2027-01-01 06:00:00', 'Europe/Paris'));
        $this->lire()->assertOk()->assertJsonPath('theme_name', 'Fêtes')->assertJsonPath('next_change_at', '2027-01-03T00:00:00+01:00');
    }

    public function test_vitrine_eteinte_aucun_theme_programme_n_est_servi(): void
    {
        StorefrontSetting::query()->create(['branch_id' => 1, 'enabled' => false, 'theme' => null, 'scheduled_themes' => [$this->theme()]]);
        $this->publish();
        Carbon::setTestNow(Carbon::parse('2026-12-25 12:00:00', 'Europe/Paris'));
        $this->lire()->assertOk()->assertJsonPath('theme', null)->assertJsonPath('theme_name', null)->assertJsonPath('next_change_at', null);
    }

    public function test_l_admin_enregistre_un_theme_programme_et_le_voit_avec_son_etat(): void
    {
        $admin = $this->admin();
        Carbon::setTestNow(Carbon::parse('2026-12-01 12:00:00', 'Europe/Paris'));
        $r = $this->enAdmin($admin)->putJson('/api/admin/storefront/settings', [
            'enabled' => true, 'theme' => null, 'delivery_on_message' => null, 'delivery_off_message' => null,
            'scheduled_themes' => [$this->theme()],
        ])->assertOk();
        $t = $r->json('data.draft.scheduled_themes.0');
        $this->assertSame('Noël', $t['name']);
        $this->assertSame('2026-12-24T18:00:00+01:00', $t['starts_at'], 'saisie sans fuseau = heure de Paris');
        $this->assertSame('scheduled', $t['state']);
        $this->assertTrue($r->json('data.has_unpublished_changes'));
        $this->assertSame(12, $r->json('data.meta.max_scheduled_themes'));
        // L'aperçu du brouillon est construit par le même code que l'API publique : pas encore Noël le 1er décembre.
        $this->assertNull($r->json('data.preview.theme_name'));
        $this->assertSame('2026-12-24T18:00:00+01:00', $r->json('data.preview.next_change_at'));
    }

    public function test_refus_en_francais_nom_dates_fin_passee_et_contraste(): void
    {
        $admin = $this->admin();
        Carbon::setTestNow(Carbon::parse('2026-12-01 12:00:00', 'Europe/Paris'));
        $base = ['enabled' => true, 'theme' => null, 'delivery_on_message' => null, 'delivery_off_message' => null];

        $r = $this->enAdmin($admin)->putJson('/api/admin/storefront/settings', $base + ['scheduled_themes' => [
            $this->theme(['name' => '', 'starts_at' => '2026-12-26T00:00', 'ends_at' => '2026-12-24T18:00']),
            $this->theme(['id' => 2, 'name' => 'Passé', 'starts_at' => '2026-11-01T00:00', 'ends_at' => '2026-11-02T00:00']),
            $this->theme(['id' => 3, 'name' => 'Pâle', 'theme' => self::ILLISIBLE]),
            $this->theme(['id' => 4, 'name' => 'Sans couleurs', 'theme' => []]),
        ]])->assertStatus(422);
        $e = $r->json('errors');
        $this->assertStringContainsString('nom', $e['scheduled_themes.0.name'][0]);
        $this->assertSame('La fin doit être après le début.', $e['scheduled_themes.0.ends_at'][0]);
        $this->assertStringContainsString('déjà passée', $e['scheduled_themes.1.ends_at'][0]);
        $this->assertStringContainsString('Contraste insuffisant', $e['scheduled_themes.2.theme.on_primary'][0]);
        $this->assertStringContainsString('couleurs', $e['scheduled_themes.3.theme'][0]);
        $this->assertNull(StorefrontSetting::query()->where('branch_id', 1)->first()?->scheduled_themes, 'rien n\'est écrit sur un refus');

        // Un thème terminé mais DÉSACTIVÉ reste enregistrable (on garde l'historique sans le rejouer).
        $this->enAdmin($admin)->putJson('/api/admin/storefront/settings', $base + ['scheduled_themes' => [
            $this->theme(['id' => 2, 'name' => 'Passé', 'active' => false, 'starts_at' => '2026-11-01T00:00', 'ends_at' => '2026-11-02T00:00']),
        ]])->assertOk()->assertJsonPath('data.draft.scheduled_themes.0.state', 'off');
    }

    public function test_la_publication_refuse_un_theme_programme_devenu_illisible_en_base(): void
    {
        $s = StorefrontSetting::query()->create(['branch_id' => 1, 'enabled' => true, 'theme' => null, 'scheduled_themes' => [$this->theme()]]);
        $s->forceFill(['scheduled_themes' => [$this->theme(['theme' => self::ILLISIBLE])]])->save();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->publish();
    }

    public function test_le_retour_arriere_remet_les_themes_programmes_de_la_version_republiee(): void
    {
        StorefrontSetting::query()->create(['branch_id' => 1, 'enabled' => true, 'theme' => null, 'scheduled_themes' => [$this->theme()]]);
        $this->publish(); // v1 : Noël
        StorefrontSetting::query()->where('branch_id', 1)->update(['scheduled_themes' => json_encode([])]);
        $this->publish(); // v2 : plus rien

        $service = app(StorefrontService::class);
        $this->assertFalse($service->hasUnpublishedChanges(1));
        $service->rollback(1, null, 2, 1); // v3 = v1 republiée

        $this->assertSame('Noël', StorefrontSetting::query()->where('branch_id', 1)->first()->scheduled_themes[0]['name']);
        Carbon::setTestNow(Carbon::parse('2026-12-25 12:00:00', 'Europe/Paris'));
        $this->lire()->assertOk()->assertJsonPath('version', 3)->assertJsonPath('theme_name', 'Noël');
        $this->assertFalse($service->hasUnpublishedChanges(1), 'après un retour, brouillon = version publiée');
    }

    // ── Supervision 2026-11-10 : ce que la relecture adverse a trouvé ──────────────────────────────────────────────────────

    /** Un Noël passé, déjà enregistré tel quel, ne bloque pas en janvier l'enregistrement d'un simple message de livraison. */
    public function test_un_theme_termine_deja_enregistre_ne_bloque_pas_les_enregistrements_suivants(): void
    {
        $admin = $this->admin();
        $base = ['enabled' => true, 'theme' => null, 'delivery_off_message' => null];
        Carbon::setTestNow(Carbon::parse('2026-12-01 12:00:00', 'Europe/Paris'));
        $this->enAdmin($admin)->putJson('/api/admin/storefront/settings', $base + ['delivery_on_message' => null, 'scheduled_themes' => [$this->theme()]])->assertOk();

        Carbon::setTestNow(Carbon::parse('2027-01-03 12:00:00', 'Europe/Paris'));
        // L'écran renvoie toujours la liste entière : le Noël terminé y est, inchangé.
        $r = $this->enAdmin($admin)->putJson('/api/admin/storefront/settings', $base + ['delivery_on_message' => 'Bonne année !', 'scheduled_themes' => [$this->theme()]])->assertOk();
        $this->assertSame('Bonne année !', $r->json('data.draft.delivery_on_message'));
        $this->assertSame('ended', $r->json('data.draft.scheduled_themes.0.state'));

        // Un NOUVEAU thème déjà terminé, ou un thème existant dont on recule la fin dans le passé : toujours refusés.
        $r = $this->enAdmin($admin)->putJson('/api/admin/storefront/settings', $base + ['delivery_on_message' => null, 'scheduled_themes' => [
            $this->theme(), $this->theme(['id' => 9, 'name' => 'Trop tard', 'starts_at' => '2026-11-01T00:00', 'ends_at' => '2026-11-02T00:00']),
        ]])->assertStatus(422);
        $this->assertArrayHasKey('scheduled_themes.1.ends_at', $r->json('errors'));
        $this->assertArrayNotHasKey('scheduled_themes.0.ends_at', $r->json('errors'));
        $r = $this->enAdmin($admin)->putJson('/api/admin/storefront/settings', $base + ['delivery_on_message' => null, 'scheduled_themes' => [
            $this->theme(['ends_at' => '2026-12-25T00:00']),
        ]])->assertStatus(422);
        $this->assertArrayHasKey('scheduled_themes.0.ends_at', $r->json('errors'));
    }

    /** `null` ne vide pas la liste ; identifiants dupliqués renumérotés ; 13 thèmes et une couleur illisible refusés. */
    public function test_null_garde_la_liste_doublons_renumerotes_plafond_et_hex_refuses(): void
    {
        $admin = $this->admin();
        Carbon::setTestNow(Carbon::parse('2026-12-01 12:00:00', 'Europe/Paris'));
        $base = ['enabled' => true, 'theme' => null, 'delivery_on_message' => null, 'delivery_off_message' => null];

        $r = $this->enAdmin($admin)->putJson('/api/admin/storefront/settings', $base + ['scheduled_themes' => [
            $this->theme(['id' => 1, 'name' => 'A']), $this->theme(['id' => 1, 'name' => 'B']), $this->theme(['id' => 0, 'name' => 'C']),
        ]])->assertOk();
        $ids = array_column($r->json('data.draft.scheduled_themes'), 'id');
        $this->assertCount(3, array_unique($ids), 'identifiants dupliqués conservés : l\'écran modifierait le mauvais thème');
        $this->assertSame(['A', 'B', 'C'], array_column($r->json('data.draft.scheduled_themes'), 'name'));
        // Relecture adverse cycle 2 : un doublon renuméroté ne doit pas retomber sur un numéro donné par position.
        $service = app(StorefrontService::class);
        foreach ([[1, null, 1], [1, null, null, 1], [null, 2, 2, null], [3, null, 1, 1]] as $forme) {
            $liste = array_map(fn ($id) => $this->theme($id === null ? ['id' => null] : ['id' => $id]), $forme);
            $sortie = array_column($service->normalizeScheduledThemes($liste), 'id');
            $this->assertSame(count($forme), count(array_unique($sortie)), 'doublons après normalisation pour '.json_encode($forme).' → '.json_encode($sortie));
        }

        $r = $this->enAdmin($admin)->putJson('/api/admin/storefront/settings', $base + ['scheduled_themes' => null])->assertOk();
        $this->assertCount(3, $r->json('data.draft.scheduled_themes'), '`null` a effacé les thèmes programmés');
        $r = $this->enAdmin($admin)->putJson('/api/admin/storefront/settings', $base)->assertOk();
        $this->assertCount(3, $r->json('data.draft.scheduled_themes'), 'clé absente a effacé les thèmes programmés');
        $r = $this->enAdmin($admin)->putJson('/api/admin/storefront/settings', $base + ['scheduled_themes' => []])->assertOk();
        $this->assertSame([], $r->json('data.draft.scheduled_themes'), 'une liste vide, elle, vide bien la liste');

        $treize = [];
        for ($i = 1; $i <= 13; $i++) {
            $treize[] = $this->theme(['id' => $i, 'name' => "T{$i}"]);
        }
        $r = $this->enAdmin($admin)->putJson('/api/admin/storefront/settings', $base + ['scheduled_themes' => $treize])->assertStatus(422);
        $this->assertStringContainsString('12 thèmes', $r->json('errors.scheduled_themes.0'));

        $r = $this->enAdmin($admin)->putJson('/api/admin/storefront/settings', $base + ['scheduled_themes' => [
            $this->theme(['theme' => ['primary' => '#GGGGGG'] + self::NOEL]),
        ]])->assertStatus(422);
        $this->assertArrayHasKey('scheduled_themes.0.theme.primary', $r->json('errors'));

        // Un nom qui n'est pas un texte : refusé proprement (422), pas une erreur serveur.
        $this->enAdmin($admin)->putJson('/api/admin/storefront/settings', $base + ['scheduled_themes' => [$this->theme(['name' => ['x']])]])
            ->assertStatus(422)->assertJsonValidationErrors(['scheduled_themes.0.name']);
    }

    /** L'ETag public change au basculement d'un thème, sans aucune écriture : un 304 ne peut pas servir les vieilles couleurs. */
    public function test_l_etag_public_change_quand_le_theme_bascule_sans_ecriture(): void
    {
        StorefrontSetting::query()->create(['branch_id' => 1, 'enabled' => true, 'theme' => null, 'scheduled_themes' => [$this->theme()]]);
        $this->publish();

        Carbon::setTestNow(Carbon::parse('2026-12-23 12:00:00', 'Europe/Paris'));
        $avant = $this->lire()->assertOk()->assertJsonPath('theme_name', null);
        $etagAvant = $avant->headers->get('ETag');
        $this->withHeaders(['x-api-key' => '123456', 'If-None-Match' => $etagAvant])->getJson(self::URL)->assertStatus(304);

        Carbon::setTestNow(Carbon::parse('2026-12-24 18:00:00', 'Europe/Paris'));
        $pendant = $this->withHeaders(['x-api-key' => '123456', 'If-None-Match' => $etagAvant])->getJson(self::URL)
            ->assertOk()->assertJsonPath('theme_name', 'Noël')->assertJsonPath('theme.primary', '#B91C1C');
        $this->assertNotSame($etagAvant, $pendant->headers->get('ETag'));
        $this->withHeaders(['x-api-key' => '123456', 'If-None-Match' => $pendant->headers->get('ETag')])->getJson(self::URL)->assertStatus(304);

        Carbon::setTestNow(Carbon::parse('2026-12-26 00:00:00', 'Europe/Paris'));
        $apres = $this->withHeaders(['x-api-key' => '123456', 'If-None-Match' => $pendant->headers->get('ETag')])->getJson(self::URL)
            ->assertOk()->assertJsonPath('theme_name', null);
        $this->assertNotSame($pendant->headers->get('ETag'), $apres->headers->get('ETag'));
    }

    /** Un changement de la SEULE liste programmée rend le brouillon « à publier » ; même priorité → le dernier commencé gagne. */
    public function test_la_liste_seule_rend_le_brouillon_a_publier_et_l_egalite_de_priorite_se_tranche(): void
    {
        StorefrontSetting::query()->create(['branch_id' => 1, 'enabled' => true, 'theme' => null, 'scheduled_themes' => [$this->theme()]]);
        $this->publish();
        $service = app(StorefrontService::class);
        $this->assertFalse($service->hasUnpublishedChanges(1));

        StorefrontSetting::query()->where('branch_id', 1)->update(['scheduled_themes' => json_encode([$this->theme(['priority' => 11])])]);
        $this->assertTrue($service->hasUnpublishedChanges(1), 'une priorité changée doit se voir comme « à publier »');

        $a = $this->theme(['id' => 1, 'name' => 'A', 'priority' => 5, 'starts_at' => '2026-12-20T00:00', 'ends_at' => '2026-12-31T00:00']);
        $b = $this->theme(['id' => 2, 'name' => 'B', 'priority' => 5, 'starts_at' => '2026-12-24T00:00', 'ends_at' => '2026-12-31T00:00']);
        StorefrontSetting::query()->where('branch_id', 1)->update(['scheduled_themes' => json_encode([$a, $b])]);
        $this->publish();
        Carbon::setTestNow(Carbon::parse('2026-12-25 12:00:00', 'Europe/Paris'));
        $this->lire()->assertOk()->assertJsonPath('theme_name', 'B');
        Carbon::setTestNow(Carbon::parse('2026-12-22 12:00:00', 'Europe/Paris'));
        $this->lire()->assertOk()->assertJsonPath('theme_name', 'A')->assertJsonPath('next_change_at', '2026-12-24T00:00:00+01:00');
    }

    public function test_une_version_publiee_avant_les_themes_programmes_reste_a_jour(): void
    {
        StorefrontSetting::query()->create(['branch_id' => 1, 'enabled' => true, 'theme' => config('storefront.default_theme')]);
        $this->publish();
        // Instantané « ancien » : sans la clé scheduled_themes.
        $v = \App\Models\StorefrontVersion::query()->where('branch_id', 1)->first();
        $snapshot = $v->snapshot;
        unset($snapshot['scheduled_themes']);
        $v->forceFill(['snapshot' => $snapshot])->save();

        $this->assertFalse(app(StorefrontService::class)->hasUnpublishedChanges(1));
        $this->lire()->assertOk()->assertJsonPath('theme.primary', '#FF5A1F')->assertJsonPath('theme_name', null);
    }
}
