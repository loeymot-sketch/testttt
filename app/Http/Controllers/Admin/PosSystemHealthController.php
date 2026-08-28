<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HealthzController;
use App\Services\Fiscal\AuditLogService;
use App\Traits\DefaultAccessModelTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * [CAISSE-HEALTH 2026-07-30] Surface santé SYSTÈME pour le poste de commande (caisse).
 *
 * L'opérateur voit d'un coup d'œil si le temps réel + la chaîne fiscale sont sains — SANS
 * attendre que des commandes se « perdent » en silence. Répond au mode de panne le plus
 * pernicieux (diagnostiqué à répétition) : soketi RESTE « connecté » alors que le worker de
 * queue est DOWN → aucun event n'arrive, mais le front croit le temps réel OK. On le détecte
 * ici via le backlog outbox (domain_events non-dispatchés), signal direct du worker en panne,
 * en plus du probe socket honnête.
 *
 * READ-ONLY (NF525-safe : 0 write, 0 schema). Réutilise les probes honnêtes partagés de
 * HealthzController (pas de dérive). La vérif chaîne fiscale (re-parcours curseur) est mise en
 * cache 5 min — coût borné même avec plusieurs écrans caisse qui pollent. Gate `permission:pos`
 * (donnée d'exploitation, aucun secret exposé).
 */
class PosSystemHealthController extends Controller
{
    // [REPLAN_8 2026-08-24] Résolution de branche CANONIQUE du projet : `default_access` d'abord,
    // puis `users.branch_id`. Lire `branch_id` brut faisait diverger la pastille du tracker
    // qu'elle surmonte — un utilisateur `branch_id=1` avec `default_id=2` voyait un tableau
    // filtré sur la branche 2 et une santé sondée sur la branche 1.
    use DefaultAccessModelTrait;

    /** Au-delà, le worker est considéré en retard/en panne (miroir HealthController::checkQueueWorker). */
    private const STALE_OUTBOX_THRESHOLD = 10;

    /** Une commande PAS ENCORE PRÊTE plus vieille que ça « vieillit trop » (fast-food : ~15 min = tard). */
    private const AGING_THRESHOLD_MIN = 15;

    /** Une réponse plus vieille que deux polls ne doit plus être considérée comme fraîche par le POS. */
    private const FISCAL_CACHE_SECONDS = 300;

    public function __invoke(): JsonResponse
    {
        $branchId = auth()->check() ? (int) $this->branch() : 0;
        if ($branchId <= 0) {
            // [REPLAN_8 2026-08-24] HTTP 200, pas 422.
            //
            // Un compte Admin vit légitimement en `branch_id = 0` (CLAUDE.md §9 : bypass
            // multi-branche). Mesuré sur cette base : 16 comptes Admin actifs sont dans ce cas.
            // Avec un 422, l'appel axios de la pastille partait en `catch` : elle affichait
            // « Contrôle indisponible » AMBRE À VIE, avec un bouton « Réessayer » incapable de
            // réussir, et le corps 422 soigneusement rédigé ci-dessous n'était rendu par
            // personne. On renvoie donc un 200 que la pastille sait afficher : quatre sondes
            // `unknown`, message actionnable, et surtout AUCUNE donnée d'une autre branche —
            // l'exactitude fiscale par branche reste entière, aucune sonde n'est exécutée.
            return response()->json([
                'overall' => 'degraded',
                'message' => 'Aucune succursale sélectionnée : choisis une succursale pour suivre la santé de la caisse.',
                'branch_required' => true,
                'checks' => [
                    'sync' => $this->unknownCheck('Temps réel : sélectionne une succursale pour lancer le contrôle.'),
                    'fiscal' => $this->unknownCheck('Chaîne fiscale : sélectionne une succursale pour lancer le contrôle.'),
                    'stock' => $this->unknownCountCheck('Stock : sélectionne une succursale pour lancer le contrôle.'),
                    'aging' => $this->unknownCountCheck('Commandes en attente : sélectionne une succursale pour lancer le contrôle.'),
                ],
                'stale_events' => null,
                'queue_pending' => null,
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        // --- Temps réel : le socket est-il vivant ET le worker dépile-t-il réellement les events ? ---
        $socket        = $this->websocketStatus(); // 'ok' | 'fail' | 'unknown'
        $staleEvents   = $this->staleOutboxCount($branchId);
        $queuePending  = $this->queuePendingCount();
        $workerLagging = $staleEvents !== null && $staleEvents > self::STALE_OUTBOX_THRESHOLD;

        // NB : le message ne répète PAS l'état (« Temps réel dégradé/coupé ») — la pastille l'affiche
        // déjà comme libellé. Le message porte l'info ACTIONNABLE + rassurante uniquement.
        // [REPLAN_8 2026-08-24] Le socket testé EN ÉCHEC est un fait dur : il passe AVANT les
        // inconnues. L'ordre précédent (`queue null` puis `socket unknown`) rétrogradait un
        // « temps réel coupé » certain (rang 2, rouge) en « indisponible » (rang 1, ambre) dès
        // qu'une sonde voisine tombait en même temps — or une panne de queue accompagne
        // typiquement une panne de socket. On effaçait la mauvaise nouvelle avec l'incertitude.
        if ($socket === 'fail') {
            $sync = [
                'status' => 'down',
                'message' => 'Le tableau bascule sur un rafraîchissement automatique (léger délai). Vérifie les nouvelles commandes et préviens le support si cet état persiste.',
            ];
        } elseif ($queuePending === null) {
            $sync = $this->unknownCheck('Contrôle de la file de traitement indisponible — vérifie les nouvelles commandes et préviens le support si cela persiste.');
        } elseif ($socket === 'unknown' || $staleEvents === null) {
            $sync = $this->unknownCheck('Contrôle temps réel momentanément indisponible — vérifie les écrans et préviens le support si cela persiste.');
        } elseif ($socket === 'ok' && ! $workerLagging) {
            $sync = ['status' => 'ok', 'message' => 'Les commandes arrivent en direct.'];
        } else {
            // Socket vivant MAIS backlog d'events non distribués = worker en retard (le cas silencieux).
            $sync = ['status' => 'warn', 'message' => 'Traitement en retard — mise à jour par rafraîchissement. Préviens le support si ça persiste.'];
        }

        // --- Intégrité fiscale (NF525) — lecture seule, mise en cache pour la perf. ---
        $fiscal = $this->cachedFiscalStatus($branchId);

        // --- Ruptures de stock (vue d'ensemble) — INFO : ne change pas le ton système. Quelques
        // produits épuisés en plein service est NORMAL, pas une panne. On les remonte comme un compteur
        // visible, aligné EXACTEMENT sur le dashboard rupture (même filtre) pour éviter toute dérive. ---
        $ruptures = $this->stockRuptureCount($branchId);
        $stock = $ruptures === null
            ? $this->unknownCountCheck('Contrôle stock momentanément indisponible.')
            : [
                'status'  => $ruptures > 0 ? 'info' : 'ok',
                'count'   => $ruptures,
                'message' => $ruptures > 0
                    ? ($ruptures.' produit'.($ruptures > 1 ? 's' : '').' en rupture')
                    : 'Stock complet.',
            ];

        // --- Commandes qui vieillissent trop (vue d'ensemble) — INFO. Commandes PAS ENCORE PRÊTES
        // (PENDING/ACCEPT/PREPARING) de plus de 15 min = kitchen en retard ou commande oubliée. Le
        // tracker les colore déjà par carte ; ici c'est le compteur agrégé pour un coup d'œil. ---
        $agingCount = $this->agingOrdersCount($branchId);
        $aging = $agingCount === null
            ? array_merge(
                $this->unknownCountCheck('Contrôle des commandes momentanément indisponible.'),
                ['threshold_min' => self::AGING_THRESHOLD_MIN]
            )
            : [
                'status'        => $agingCount > 0 ? 'info' : 'ok',
                'count'         => $agingCount,
                'threshold_min' => self::AGING_THRESHOLD_MIN,
                'message'       => $agingCount > 0
                    ? ($agingCount.' commande'.($agingCount > 1 ? 's' : '').' en attente > '.self::AGING_THRESHOLD_MIN.' min')
                    : 'Aucune commande en retard.',
            ];

        // Sévérité : une panne de SYNC (opérationnel — la caisse ne reçoit plus les commandes) peut
        // aller jusqu'à 'down' (rouge). Une alerte FISCALE (intégrité de fond ; l'opérateur ne peut
        // qu'alerter le support, il continue d'encaisser) plafonne à 'degraded' (ambre) — on ne veut
        // pas un écran caisse ROUGE en permanence pour un souci non-opérationnel.
        $syncRank   = ['ok' => 0, 'warn' => 1, 'unknown' => 1, 'down' => 2][$sync['status']] ?? 1;
        $fiscalRank = $fiscal['status'] === 'ok' ? 0 : 1;
        $stockRank  = $stock['status'] === 'unknown' ? 1 : 0;
        $agingRank  = $aging['status'] === 'unknown' ? 1 : 0;
        $worst = max($syncRank, $fiscalRank, $stockRank, $agingRank);
        $overall = $worst === 0 ? 'ok' : ($worst === 1 ? 'degraded' : 'down');

        return response()->json([
            'overall'       => $overall,
            'checks'        => ['sync' => $sync, 'fiscal' => $fiscal, 'stock' => $stock, 'aging' => $aging],
            'stale_events'  => $staleEvents,
            'queue_pending' => $queuePending,
            'timestamp'     => now()->toIso8601String(),
        ]);
    }

    protected function websocketStatus(): string
    {
        try {
            $status = HealthzController::probeWebsocket();

            return in_array($status, ['ok', 'fail'], true) ? $status : 'unknown';
        } catch (\Throwable $e) {
            report($e);

            return 'unknown';
        }
    }

    protected function queuePendingCount(): ?int
    {
        try {
            // Ne pas passer par HealthzController::probeQueuePending(): son contrat
            // historique transforme une panne du driver en 0. Pour la caisse, 0 est
            // une donnée métier (« aucune tâche »), pas un état d'erreur acceptable.
            //
            // [GOAL CONSOLIDATION 2026-08-25] POURQUOI PAS `queue.monitored_queues` ICI.
            //
            // Les sondes d'exploitation couvrent désormais TOUTES les files, `notifications`
            // comprise (1 490 travaux y dormaient, invisibles — voir
            // reports/audit/P0_FILE_NOTIFICATIONS_ORPHELINE_2026-08-25.md). Cette sonde-ci reste
            // volontairement limitée à `default` + `high`, et ce n'est pas un oubli :
            //
            //   - elle répond à UNE question du comptoir — « la cuisine va-t-elle voir ma
            //     commande ? » — à laquelle seules ces deux files participent ;
            //   - un caissier ne peut rien faire d'un retard de notifications push. Faire rougir
            //     sa pastille pour un problème hors de sa portée, c'est l'entraîner à l'ignorer,
            //     et le jour où elle rougit pour la cuisine il ne la regardera plus.
            //
            // La visibilité de `notifications` appartient à /api/healthz et /api/health, pas au
            // comptoir. Si ce partage change, il doit changer par décision, pas par recopie.
            return (int) Queue::size('default') + (int) Queue::size('high');
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Nombre de produits actuellement en rupture pour la branche du caissier. Aligné EXACTEMENT sur
     * StockRuptureDashboardController::lastSummary (is_available=false + unavailable_reason stock) →
     * le compteur de la pastille == celui du dashboard rupture (zéro dérive entre deux surfaces).
     * Requête indexée (branch_id, is_available), coût borné même pollée toutes les 45 s.
     */
    protected function stockRuptureCount(int $branchId): ?int
    {
        try {
            $q = \App\Models\ItemBranchAvailability::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->where('is_available', false)
                ->whereIn('unavailable_reason', ['stock_rupture', 'out_of_stock']);

            $q->where('branch_id', $branchId);

            return (int) $q->count();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Commandes PAS ENCORE PRÊTES (PENDING/ACCEPT/PREPARING — pas les prêtes/livrées/annulées) de plus
     * de AGING_THRESHOLD_MIN minutes, pour la branche du caissier. Signal « la cuisine est en retard
     * ou une commande a été oubliée ». Requête indexée (branch_id, status, created_at).
     */
    protected function agingOrdersCount(int $branchId): ?int
    {
        try {
            // Singulier §9 : SoftDeletes de retour → les commandes soft-deleted (ex. commandes
            // de test balayées) ne comptent plus comme backlog « aging » fantôme.
            $q = \App\Models\Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->whereIn('status', [
                    \App\Enums\OrderStatus::PENDING,
                    \App\Enums\OrderStatus::ACCEPT,
                    \App\Enums\OrderStatus::PREPARING,
                ])
                ->where('created_at', '<', now()->subMinutes(self::AGING_THRESHOLD_MIN))
                // [REPLAN_8 2026-08-24] Borne basse OBLIGATOIRE. Sans elle, toute commande jamais
                // sortie de PENDING/ACCEPT/PREPARING comptait À VIE : mesuré sur cette base,
                // 248 commandes « en retard » dont ZÉRO datant des dernières 24 h, la plus
                // ancienne du 2026-05-28. La pastille criait « ⏱️ 248 en retard » en permanence.
                // Un compteur qui hurle sans arrêt se fait ignorer aussi sûrement qu'un faux vert.
                // C'est exactement la décision déjà prise pour staleOutboxCount() plus bas
                // (fenêtre 24 h « pour ne pas compter d'anciens orphelins ») : on la porte ici.
                // Les traînards de plus de 24 h restent visibles en Historique ; ce compteur-ci
                // répond à « la cuisine est-elle en retard MAINTENANT ».
                ->where('created_at', '>=', now()->subDay());

            $q->where('branch_id', $branchId);

            return (int) $q->count();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Backlog outbox : events créés il y a >30 s et non encore dispatchés (fenêtre 24 h pour ne pas
     * compter d'anciens orphelins), hors violations de contrat terminales (jamais retentées → pas
     * preuve d'un worker down). Miroir exact de HealthController::checkQueueWorker.
     */
    protected function staleOutboxCount(int $branchId): ?int
    {
        try {
            return (int) DB::table('domain_events')
                ->where('created_at', '<', now()->subSeconds(30))
                ->where('created_at', '>=', now()->subDay())
                ->where('branch_id', $branchId)
                ->whereNull('dispatched_at')
                ->where(function ($q) {
                    $q->whereNull('last_error')->orWhere('last_error', 'not like', 'contract_violation%');
                })
                ->count();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    protected function fiscalStatus(int $branchId): array
    {
        try {
            $tamperedId = app(AuditLogService::class)->verifyChain($branchId);

            return $tamperedId === null
                ? ['status' => 'ok', 'message' => 'Chaîne fiscale intègre.']
                : ['status' => 'alert', 'message' => 'Anomalie sur la chaîne fiscale — préviens le support (NF525).'];
        } catch (\Throwable $e) {
            report($e);

            return $this->unknownCheck('Chaîne fiscale : vérification momentanément indisponible — préviens le support.');
        }
    }

    private function cachedFiscalStatus(int $branchId): array
    {
        $key = 'pos_system_health_fiscal:'.$branchId;
        try {
            $cached = Cache::get($key);
        } catch (\Throwable $e) {
            // Le cache est un accélérateur, jamais l'autorité fiscale. Une panne
            // de lecture déclenche donc la sonde live au lieu d'un HTTP 500.
            report($e);
            $cached = null;
        }
        if (is_array($cached) && isset($cached['status'], $cached['message'])) {
            return $cached;
        }

        $status = $this->fiscalStatus($branchId);
        // Une panne transitoire doit être retestée au prochain poll, pas mémorisée cinq minutes.
        if ($status['status'] !== 'unknown') {
            try {
                Cache::put($key, $status, self::FISCAL_CACHE_SECONDS);
            } catch (\Throwable $e) {
                // La sonde live reste valide même si sa mise en cache échoue.
                report($e);
            }
        }

        return $status;
    }

    private function unknownCheck(string $message): array
    {
        return ['status' => 'unknown', 'message' => $message];
    }

    private function unknownCountCheck(string $message): array
    {
        return ['status' => 'unknown', 'count' => null, 'message' => $message];
    }
}
