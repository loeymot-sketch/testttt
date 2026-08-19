<?php

namespace Tests\Feature\Apps;

use Tests\TestCase;

/**
 * [APPS 2026-08-19] Les applications iOS et Android doivent pouvoir appeler l'API.
 *
 * LE DÉFAUT QUE CES TESTS RENDENT IMPOSSIBLE
 * ------------------------------------------
 * Une application empaquetée sert ses fichiers depuis un serveur local interne à la vue
 * web : son origine est `https://localhost`, pas `lecayenne.fr`. Si la politique d'origine
 * du serveur ne l'autorise pas, TOUS les appels de l'application sont refusés par le
 * navigateur embarqué.
 *
 * Ce défaut est particulièrement traître, et ce dépôt l'a déjà vécu avec `api-base-url` :
 * la carte s'affiche — elle vient de fichiers embarqués dans l'application — et tout a
 * l'air de fonctionner. Seules la connexion, la commande et la fidélité échouent. Une
 * application « qui marche » et qui ne prend aucune commande.
 *
 * Aucun test unitaire du code métier ne peut attraper cela : le refus vient du navigateur,
 * pas du serveur. D'où ces tests, qui interrogent l'API exactement comme l'application le
 * fera — avec un en-tête `Origin`.
 */
class AppOriginCorsTest extends TestCase
{
    /** Une route publique de l'API suffit : c'est la politique d'origine qu'on teste. */
    private const ROUTE = '/api/frontend/item';

    private function preflight(string $origine)
    {
        return $this->call('OPTIONS', self::ROUTE, [], [], [], [
            'HTTP_ORIGIN' => $origine,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,x-api-key',
        ]);
    }

    /**
     * @test
     * @dataProvider originesApplication
     */
    public function les_origines_des_applications_sont_autorisees(string $origine): void
    {
        $reponse = $this->preflight($origine);

        $this->assertSame(
            $origine,
            $reponse->headers->get('Access-Control-Allow-Origin'),
            "L'origine $origine doit être autorisée, sinon l'application ne peut PASSER AUCUNE COMMANDE."
        );
    }

    public static function originesApplication(): array
    {
        return [
            'schéma https (configuration retenue, iOS et Android)' => ['https://localhost'],
            'schéma capacitor (défaut iOS)'                        => ['capacitor://localhost'],
            'schéma ionic (hérité)'                                => ['ionic://localhost'],
        ];
    }

    /** @test */
    public function une_origine_distante_quelconque_reste_refusee(): void
    {
        // La contrepartie du test précédent. Autoriser l'application ne doit pas revenir à
        // ouvrir l'API à n'importe quel site : sans cette vérification, un motif trop large
        // passerait inaperçu — les tests d'autorisation seraient verts, et l'API ouverte.
        $reponse = $this->preflight('https://site-malveillant.example');

        $this->assertNotSame(
            'https://site-malveillant.example',
            $reponse->headers->get('Access-Control-Allow-Origin'),
            'Une origine inconnue ne doit jamais être renvoyée comme autorisée.'
        );
    }

    /** @test */
    public function localhost_avec_un_port_reste_autorise_pour_le_developpement(): void
    {
        // Le motif historique (développement local, e2e) ne doit pas avoir été cassé en
        // ajoutant celui des applications.
        $reponse = $this->preflight('http://127.0.0.1:8011');

        $this->assertSame('http://127.0.0.1:8011', $reponse->headers->get('Access-Control-Allow-Origin'));
    }
}
