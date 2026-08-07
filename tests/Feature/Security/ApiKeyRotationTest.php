<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ApiKeyMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [ROTATION CLÉ D'API 2026-08-08] La production portait encore la valeur d'EXEMPLE du dépôt
 * (`change-me-…`), connue de quiconque a lu le code. Pour la tourner sans coupure, le garde
 * accepte une clé PRÉCÉDENTE le temps de la bascule.
 *
 * Pourquoi une bascule est nécessaire : la même clé vit à trois endroits qui ne peuvent pas
 * changer au même instant — le `.env` du serveur, les bundles COMPILÉS (`MIX_` → `app.js` et
 * `pos-app.js`, donc borne + caisse) et le meta `api-key` du site déployé sur Vercel. Avec une
 * seule clé acceptée, la rotation ouvre une fenêtre de `400 invalid_api_key` sur au moins une
 * surface : une panne de prise de commande.
 *
 * Ce que cette suite verrouille — et surtout ce qu'elle REFUSE de laisser passer :
 *   · la clé courante fonctionne ;
 *   · la précédente fonctionne PENDANT la rotation, et cesse dès qu'on la vide ;
 *   · une clé inconnue est refusée (sinon la rotation aurait ouvert la porte) ;
 *   · une clé attendue VIDE ne valide JAMAIS rien — c'est le piège de ce type de garde :
 *     un déploiement sans `API_KEY` ferait passer un en-tête vide et ouvrirait tout.
 */
class ApiKeyRotationTest extends TestCase
{
    private function appel(?string $cleEnvoyee): int
    {
        $request = Request::create('/api/frontend/item', 'GET');
        if ($cleEnvoyee !== null) {
            $request->headers->set('x-api-key', $cleEnvoyee);
        }

        $reponse = (new ApiKeyMiddleware())->handle(
            $request,
            fn () => response()->json(['ok' => true], 200)
        );

        return $reponse->getStatusCode();
    }

    public function test_la_cle_courante_est_acceptee(): void
    {
        Config::set('app.api_key', 'CLE-COURANTE-1234567890');
        Config::set('app.api_key_previous', '');

        $this->assertSame(200, $this->appel('CLE-COURANTE-1234567890'));
    }

    public function test_la_cle_precedente_est_acceptee_pendant_la_rotation(): void
    {
        Config::set('app.api_key', 'CLE-NOUVELLE-abcdefghij');
        Config::set('app.api_key_previous', 'CLE-ANCIENNE-0987654321');

        $this->assertSame(200, $this->appel('CLE-NOUVELLE-abcdefghij'), 'la nouvelle doit passer');
        $this->assertSame(200, $this->appel('CLE-ANCIENNE-0987654321'), 'l\'ancienne doit passer PENDANT la bascule');
    }

    public function test_la_cle_precedente_cesse_de_fonctionner_des_qu_on_la_vide(): void
    {
        Config::set('app.api_key', 'CLE-NOUVELLE-abcdefghij');
        Config::set('app.api_key_previous', '');

        $this->assertSame(400, $this->appel('CLE-ANCIENNE-0987654321'),
            'fin de rotation : l\'ancienne clé doit être refusée');
    }

    public function test_une_cle_inconnue_est_refusee(): void
    {
        Config::set('app.api_key', 'CLE-COURANTE-1234567890');
        Config::set('app.api_key_previous', 'CLE-ANCIENNE-0987654321');

        $this->assertSame(400, $this->appel('n-importe-quoi'));
    }

    public function test_absence_d_en_tete_est_refusee(): void
    {
        Config::set('app.api_key', 'CLE-COURANTE-1234567890');
        Config::set('app.api_key_previous', '');

        $this->assertSame(400, $this->appel(null));
    }

    /**
     * LE piège de ce garde. Si la configuration est vide (déploiement bâclé, `config:cache`
     * obsolète, variable oubliée), une comparaison naïve `'' === ''` laisserait passer toute
     * requête portant un en-tête vide — c'est-à-dire tout le monde. Le garde doit refuser.
     */
    public function test_une_configuration_vide_ne_laisse_JAMAIS_passer(): void
    {
        Config::set('app.api_key', '');
        Config::set('app.api_key_previous', '');

        $this->assertSame(400, $this->appel(''), 'clé attendue vide + en-tête vide = REFUS');
        $this->assertSame(400, $this->appel('quoi que ce soit'));
        $this->assertSame(400, $this->appel(null));
    }

    /** Une clé courante vide mais une précédente renseignée ne doit pas non plus ouvrir. */
    public function test_courante_vide_et_precedente_renseignee_reste_stricte(): void
    {
        Config::set('app.api_key', '');
        Config::set('app.api_key_previous', 'CLE-ANCIENNE-0987654321');

        $this->assertSame(400, $this->appel(''), 'l\'en-tête vide ne doit pas matcher la courante vide');
        $this->assertSame(200, $this->appel('CLE-ANCIENNE-0987654321'), 'la précédente reste valable');
    }
}
