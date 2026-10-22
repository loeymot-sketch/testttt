<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [VG-2026-10-21 M1-002 = M10-004 · M5-005] Les `throttle:N,M` ANONYMES partageaient UN compteur.
 *
 * Sans nom ni préfixe, `ThrottleRequests::resolveRequestSignature()` signe la requête par
 * `sha1(user_id)` (connecté) ou `sha1(domaine|IP)` (invité) — SANS la route. Tous les limiteurs
 * anonymes d'une même IP (ou d'un même compte) comptaient donc dans le même seau, chacun le
 * comparant à SON plafond :
 *  - site / app : 3 chargements de la page de suivi (`wait-estimate`, 30/min) et la validation du
 *    code (`guest-signup/verify`, 3/5 min) répondait 429 sans une seule tentative ;
 *  - borne : UN compte machine pour tous les clients ; composer 2–3 articles (`pricing/preview`,
 *    60/min) remplissait le seau de `loyalty/check` (10/min) → « Too Many Attempts. ».
 *
 * Correctif : chaque limiteur garde SON seuil mais reçoit un compteur PROPRE (3e argument de
 * `throttle`, préfixe de clé). Chaque paire de tests prouve les deux faces : l'isolation ET que le
 * limiteur mord encore (anti force brute intact).
 */
class AnonymousThrottleCounterIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key']);
        Http::fake();
    }

    private function api(): self
    {
        return $this->withHeader('x-api-key', 'test-api-key');
    }

    private function verify()
    {
        return $this->api()->postJson('/api/auth/guest-signup/verify', [
            'phone' => '0611223344',
            'code'  => '0000',
        ]);
    }

    public function test_charger_la_page_de_suivi_ne_consomme_pas_les_essais_du_code(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $r = $this->api()->getJson('/api/frontend/order/wait-estimate?branch_id=1');
            $this->assertNotSame(429, $r->status(), "wait-estimate #{$i} ne doit pas être limité.");
        }

        $this->assertNotSame(429, $this->verify()->status(),
            'Le 1er essai du code ne doit pas répondre 429 après 4 chargements de la page de suivi.');
    }

    public function test_le_limiteur_du_code_mord_encore_au_quatrieme_essai(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->assertNotSame(429, $this->verify()->status(), "Essai #{$i} ne doit pas encore être limité.");
        }

        $this->verify()->assertStatus(429);
    }

    public function test_la_page_de_suivi_ne_bloque_pas_la_connexion_apple(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->api()->getJson('/api/frontend/order/wait-estimate?branch_id=1');
        }

        $r = $this->api()->postJson('/api/auth/social/apple', ['identity_token' => 'x']);
        $this->assertNotSame(429, $r->status(),
            'La connexion Apple ne doit pas hériter du compteur de wait-estimate.');
    }

    public function test_la_connexion_apple_garde_son_plafond_de_dix_par_minute(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $r = $this->api()->postJson('/api/auth/social/apple', ['identity_token' => 'x']);
            $this->assertNotSame(429, $r->status(), "Appel Apple #{$i} ne doit pas encore être limité.");
        }

        $this->api()->postJson('/api/auth/social/apple', ['identity_token' => 'x'])->assertStatus(429);
    }

    public function test_composer_des_articles_a_la_borne_ne_bloque_pas_la_fidelite(): void
    {
        $borne = User::factory()->create(['status' => 5]);
        Sanctum::actingAs($borne, ['kiosk:order']);

        for ($i = 1; $i <= 10; $i++) {
            $r = $this->api()->postJson('/api/frontend/pricing/preview', []);
            $this->assertNotSame(429, $r->status(), "pricing/preview #{$i} ne doit pas être limité.");
        }
        for ($i = 1; $i <= 5; $i++) {
            $this->api()->postJson('/api/frontend/kiosk-event', []);
        }

        $r = $this->api()->postJson('/api/frontend/loyalty/check', ['code' => '0611002200']);
        $this->assertNotSame(429, $r->status(),
            'loyalty/check ne doit pas répondre 429 après la composition d\'une commande à la borne.');
    }

    public function test_le_limiteur_fidelite_mord_encore_au_onzieme_appel(): void
    {
        $borne = User::factory()->create(['status' => 5]);
        Sanctum::actingAs($borne, ['kiosk:order']);

        for ($i = 1; $i <= 10; $i++) {
            $r = $this->api()->postJson('/api/frontend/loyalty/check', ['code' => '0611002200']);
            $this->assertNotSame(429, $r->status(), "loyalty/check #{$i} ne doit pas encore être limité.");
        }

        $this->api()->postJson('/api/frontend/loyalty/check', ['code' => '0611002200'])->assertStatus(429);
    }

    /**
     * Sentinelle : plus AUCUN `throttle:N,M` nu sur les routes /api. Un nouveau limiteur sans
     * préfixe (ni nom) retomberait dans le seau commun et recréerait ce défaut.
     */
    public function test_aucune_route_api_ne_porte_un_throttle_numerique_sans_compteur_propre(): void
    {
        $nus = [];
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            foreach ($route->gatherMiddleware() as $mw) {
                if (is_string($mw) && preg_match('/^throttle:\d+,\d+$/', $mw)) {
                    $nus[] = implode('|', $route->methods()).' '.$route->uri().' → '.$mw;
                }
            }
        }

        $this->assertSame([], $nus,
            "Ces routes portent un throttle:N,M sans compteur propre (seau partagé par IP/compte) :\n"
            .implode("\n", $nus));
    }

    /**
     * [VG-2026-10-21 M1-002 · reste vague 1] Hors /api : les webhooks de paiement (Stripe, Senangpay) et la roue
     * (`routes/web.php`) portaient des `throttle:N,M` nus. Même IP → même seau : 60 appels Senangpay (ou un
     * rejeu de signatures invalides) faisaient répondre 429 au webhook Stripe — un paiement confirmé perdu.
     */
    private function webhookRoutesLoaded(): void
    {
        if (! \Illuminate\Support\Facades\Route::has('payment.stripe.webhook')
            || ! \Illuminate\Support\Facades\Route::has('payment.senangpay.webhook')) {
            // Routes chargées seulement si storage/installed existe (RouteServiceProvider::mapWebRoutes) ;
            // la sentinelle textuelle ci-dessous couvre ce cas.
            $this->markTestSkipped('Routes des passerelles de paiement non chargées (storage/installed absent).');
        }
    }

    public function test_le_webhook_stripe_ne_partage_pas_le_compteur_du_webhook_senangpay(): void
    {
        $this->webhookRoutesLoaded();

        for ($i = 1; $i <= 60; $i++) {
            $this->post('/payment/senangpay-webhook', []);
        }

        $r = $this->post('/payment/stripe-webhook', [], ['Stripe-Signature' => 't=1,v1=x']);
        $this->assertNotSame(429, $r->status(),
            'Le webhook Stripe ne doit pas hériter du compteur du webhook Senangpay (même IP).');
    }

    public function test_le_webhook_stripe_garde_son_plafond_de_soixante_par_minute(): void
    {
        $this->webhookRoutesLoaded();

        for ($i = 1; $i <= 60; $i++) {
            $r = $this->post('/payment/stripe-webhook', [], ['Stripe-Signature' => 't=1,v1=x']);
            $this->assertNotSame(429, $r->status(), "Appel Stripe #{$i} ne doit pas encore être limité.");
        }

        $this->post('/payment/stripe-webhook', [], ['Stripe-Signature' => 't=1,v1=x'])->assertStatus(429);
    }

    /** Sentinelle : aucune route, /api ou non, ne porte un throttle:N,M nu. */
    public function test_aucune_route_hors_api_ne_porte_un_throttle_numerique_sans_compteur_propre(): void
    {
        $nus = [];
        foreach (app('router')->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $mw) {
                if (is_string($mw) && preg_match('/^throttle:\d+,\d+$/', $mw)) {
                    $nus[] = implode('|', $route->methods()).' '.$route->uri().' → '.$mw;
                }
            }
        }

        $this->assertSame([], $nus,
            "Ces routes portent un throttle:N,M sans compteur propre (seau partagé par IP/compte) :\n"
            .implode("\n", $nus));
    }

    /**
     * Sentinelle TEXTUELLE des fichiers de routes : les passerelles de paiement ne sont chargées que si
     * `storage/installed` existe ; la lecture du code ne dépend pas de l'environnement.
     */
    public function test_aucun_fichier_de_routes_ne_declare_un_throttle_numerique_nu(): void
    {
        $files = array_merge(
            glob(base_path('routes/*.php')) ?: [],
            glob(app_path('Http/PaymentGateways/Routes/*.php')) ?: [],
        );
        $this->assertNotEmpty($files);

        $nus = [];
        foreach ($files as $file) {
            foreach (file($file) as $n => $line) {
                if (preg_match('/[\'"]throttle:\d+,\d+[\'"]/', $line)) {
                    $nus[] = str_replace(base_path().'/', '', $file).':'.($n + 1).' '.trim($line);
                }
            }
        }

        $this->assertSame([], $nus, "throttle:N,M nu (sans 3e argument) dans :\n".implode("\n", $nus));
    }
}
