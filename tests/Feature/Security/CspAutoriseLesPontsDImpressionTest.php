<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * [AUDIT-SUPERVISEUR 2026-08-25 · D-002] La politique de sécurité doit autoriser LES DEUX
 * ponts d'impression, pas seulement celui de la caisse.
 *
 * LE DÉFAUT — `connect-src` n'autorisait que le port **9100** (pont caisse), alors que
 * `resources/js/helpers/kitchenLocalPrinter.js:22` compose le **9101** (pont cuisine).
 *
 * POURQUOI IL ÉTAIT INVISIBLE — la politique tourne en `report_only` : le navigateur
 * SIGNALE sans bloquer. L'impression marche, la violation part dans un journal que
 * personne ne lit, et l'équipe de capture avait rangé les erreurs réseau associées dans
 * « bruit Pusher allowlisté ». Le superviseur a montré que ce ne sont ni Pusher ni des
 * websockets : ce sont des appels HTTP aux ponts d'impression.
 *
 * CE QUI SE SERAIT PASSÉ — le jour où `CSP_ENFORCE_MODE` passe à `enforce`, le navigateur
 * bloque l'appel et la cuisine cesse d'imprimer, sans qu'une seule ligne de code ait
 * changé. Une mine armée par une configuration.
 *
 * Ce test lie les deux moitiés : le port que le JS compose et le port que la politique
 * autorise. Changer l'un sans l'autre fait rougir ici.
 */
class CspAutoriseLesPontsDImpressionTest extends TestCase
{
    /** Le port réellement composé par le pont cuisine, lu à la source. */
    private function portDuPontCuisine(): string
    {
        $source = file_get_contents(base_path('resources/js/helpers/kitchenLocalPrinter.js'));
        $this->assertNotFalse($source, 'kitchenLocalPrinter.js introuvable');

        preg_match('/KITCHEN_BRIDGE_PORT\s*=\s*(\d+)/', $source, $m);
        $this->assertNotEmpty($m, 'KITCHEN_BRIDGE_PORT introuvable dans kitchenLocalPrinter.js');

        return $m[1];
    }

    /** @test */
    public function la_politique_autorise_le_port_que_le_pont_cuisine_compose_vraiment(): void
    {
        $port = $this->portDuPontCuisine();
        $directives = config('security.csp.directives');

        $this->assertStringContainsString(
            "http://127.0.0.1:{$port}",
            $directives,
            "Le pont cuisine compose le port {$port} mais `connect-src` ne l'autorise pas : "
            ."en mode `enforce`, le navigateur bloquerait l'impression cuisine."
        );
        $this->assertStringContainsString("http://localhost:{$port}", $directives);
    }

    /** @test */
    public function le_pont_caisse_reste_autorise(): void
    {
        $directives = config('security.csp.directives');

        $this->assertStringContainsString('http://127.0.0.1:9100', $directives);
        $this->assertStringContainsString('http://localhost:9100', $directives);
    }

    /**
     * Garde de portée : on ouvre les ponts d'impression LOCAUX, pas l'internet.
     * Un `connect-src` trop large annulerait l'intérêt de la politique.
     *
     * @test
     */
    public function la_politique_n_ouvre_pas_les_ponts_a_des_hotes_distants(): void
    {
        $directives = config('security.csp.directives');

        preg_match_all('/http:\/\/([^\s;]+):(910\d)/', $directives, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m, 'aucun pont d\'impression déclaré');

        foreach ($m as [$entier, $hote]) {
            $this->assertContains(
                $hote,
                ['127.0.0.1', 'localhost'],
                "Le pont d'impression `{$entier}` désigne un hôte NON LOCAL : la politique "
                ."ne doit ouvrir ces ports que sur la machine du poste."
            );
        }
    }
}
