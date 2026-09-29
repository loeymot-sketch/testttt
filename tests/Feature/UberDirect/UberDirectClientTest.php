<?php

namespace Tests\Feature\UberDirect;

use App\Services\UberDirect\UberDirectClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [UBER-DIRECT 2026-09-06] Le transport HTTP vers Uber Direct.
 *
 * Deux exigences du propriétaire sont vérifiées ici avant toute chose :
 *   · « Toutes les communications avec Uber doivent être faites côté serveur » — et **aucun
 *     appel n'est émis tant que l'intégration n'est pas armée**, ce qui garantit qu'un
 *     déploiement avant l'obtention des clés ne touche rien ;
 *   · « Jamais de Client Secret dans les logs » — le secret ne doit jamais fuir.
 *
 * Le reste couvre ce qui casse en vrai : une adresse non livrable, une panne d'Uber, un
 * jeton périmé. Dans les trois cas le checkout doit rester debout.
 */
class UberDirectClientTest extends TestCase
{
    private function armer(array $surcharges = []): void
    {
        config(array_merge([
            'uber_direct.enabled' => true,
            'uber_direct.client_id' => 'cid-test',
            'uber_direct.client_secret' => 'secret-test',
            'uber_direct.customer_id' => 'cust-test',
            'uber_direct.token_url' => 'https://auth.uber.com/oauth/v2/token',
            'uber_direct.api_base' => 'https://api.uber.com',
        ], $surcharges));
        Cache::forget('uber_direct_access_token');
    }

    private function faireJeton(): array
    {
        return ['auth.uber.com/*' => Http::response(['access_token' => 'jeton-abc', 'expires_in' => 3600], 200)];
    }

    /** @test */
    public function tant_que_l_integration_n_est_pas_armee_aucun_appel_n_est_emis(): void
    {
        // C'est ce qui rend le déploiement sûr AVANT d'avoir les clés d'Uber.
        config(['uber_direct.enabled' => false]);
        Http::fake();

        $client = new UberDirectClient;

        $this->assertFalse($client->isConfigured());
        $this->assertNull($client->accessToken());
        $this->assertNull($client->createQuote(['x' => 1]));
        Http::assertNothingSent();
    }

    /** @test */
    public function des_identifiants_incomplets_n_emettent_aucun_appel_non_plus(): void
    {
        $this->armer(['uber_direct.customer_id' => '']);
        Http::fake();

        $this->assertFalse((new UberDirectClient)->isConfigured());
        Http::assertNothingSent();
    }

    /** @test */
    public function le_jeton_est_obtenu_avec_le_scope_eats_deliveries_puis_mis_en_cache(): void
    {
        $this->armer();
        Http::fake($this->faireJeton());

        $client = new UberDirectClient;
        $this->assertSame('jeton-abc', $client->accessToken());
        $this->assertSame('jeton-abc', $client->accessToken(), 'le second appel vient du cache');

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['grant_type'] === 'client_credentials'
            && $r['scope'] === config('uber_direct.scopes'));
    }

    /** @test */
    public function le_cache_du_jeton_ne_collisionne_PAS_avec_celui_de_la_marketplace(): void
    {
        // Le défaut le plus insidieux qu'on puisse introduire : deux intégrations qui
        // s'échangent leurs jetons et parlent chacune avec les identifiants de l'autre.
        $this->armer();
        Cache::put('uber_eats_access_token', 'jeton-marketplace', 600);
        Http::fake($this->faireJeton());

        $this->assertSame('jeton-abc', (new UberDirectClient)->accessToken());
        $this->assertSame('jeton-marketplace', Cache::get('uber_eats_access_token'), 'intact');
    }

    /** @test */
    public function une_adresse_non_livrable_ne_fait_pas_tomber_le_checkout(): void
    {
        // Uber refuse : on rend null, l'appelant affichera « non livrable ». Pas d'exception.
        $this->armer();
        Http::fake(array_merge($this->faireJeton(), [
            'api.uber.com/*' => Http::response(['code' => 'address_undeliverable'], 422),
        ]));

        $this->assertNull((new UberDirectClient)->createQuote(['dropoff_address' => 'nulle part']));
    }

    /** @test */
    public function une_panne_d_uber_ne_fait_pas_tomber_le_checkout(): void
    {
        $this->armer();
        Http::fake(array_merge($this->faireJeton(), [
            'api.uber.com/*' => Http::response('bad gateway', 502),
        ]));

        $this->assertNull((new UberDirectClient)->createQuote(['a' => 1]));
    }

    /** @test */
    public function une_coupure_reseau_ne_fait_pas_tomber_le_checkout(): void
    {
        $this->armer();
        Http::fake(array_merge($this->faireJeton(), [
            'api.uber.com/*' => fn () => throw new \RuntimeException('connexion refusée'),
        ]));

        $this->assertNull((new UberDirectClient)->createQuote(['a' => 1]));
    }

    /** @test */
    public function un_jeton_perime_est_renouvele_une_fois_puis_l_appel_reussit(): void
    {
        $this->armer();
        $appels = 0;
        Http::fake([
            'auth.uber.com/*' => Http::response(['access_token' => 'jeton-abc', 'expires_in' => 3600], 200),
            'api.uber.com/*' => function () use (&$appels) {
                $appels++;

                return $appels === 1
                    ? Http::response(['code' => 'unauthorized'], 401)
                    : Http::response(['quote_id' => 'q1', 'fee' => 742], 200);
            },
        ]);

        $res = (new UberDirectClient)->createQuote(['a' => 1]);

        $this->assertSame('q1', $res['quote_id'] ?? null);
        $this->assertSame(2, $appels, 'un seul réessai, pas une boucle');
    }

    /** @test */
    public function un_401_persistant_n_entraine_pas_de_boucle_infinie(): void
    {
        $this->armer();
        Http::fake(array_merge($this->faireJeton(), [
            'api.uber.com/*' => Http::response(['code' => 'unauthorized'], 401),
        ]));

        $this->assertNull((new UberDirectClient)->createQuote(['a' => 1]));
    }

    /** @test */
    public function l_url_porte_le_customer_id_et_le_bon_chemin(): void
    {
        $this->armer();
        Http::fake(array_merge($this->faireJeton(), [
            'api.uber.com/*' => Http::response(['quote_id' => 'q1'], 200),
        ]));

        (new UberDirectClient)->createQuote(['a' => 1]);

        Http::assertSent(fn ($r) => str_contains((string) $r->url(), '/v1/customers/cust-test/delivery_quotes'));
    }

    /** @test */
    public function le_secret_client_ne_fuit_jamais_dans_les_journaux(): void
    {
        // Exigence propriétaire : « Jamais de Client Secret dans les logs ».
        $this->armer();
        Http::fake([
            'auth.uber.com/*' => Http::response(['error' => 'invalid_client', 'debug' => 'secret-test'], 401),
        ]);

        $messages = [];
        \Illuminate\Support\Facades\Log::listen(function ($e) use (&$messages) {
            $messages[] = $e->message.' '.json_encode($e->context);
        });

        (new UberDirectClient)->accessToken();

        foreach ($messages as $m) {
            $this->assertStringNotContainsString('secret-test', $m, 'le secret ne doit jamais être journalisé');
        }
    }
}
