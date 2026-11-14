<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * LE JOUR OÙ LA CSP PASSERA EN MODE BLOQUANT, LA CUISINE NE DOIT PAS S'ARRÊTER.
 *
 * Constat E-014 / AB-012 / C-006, relevé indépendamment sur TROIS vagues de l'audit
 * superviseur (2026-08-25). Chaque chargement de la cuisine et du mur client émettait :
 *
 *   « Connecting to 'http://127.0.0.1:8000/api/broadcasting/auth' violates the following
 *     Content Security Policy directive: "connect-src 'self' ws: wss: https: …" »
 *
 * suivi, dans le MÊME journal, de « [PosSyncService] fallback polling disabled ».
 *
 * Aujourd'hui la politique est en `report_only` : le navigateur signale et laisse passer.
 * Le jour où `CSP_ENFORCE_MODE` passe à `enforce`, il BLOQUE — la cuisine et le mur client
 * cessent de recevoir les commandes, le repli par sondage est déjà désactivé, et les écrans
 * continuent d'afficher « Mis à jour à l'instant » sur des données FIGÉES.
 *
 * Une panne silencieuse en plein service, armée par une ligne de configuration.
 *
 * DEUX CORRECTIONS DE NATURES DIFFÉRENTES, et la distinction est le cœur du sujet :
 *
 *   - `connect-src` : l'adresse absolue de `/api/broadcasting/auth` n'avait AUCUNE raison
 *     d'être. Rendue RELATIVE dans `bootstrap.js` — elle est alors résolue contre l'origine
 *     de la page, donc juste quel que soit l'hôte par lequel on ouvre l'écran (tablette sur
 *     l'IP du réseau local, `localhost`, domaine). C'est la correction de fond.
 *
 *   - `img-src` : là, l'adresse absolue est DÉLIBÉRÉE et documentée. La vitrine de la roue
 *     est servie par le site, sur un autre domaine, où une adresse relative pointerait vers
 *     un serveur qui n'a pas le fichier. C'est la politique qui devait admettre l'origine
 *     publiée par l'application.
 *
 * Élargir `connect-src` aurait « marché » aussi, et aurait laissé le défaut entier : il
 * aurait suffi d'ouvrir la caisse par une adresse non listée pour le rouvrir.
 */
class CspNeCoupePasLeTempsReelTest extends TestCase
{
    /**
     * LA CORRECTION DE FOND : plus aucune adresse absolue pour l'authentification temps réel.
     *
     * @test
     */
    public function l_authentification_du_temps_reel_est_relative(): void
    {
        $source = file_get_contents(resource_path('js/bootstrap.js'));
        $this->assertNotFalse($source, 'bootstrap.js illisible');

        $this->assertStringNotContainsString(
            '${_baseUrl}/api/broadcasting/auth',
            $source,
            'RÉGRESSION : l\'adresse d\'authentification du temps réel est de nouveau ABSOLUE. '
            . 'Dès que l\'écran est ouvert sur un hôte différent d\'APP_URL, ce n\'est plus la '
            . 'même origine — donc plus « self » — et le jour où la CSP bloque, la cuisine '
            . 'cesse de recevoir les commandes SANS que rien ne le signale.'
        );

        $this->assertMatchesRegularExpression(
            "/_authEndpoint\s*=\s*'\/api\/broadcasting\/auth'/",
            $source,
            'l\'adresse doit être relative, littéralement « /api/broadcasting/auth »'
        );
    }

    /**
     * Le garde ci-dessus ne vaut que si RIEN d'autre ne reconstruit une adresse absolue.
     *
     * @test
     */
    public function aucune_adresse_absolue_ne_subsiste_pour_la_diffusion(): void
    {
        $source = file_get_contents(resource_path('js/bootstrap.js'));

        $this->assertSame(
            0,
            preg_match_all('#\$\{[^}]*\}/api/broadcasting#', $source),
            'une adresse de diffusion est de nouveau construite par interpolation : c\'est le '
            . 'motif exact du défaut.'
        );
    }

    /** @test */
    public function la_politique_admet_l_origine_publiee_par_l_application(): void
    {
        $directives = (string) config('security.csp.directives');
        $this->assertNotSame('', $directives, 'directives CSP absentes');

        $origine = rtrim((string) config('app.url'), '/');
        if (! preg_match('#^https?://[^/]+$#', $origine)) {
            $this->markTestSkipped('APP_URL n\'est pas une origine simple sur cet environnement');
        }

        /*
         * On isole la directive `img-src` AVANT de chercher l'origine.
         *
         * Un premier jet cherchait l'origine dans la politique ENTIÈRE, et le mutant qui la
         * retirait d'`img-src` a SURVÉCU : `connect-src` contient déjà « http://localhost:9100 »,
         * dont « http://localhost » est un sous-texte. L'assertion passait sur une politique
         * où la correction avait pourtant disparu.
         */
        $this->assertMatchesRegularExpression(
            "/img-src[^;]*;/",
            $directives,
            'la directive img-src a disparu'
        );
        preg_match("/img-src([^;]*);/", $directives, $m);
        $imgSrc = $m[1] ?? '';

        $this->assertStringContainsString(
            $origine,
            $imgSrc,
            "RÉGRESSION : l'origine publiée par l'application ({$origine}) n'est plus admise "
            . 'par la politique. Les photos de lots de la roue — absolutisées DÉLIBÉRÉMENT, '
            . 'parce que la vitrine est servie par un autre domaine — redeviennent des '
            . 'violations : 8 par chargement, sur un écran qui se recharge tout seul en salle.'
        );
    }

    /**
     * L'origine est DÉRIVÉE, jamais recopiée : rien à resynchroniser au changement de domaine.
     *
     * @test
     */
    public function l_origine_est_derivee_et_non_recopiee(): void
    {
        $config = file_get_contents(config_path('security.php'));

        $this->assertStringContainsString(
            "env('APP_URL'",
            $config,
            'l\'origine doit être dérivée d\'APP_URL. Une valeur recopiée à la main se '
            . 'désynchronise au premier changement de domaine — et la panne qu\'elle rouvre '
            . 'est silencieuse.'
        );

        // Et une APP_URL absente ou malformée ne doit pas produire une directive cassée.
        $this->assertStringContainsString(
            'preg_match',
            $config,
            'la valeur doit être validée avant d\'entrer dans la politique : une APP_URL vide '
            . 'ou fantaisiste ne doit pas corrompre la directive.'
        );
    }

    /**
     * Le mode reste `report_only` par défaut : ce commit ne durcit rien, il rend le
     * durcissement POSSIBLE. C'est une décision d'exploitant, pas de développeur.
     *
     * @test
     */
    public function le_mode_reste_une_decision_d_exploitant(): void
    {
        $config = file_get_contents(config_path('security.php'));

        $this->assertStringContainsString(
            "env('CSP_ENFORCE_MODE', 'report_only')",
            $config,
            'le mode par défaut a changé. Passer en mode bloquant est une décision '
            . 'd\'exploitation, à prendre après une campagne complète vérifiée — pas un effet '
            . 'de bord d\'un correctif.'
        );
    }
}
