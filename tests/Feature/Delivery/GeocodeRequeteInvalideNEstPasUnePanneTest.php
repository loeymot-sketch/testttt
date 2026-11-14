<?php

namespace Tests\Feature\Delivery;

use App\Exceptions\Delivery\GeocodeUnavailableException;
use App\Services\Delivery\AddressGeocoder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [SUPERVISION 2026-11-13] La Base Adresse Nationale répond 400 « q: must contain between 3 and 200 chars and start with
 * a number or a letter » (mesuré le 2026-11-13) à un texte trop long ou commençant par « - ». Nos validations laissent
 * passer 300 (devis) et 500 (adresse) caractères. Ce 400 était classé PANNE (« service indisponible, choisissez À
 * emporter ») au lieu de « adresse invalide » : le client ne savait pas qu'il devait corriger sa saisie.
 * Seuls 5xx, 429, erreur réseau et corps sans `features` sont une panne.
 */
class GeocodeRequeteInvalideNEstPasUnePanneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function reponseBan400()
    {
        return Http::response(['code' => 400, 'message' => 'q: must contain between 3 and 200 chars and start with a number or a letter'], 400);
    }

    public function test_un_400_de_la_ban_est_une_adresse_invalide_pas_une_panne(): void
    {
        Http::fake(['api-adresse.data.gouv.fr/*' => $this->reponseBan400()]);

        try {
            app(AddressGeocoder::class)->geocode(str_repeat('12 rue de Lens ', 15));
            $this->fail('Une adresse refusée par la BAN doit lever une exception.');
        } catch (GeocodeUnavailableException $e) {
            $this->assertFalse($e->panne, 'un 400 (saisie invalide) ne doit pas être annoncé comme une panne');
        }

        $this->assertFalse(app(AddressGeocoder::class)->suggestions('-12 rue de lens')['panne']);
    }

    public function test_un_429_ou_un_5xx_reste_une_panne(): void
    {
        foreach ([429, 500, 503] as $statut) {
            Http::swap(new \Illuminate\Http\Client\Factory());
            Http::fake(['api-adresse.data.gouv.fr/*' => Http::response('x', $statut)]);
            Cache::flush();
            try {
                app(AddressGeocoder::class)->geocode("12 rue de Lens $statut Hénin-Beaumont");
                $this->fail("$statut doit lever une exception.");
            } catch (GeocodeUnavailableException $e) {
                $this->assertTrue($e->panne, "$statut doit rester une panne");
            }
        }
    }
}
