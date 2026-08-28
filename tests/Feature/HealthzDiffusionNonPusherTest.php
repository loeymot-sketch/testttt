<?php

namespace Tests\Feature;

use App\Http\Controllers\HealthzController;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — T-5.3.2]
 *
 * CE TEST NE CORRIGE RIEN — IL VERROUILLE UN CHOIX DÉLIBÉRÉ.
 *
 * J'avais escaladé `HealthzController::probeWebsocket()` comme un défaut : « pour tout pilote de
 * diffusion différent de `pusher`, la sonde renvoie un état sans jamais ouvrir de connexion ».
 * Lecture complète faite, c'est **faux comme reproche** : le comportement est documenté, motivé,
 * et corrigé une première fois le 2026-06-04 (OPS-2) précisément pour le rendre honnête.
 *
 * Le contrat réel, tel qu'écrit dans le code :
 *   - pilote `null`            → 'fail'  (temps réel explicitement désactivé)
 *   - pilote `pusher`          → connexion TCP RÉELLE vers host:port, échec = 'fail'
 *   - `pusher` mal configuré   → 'fail'  (host vide ou port nul = vraie erreur de configuration)
 *   - autre pilote (log, …)    → 'ok' INFORMATIF — il n'y a pas de socket à sonder, et V1 LOCAL
 *                                tolère le repli par scrutation 30 s. `/api/health/ready` est la
 *                                grille stricte ; `/api/healthz` reste indulgente.
 *
 * CE QUI MANQUAIT VRAIMENT : aucun test n'épinglait ces branches. `tests/Feature/HealthzEndpointTest.php`
 * couvre la forme, l'énumération et les types — pas le choix de pilote. Une indulgence non testée
 * n'est plus un choix : c'est une dérive en attente. Ce fichier la garde délibérée.
 *
 * ⚠️ Si un jour V1 quitte le mono-poste, la branche « autre pilote → ok » devra être rediscutée.
 * Ce test échouera alors bruyamment, ce qui est exactement le but.
 */
class HealthzDiffusionNonPusherTest extends TestCase
{
    public function test_pilote_null_est_un_echec_franc(): void
    {
        Config::set('broadcasting.default', 'null');
        $this->assertSame('fail', HealthzController::probeWebsocket());

        Config::set('broadcasting.default', null);
        $this->assertSame('fail', HealthzController::probeWebsocket());
    }

    public function test_pilote_non_pusher_est_indulgent_et_c_est_assume(): void
    {
        // Choix documenté : pas de socket à sonder, le repli par scrutation couvre le temps réel.
        foreach (['log', 'redis', 'ably'] as $pilote) {
            Config::set('broadcasting.default', $pilote);
            $this->assertSame(
                'ok',
                HealthzController::probeWebsocket(),
                "Le pilote '{$pilote}' doit rester informatif-ok tant que V1 est mono-poste. "
                .'Si ce test casse, c\'est une DÉCISION à prendre, pas un correctif à appliquer.',
            );
        }
    }

    public function test_pusher_mal_configure_est_un_echec_et_non_une_indulgence(): void
    {
        Config::set('broadcasting.default', 'pusher');

        Config::set('broadcasting.connections.pusher.options.host', '');
        Config::set('broadcasting.connections.pusher.options.port', 6001);
        $this->assertSame('fail', HealthzController::probeWebsocket(), 'Hôte vide = vraie erreur de configuration.');

        Config::set('broadcasting.connections.pusher.options.host', '127.0.0.1');
        Config::set('broadcasting.connections.pusher.options.port', 0);
        $this->assertSame('fail', HealthzController::probeWebsocket(), 'Port nul = vraie erreur de configuration.');
    }

    public function test_pusher_injoignable_est_un_echec_reel(): void
    {
        // C'était le vrai défaut d'avant OPS-2 : un serveur temps réel mort remontait « sain ».
        Config::set('broadcasting.default', 'pusher');
        Config::set('broadcasting.connections.pusher.options.host', '127.0.0.1');
        Config::set('broadcasting.connections.pusher.options.port', 1);  // port fermé par construction

        $this->assertSame('fail', HealthzController::probeWebsocket());
    }

    public function test_la_grille_stricte_promise_par_le_commentaire_existe_vraiment(): void
    {
        // Le commentaire du contrôleur renvoie vers `/api/health/ready`. Un commentaire qui
        // promet une grille inexistante est pire qu'aucun commentaire.
        $this->assertTrue(
            collect(app('router')->getRoutes())->contains(fn ($r) => $r->uri() === 'api/health/ready'),
            'Le commentaire de HealthzController promet /api/health/ready : cette route doit exister.',
        );
    }
}
