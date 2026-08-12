<?php

/**
 * [GOAL-OPS-SWAP W3 2026-08-12 — « trop de requêtes » sur la caisse]
 *
 * DÉFAUT : `throttle:api` plafonnait **par COMPTE** — `by($request->user()?->id)`.
 * Caisse, cuisine et écran client connectés sous le même login partageaient
 * donc UN SEUL budget de 120/min. Mesuré : ouvrir la caisse coûte 29 requêtes ;
 * à 4 écrans le compte franchissait le mur et le caissier voyait
 * « Trop de requêtes ».
 *
 * CORRECTIF : le budget est désormais porté par le COUPLE compte+appareil.
 * Chaque écran a le sien. L'identité d'appareil est déjà de première classe
 * dans ce projet — `X-Device-Id` part sur CHAQUE requête
 * (`resources/js/shared/axios-setup.js:90`) et `DeviceTokenService::resolveDeviceId()`
 * la valide déjà par liste blanche stricte.
 *
 * GARDE-FOU INDISPENSABLE : sans plafond global, il suffirait de faire tourner
 * l'en-tête `X-Device-Id` pour obtenir un débit illimité. Une SECONDE limite,
 * par compte tous appareils confondus, ferme ce vecteur tout en laissant
 * respirer les écrans légitimes.
 *
 * @group sentinel
 * @group security
 */

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ApiRateLimitPerDeviceTest extends TestCase
{
    use RefreshDatabase;

    /** Endpoint authentifié le moins coûteux sous `throttle:api`. */
    private const URL = '/api/admin/default-access';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        RateLimiter::clear('api');
        // Plafonds bas pour éprouver le comportement sans 120 appels.
        config([
            'app.api_throttle_per_minute' => 3,
            'app.api_throttle_user_ceiling_per_minute' => 8,
        ]);
    }

    private function staff(): User
    {
        $u = User::factory()->create(['branch_id' => 1]);
        $u->assignRole('Admin');

        return $u;
    }

    private function appeler(User $u, string $appareil)
    {
        return $this->actingAs($u, 'sanctum')
            ->withHeaders(['X-Device-Id' => $appareil])
            ->getJson(self::URL);
    }

    public function test_un_ecran_ne_consomme_plus_le_budget_de_l_autre(): void
    {
        $u = $this->staff();

        // La caisse épuise SON budget.
        for ($i = 0; $i < 3; $i++) {
            $this->appeler($u, 'caisse-comptoir-01')->assertOk();
        }
        $this->appeler($u, 'caisse-comptoir-01')->assertStatus(429);

        // L'écran cuisine, MÊME COMPTE, doit rester servi.
        $this->appeler($u, 'ecran-cuisine-01')
            ->assertOk();
    }

    public function test_un_meme_ecran_reste_bien_plafonne(): void
    {
        $u = $this->staff();

        for ($i = 0; $i < 3; $i++) {
            $this->appeler($u, 'caisse-comptoir-01')->assertOk();
        }

        // La protection anti-boucle folle doit survivre au correctif.
        $this->appeler($u, 'caisse-comptoir-01')->assertStatus(429);
    }

    public function test_faire_tourner_l_identifiant_d_appareil_ne_donne_PAS_un_debit_illimite(): void
    {
        $u = $this->staff();

        // Sans plafond global, 20 identifiants = 20 budgets = débit libre.
        $refuse = false;
        for ($i = 0; $i < 20; $i++) {
            $r = $this->appeler($u, 'appareil-rotatif-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
            if ($r->getStatusCode() === 429) {
                $refuse = true;
                break;
            }
        }

        $this->assertTrue(
            $refuse,
            'Le plafond global par COMPTE ne se déclenche pas : faire tourner '
            .'X-Device-Id donne un débit illimité. Le correctif a ouvert un vecteur d\'abus.'
        );
    }

    /**
     * On inspecte la CLÉ du limiteur, pas le hasard d'un 429 : le harnais de
     * test n'accumule pas fiablement les compteurs, et un banc qui dépend de
     * cela mesurerait le harnais, pas le contrat.
     */
    public function test_un_anonyme_est_toujours_compte_par_son_IP_jamais_par_son_appareil(): void
    {
        $requete = \Illuminate\Http\Request::create(self::URL, 'GET');
        $requete->headers->set('X-Device-Id', 'appareil-invente-par-le-client');
        $requete->server->set('REMOTE_ADDR', '203.0.113.7');

        $brut = (RateLimiter::limiter("api"))($requete);
        $limites = is_array($brut) ? $brut : [$brut];

        $this->assertCount(1, $limites, 'Un anonyme ne doit avoir qu\'UNE limite : celle de son IP.');
        $this->assertSame(
            '203.0.113.7',
            $limites[0]->key,
            'La clé d\'un anonyme doit être son IP. Si elle contient l\'appareil, '
            .'n\'importe qui s\'offre un budget en inventant un en-tête.'
        );
        $this->assertStringNotContainsString('appareil-invente', (string) $limites[0]->key);
    }

    public function test_un_compte_recoit_bien_DEUX_limites_appareil_puis_plafond_global(): void
    {
        $u = $this->staff();

        $requete = \Illuminate\Http\Request::create(self::URL, 'GET');
        $requete->headers->set('X-Device-Id', 'ecran-cuisine-01');
        $requete->setUserResolver(fn () => $u);

        $brut = (RateLimiter::limiter("api"))($requete);
        $limites = is_array($brut) ? $brut : [$brut];

        $this->assertCount(
            2,
            $limites,
            'Il faut DEUX limites : le budget de l\'écran, ET le plafond global du '
            .'compte qui empêche la rotation d\'identifiant de donner un débit libre.'
        );
        $this->assertStringContainsString('ecran-cuisine-01', (string) $limites[0]->key);
        $this->assertStringNotContainsString(
            'ecran-cuisine-01',
            (string) $limites[1]->key,
            'Le plafond global ne doit PAS dépendre de l\'appareil, sinon il ne plafonne rien.'
        );
        $this->assertGreaterThan(
            $limites[0]->maxAttempts,
            $limites[1]->maxAttempts,
            'Le plafond global doit être plus large que le budget d\'un écran.'
        );
    }

    public function test_un_en_tete_d_appareil_absent_ou_invalide_ne_fait_pas_planter(): void
    {
        $u = $this->staff();

        $this->actingAs($u, 'sanctum')->getJson(self::URL)->assertOk();
        $this->actingAs($u, 'sanctum')
            ->withHeaders(['X-Device-Id' => '<<invalide>>'])
            ->getJson(self::URL)
            ->assertOk();
    }
}
