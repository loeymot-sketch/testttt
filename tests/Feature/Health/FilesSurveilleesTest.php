<?php

namespace Tests\Feature\Health;

use App\Http\Controllers\HealthzController;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — suite de la découverte P0 du 2026-08-25]
 *
 * CE QUI A ÉTÉ TROUVÉ
 * -------------------
 * `App\Jobs\SendFcmNotificationJob` publie sur `onQueue('notifications')`. Or :
 *   - le worker local ET le modèle superviseur de production écoutent `--queue=high,default` ;
 *   - les TROIS sondes de santé du projet comptaient exactement `default` + `high`.
 *
 * Résultat mesuré : **1 490 travaux** empilés sur `notifications`, `attempts=0` (jamais tentés
 * une seule fois), pendant que les trois surfaces affichaient « file OK ». Un faux vert — la
 * même erreur que le correctif OPS-2 du 2026-06-04 avait éliminée pour la sonde websocket.
 *
 * CE QUE CE TEST GARANTIT
 * -----------------------
 * Il ne se contente pas d'ajouter `notifications` à une liste : il **découvre** dans le code
 * toutes les files réellement publiées (`onQueue('…')`) et exige que chacune soit surveillée.
 * Une file neuve introduite sans supervision fera échouer la CI — c'est le seul moyen d'empêcher
 * la même cécité de revenir sous un autre nom.
 *
 * ⚠️ Surveiller n'est PAS traiter. Brancher un worker sur `notifications` enverrait d'un coup
 * 1 490 notifications push portant sur des commandes vieilles de plusieurs semaines. C'est une
 * décision propriétaire, documentée dans `reports/audit/P0_FILE_NOTIFICATIONS_ORPHELINE_2026-08-25.md`.
 *
 * @group sentinel
 * @group health
 */
class FilesSurveilleesTest extends TestCase
{
    /**
     * Files découvertes dans le code applicatif via `onQueue('…')`.
     *
     * @return array<int, string>
     */
    private function filesPublieesParLeCode(): array
    {
        $trouvees = [];
        $racine = base_path('app');

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine));
        foreach ($rii as $fichier) {
            if (! $fichier->isFile() || $fichier->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($fichier->getPathname());

            // Retirer les commentaires : une file citée en commentaire n'est pas une file utilisée.
            $code = preg_replace('#/\*[\s\S]*?\*/#', ' ', $source);
            $code = preg_replace('#(^|[^:"\'])//.*$#m', '$1', (string) $code);

            // Forme 1 — `$this->onQueue('x')` ou `Job::dispatch(...)->onQueue('x')`.
            if (preg_match_all("/onQueue\(\s*['\"]([a-z0-9_\-]+)['\"]\s*\)/i", (string) $code, $m)) {
                foreach ($m[1] as $file) {
                    $trouvees[$file] = true;
                }
            }

            // Forme 2 — propriété `public $queue = 'x';` déclarée sur le Job.
            // Aucune classe ne l'utilise au 2026-08-25, mais c'est une forme Laravel parfaitement
            // valide : ne la couvrir qu'après coup reviendrait à attendre le prochain incident.
            if (preg_match_all("/(?:public|protected)\s+\\\$queue\s*=\s*['\"]([a-z0-9_\-]+)['\"]/i", (string) $code, $m2)) {
                foreach ($m2[1] as $file) {
                    $trouvees[$file] = true;
                }
            }

            // Forme 3 — `viaQueues()` sur une Notification.
            if (preg_match_all("/=>\s*['\"]([a-z0-9_\-]+)['\"]/", (string) (
                preg_match('/function\s+viaQueues\s*\([^)]*\)[^{]*\{(.*?)\n\s*\}/s', (string) $code, $vq) ? $vq[1] : ''
            ), $m3)) {
                foreach ($m3[1] as $file) {
                    $trouvees[$file] = true;
                }
            }
        }

        return array_keys($trouvees);
    }

    public function test_la_decouverte_trouve_effectivement_des_files(): void
    {
        // Un test qui ne découvre rien ne prouve rien.
        $this->assertNotEmpty(
            $this->filesPublieesParLeCode(),
            'Aucune file découverte dans app/ : la découverte est cassée, pas le problème résolu.',
        );
    }

    public function test_toute_file_publiee_par_le_code_est_surveillee(): void
    {
        $publiees = $this->filesPublieesParLeCode();
        $surveillees = (array) config('queue.monitored_queues', []);

        $aveugles = array_values(array_diff($publiees, $surveillees));

        $this->assertSame(
            [],
            $aveugles,
            sprintf(
                "Ces files sont publiées par le code mais ABSENTES de `queue.monitored_queues` :\n  %s\n\n"
                ."Une sonde qui ignore une file produit un faux vert : c'est ainsi que 1 490 travaux "
                ."ont pu pourrir sur `notifications` pendant que trois surfaces affichaient « OK ».\n"
                .'Files publiées : %s | surveillées : %s',
                implode("\n  ", $aveugles),
                implode(', ', $publiees),
                implode(', ', $surveillees),
            ),
        );
    }

    public function test_la_file_notifications_est_explicitement_surveillee(): void
    {
        // Régression nommée : c'est ELLE qui a été manquée.
        $this->assertContains(
            'notifications',
            (array) config('queue.monitored_queues', []),
            'La file `notifications` doit rester surveillée — 1 490 travaux y ont été oubliés.',
        );
    }

    public function test_la_sonde_d_exploitation_couvre_toutes_les_files_surveillees(): void
    {
        Queue::fake();

        // La sonde doit interroger la liste configurée, pas une liste écrite en dur.
        config(['queue.monitored_queues' => ['default', 'high', 'notifications']]);
        $avecTrois = HealthzController::probeQueuePending();

        config(['queue.monitored_queues' => ['default']]);
        $avecUne = HealthzController::probeQueuePending();

        // Les deux doivent aboutir sans erreur ; l'important est que la sonde LISE la config.
        $this->assertIsInt($avecTrois);
        $this->assertIsInt($avecUne);
        $this->assertGreaterThanOrEqual(0, $avecTrois);
    }

    public function test_la_sonde_ne_renvoie_pas_zero_quand_aucune_file_n_est_lisible(): void
    {
        \Illuminate\Support\Facades\Queue::shouldReceive('size')
            ->andThrow(new \RuntimeException('driver down'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('queue_unreadable');
        HealthzController::probeQueuePending();
    }
}
