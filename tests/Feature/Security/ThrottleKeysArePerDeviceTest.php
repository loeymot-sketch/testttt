<?php

/**
 * [GOAL-OPS-SWAP W4 2026-08-13 — « trop de requêtes », suite et fin]
 *
 * LE JUMEAU OUBLIÉ, HUIT FOIS.
 *
 * La veille, `throttle:api` a été passé au couple compte+appareil : la caisse,
 * l'écran cuisine et l'écran client cessaient de se partager un budget. Mais
 * le message « Trop de requêtes » s'affiche sur **n'importe quel** 429, et
 * HUIT autres compteurs étaient restés keyés `by($request->user()?->id)` :
 *
 *   pos-quote (120/min) · pos-order-create (60/min) · pos-order-update (120/min)
 *   print-queue-poll (240/min) · pos-loyalty-lookup (30/min)
 *   menu-availability (60/min) · kds-bump · kiosk-menu (60/min)
 *
 * Valeurs relevées EN PRODUCTION. Les deux qui mordent le plus vite :
 *   · `pos-order-create` = **60/min partagés** — c'est l'encaissement lui-même ;
 *   · `pos-loyalty-lookup` = **30/min partagés**.
 *
 * Deux écrans sous le même login se partageaient donc 60 encaissements/minute.
 *
 * POURQUOI PAS DE PLAFOND GLOBAL SUR CHACUN : inutile. `throttle:api` s'applique
 * à TOUTES les routes `/api/*` et porte déjà un plafond de 600/min par compte,
 * tous appareils confondus. Faire tourner `X-Device-Id` reste donc borné là.
 * Ajouter huit plafonds de plus serait du bruit sans protection supplémentaire.
 *
 * @group sentinel
 * @group security
 */

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ThrottleKeysArePerDeviceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les compteurs qui doivent distinguer les écrans d'un même compte.
     * Tout compteur keyé par compte seul y appartient — en ajouter un sans
     * l'inscrire ici, c'est reformer le jumeau.
     */
    private const COMPTEURS_PAR_APPAREIL = [
        'api',
        'kiosk-menu',
        'admin-mutation',
        'pos-quote',
        'pos-order-create',
        'pos-order-update',
        'print-queue-poll',
        'pos-loyalty-lookup',
        'menu-availability',
        'kds-bump',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function limites(string $compteur, ?User $u, string $appareil = 'ecran-A'): array
    {
        $r = Request::create('/api/quelconque', 'POST');
        $r->headers->set('X-Device-Id', $appareil);
        $r->server->set('REMOTE_ADDR', '198.51.100.4');
        if ($u) {
            $r->setUserResolver(fn () => $u);
        }

        $brut = (RateLimiter::limiter($compteur))($r);

        return is_array($brut) ? $brut : [$brut];
    }

    private function staff(): User
    {
        $u = User::factory()->create(['branch_id' => 1]);
        $u->assignRole('Admin');

        return $u;
    }

    public function test_chaque_compteur_distingue_deux_ecrans_du_meme_compte(): void
    {
        $u = $this->staff();
        $fautifs = [];

        foreach (self::COMPTEURS_PAR_APPAREIL as $compteur) {
            $a = $this->limites($compteur, $u, 'caisse-comptoir');
            $b = $this->limites($compteur, $u, 'ecran-cuisine');

            // La PREMIÈRE limite est celle de l'écran ; un éventuel plafond
            // global vient après et doit, lui, rester identique.
            if ((string) $a[0]->key === (string) $b[0]->key) {
                $fautifs[] = $compteur.'  (clé partagée : '.$a[0]->key.')';
            }
        }

        $this->assertSame(
            [],
            $fautifs,
            "Ces compteurs comptent encore les ÉCRANS ENSEMBLE. Deux écrans d'un même\n"
            ."compte se partagent leur budget, et le caissier voit « Trop de requêtes »\n"
            ."sans que personne n'ait rien fait de mal :\n  - "
            .implode("\n  - ", $fautifs)
        );
    }

    public function test_aucun_compteur_ne_donne_de_budget_a_un_anonyme_qui_invente_un_appareil(): void
    {
        $fautifs = [];

        foreach (self::COMPTEURS_PAR_APPAREIL as $compteur) {
            $a = $this->limites($compteur, null, 'appareil-invente-1');
            $b = $this->limites($compteur, null, 'appareil-invente-2');

            if ((string) $a[0]->key !== (string) $b[0]->key) {
                $fautifs[] = $compteur;
            }
        }

        $this->assertSame(
            [],
            $fautifs,
            "Un client SANS COMPTE obtient un budget neuf en changeant d'en-tête\n"
            ."X-Device-Id sur ces compteurs — c'est un contournement pur :\n  - "
            .implode("\n  - ", $fautifs)
        );
    }

    public function test_le_plafond_global_par_compte_borne_toujours_la_rotation(): void
    {
        $u = $this->staff();

        // `throttle:api` s'applique à TOUTES les routes /api/* : c'est lui qui
        // empêche la rotation d'identifiant de donner un débit illimité, quel
        // que soit le compteur spécifique traversé ensuite.
        $l = $this->limites('api', $u, 'peu-importe');

        $this->assertCount(2, $l, 'throttle:api doit garder ses DEUX limites.');
        $this->assertSame('u'.$u->id, (string) $l[1]->key);
        $this->assertGreaterThan($l[0]->maxAttempts, $l[1]->maxAttempts);
    }

    /**
     * Ces compteurs protègent des CODES À DEVINER. Un budget neuf par appareil
     * y offrirait un nombre d'essais illimité — l'inverse exact du but.
     *
     * ⚠️ CE BANC A ÉTÉ RENFORCÉ APRÈS UNE MUTATION SURVIVANTE. La première
     * version interrogeait avec un utilisateur ANONYME ; or la clé par appareil
     * retombe justement sur l'IP pour un anonyme. La mutation était donc
     * ÉQUIVALENTE, et le banc ne pouvait pas la voir. On interroge désormais
     * AUSSI avec un compte : c'est là que la différence existe, donc là qu'il
     * faut regarder.
     */
    public function test_les_compteurs_anti_bruteforce_restent_par_IP_intouches(): void
    {
        $u = $this->staff();

        foreach (['wheel-pin', 'daily-book-pin', 'mobile-stock-pin'] as $compteur) {
            // Cas anonyme — l'usage réel de ces écrans (ils vivent derrière un PIN,
            // pas derrière `auth`).
            $a = $this->limites($compteur, null, 'appareil-1');
            $b = $this->limites($compteur, null, 'appareil-2');
            $this->assertSame(
                (string) $a[0]->key,
                (string) $b[0]->key,
                "Le compteur anti-bruteforce {$compteur} est devenu contournable "
                ."en changeant d'en-tête d'appareil (anonyme)."
            );

            // Cas authentifié — c'est ici que la clé par appareil se distinguerait.
            $c = $this->limites($compteur, $u, 'appareil-1');
            $d = $this->limites($compteur, $u, 'appareil-2');
            $this->assertSame(
                (string) $c[0]->key,
                (string) $d[0]->key,
                "Le compteur anti-bruteforce {$compteur} distingue les appareils d'un "
                ."compte : chaque appareil obtient un lot d'essais neuf sur un CODE."
            );
            $this->assertStringNotContainsString(
                'appareil-1',
                (string) $c[0]->key,
                "La clé de {$compteur} embarque l'identifiant d'appareil fourni par le client."
            );
        }
    }
}
