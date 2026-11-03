<?php

namespace Tests\Feature\Storefront;

use App\Models\Branch;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Support\Storefront\Contrast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [APP HYBRIDE W2a · 2026-11-03] Thème de la vitrine = RÉGLAGES, pas du code (règle Apple 2.5.2).
 *   · liste blanche de jetons, valeurs `#RRGGBB` seulement : aucune CSS libre, aucune URL, aucun script ;
 *   · contraste texte/fond < 4,5:1 (WCAG AA) refusé, message en français qui donne le rapport mesuré.
 */
class StorefrontThemeContrastTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/storefront/settings';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config(['app.api_key' => '123456']);
        Branch::factory()->create(['id' => 1]);
        $u = User::factory()->create(['branch_id' => 1]);
        $u->givePermissionTo('settings');
        Sanctum::actingAs($u, ['*']);
    }

    private function envoyer(?array $theme, bool $enabled = true)
    {
        return $this->withHeader('x-api-key', '123456')->putJson(self::URL, ['enabled' => $enabled, 'theme' => $theme]);
    }

    private function theme(array $over = []): array
    {
        return $over + config('storefront.default_theme');
    }

    public function test_rapport_de_contraste_wcag(): void
    {
        $this->assertEqualsWithDelta(21.0, Contrast::ratio('#FFFFFF', '#000000'), 0.001);
        $this->assertEqualsWithDelta(1.0, Contrast::ratio('#FF5A1F', '#FF5A1F'), 0.001);
        $this->assertGreaterThanOrEqual(4.5, Contrast::ratio('#767676', '#FFFFFF')); // 4,54 : la limite connue
        $this->assertLessThan(4.5, Contrast::ratio('#777777', '#FFFFFF'));           // 4,48
        $this->assertSame(Contrast::ratio('#0A0A0A', '#FFD93D'), Contrast::ratio('#FFD93D', '#0A0A0A')); // symétrique
    }

    public function test_les_couleurs_actuelles_du_site_passent_et_sont_normalisees_en_majuscules(): void
    {
        $this->envoyer($this->theme(['primary' => '#ff5a1f']))->assertOk();
        $this->assertSame('#FF5A1F', StorefrontSetting::query()->where('branch_id', 1)->first()->theme['primary']);
    }

    /**
     * [RED cycle 3 · P2-3 2026-11-03] Le site peint du texte BLANC fixe sur `primary` (boutons, récap du panier) et du texte
     * `accent` sur fond NOIR (tuile de catégorie active, carte fidélité) : ces couples réels sont contrôlés, sinon un thème
     * « fêtes » accepté rendait du texte illisible (mesuré 1,38:1 et 2,13:1).
     */
    public function test_primaire_trop_clair_pour_le_texte_blanc_du_site_refuse(): void
    {
        $r = $this->envoyer($this->theme(['primary' => '#FFD93D', 'on_primary' => '#0A0A0A']))->assertStatus(422);
        $this->assertStringContainsString('blanc', mb_strtolower($r->json('errors')['theme.primary'][0]));
        // l'orange actuel de la marque (3,11:1 avec le blanc) reste accepté : c'est le niveau du site aujourd'hui (seuil 3:1)
        $this->envoyer($this->theme())->assertOk();
    }

    public function test_accent_trop_sombre_pour_le_fond_noir_du_site_refuse(): void
    {
        $r = $this->envoyer($this->theme(['accent' => '#8B1A1A']))->assertStatus(422);
        $this->assertStringContainsString('noir', mb_strtolower($r->json('errors')['theme.accent'][0]));
    }

    public function test_contraste_insuffisant_refuse_en_francais_avec_le_rapport_mesure(): void
    {
        // Blanc sur l'orange de la marque : 3,11:1 (tronqué, jamais arrondi vers le haut) — lisible pour beaucoup, mais sous le seuil AA.
        $r = $this->envoyer($this->theme(['on_primary' => '#FFFFFF']))->assertStatus(422);
        $msg = $r->json('errors')['theme.on_primary'][0];
        $this->assertStringContainsString('Contraste insuffisant', $msg);
        $this->assertStringContainsString('3,11:1', $msg);
        $this->assertStringContainsString('4,5:1', $msg);
        $this->assertNull(StorefrontSetting::query()->where('branch_id', 1)->first());

        $this->envoyer($this->theme(['text' => '#777777', 'background' => '#FFFFFF', 'surface' => '#FFFFFF']))
            ->assertStatus(422)->assertJsonValidationErrors(['theme.text']);
        $this->envoyer($this->theme(['banner_text' => '#FFD93D', 'banner_bg' => '#FFFFFF']))
            ->assertStatus(422)->assertJsonValidationErrors(['theme.banner_text']);
    }

    public function test_aucune_css_libre_aucune_url_aucun_script(): void
    {
        foreach (['red', '#FFF', 'url(https://x.fr/a.png)', '#FF0000; background:url(x)', 'expression(alert(1))', '<script>', '#GGGGGG', 'rgb(0,0,0)'] as $bad) {
            $r = $this->envoyer($this->theme(['accent' => $bad]))->assertStatus(422);
            $this->assertStringContainsString('#RRGGBB', $r->json('errors')['theme.accent'][0], "valeur acceptée à tort : {$bad}");
        }
    }

    public function test_jeton_inconnu_ou_manquant_refuse(): void
    {
        $r = $this->envoyer($this->theme(['font_url' => '#000000']))->assertStatus(422);
        $this->assertStringContainsString('inconnu', $r->json('errors')['theme'][0]);

        $missing = $this->theme();
        unset($missing['surface']);
        $this->envoyer($missing)->assertStatus(422)->assertJsonValidationErrors(['theme.surface']);
    }

    public function test_theme_nul_accepte_l_application_garde_ses_couleurs(): void
    {
        $this->envoyer(null)->assertOk()->assertJsonPath('data.draft.theme', null);
    }
}
