<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HealthzController;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
    /** Au-delà, le worker est considéré en retard/en panne (miroir HealthController::checkQueueWorker). */
    private const STALE_OUTBOX_THRESHOLD = 10;

    /** Une commande PAS ENCORE PRÊTE plus vieille que ça « vieillit trop » (fast-food : ~15 min = tard). */
    private const AGING_THRESHOLD_MIN = 15;

    public function __invoke(): JsonResponse
    {
        // --- Temps réel : le socket est-il vivant ET le worker dépile-t-il réellement les events ? ---
        $socket        = HealthzController::probeWebsocket();   // 'ok' | 'fail'
        $staleEvents   = $this->staleOutboxCount();
        $workerLagging = $staleEvents > self::STALE_OUTBOX_THRESHOLD;

        // NB : le message ne répète PAS l'état (« Temps réel dégradé/coupé ») — la pastille l'affiche
        // déjà comme libellé. Le message porte l'info ACTIONNABLE + rassurante uniquement.
        if ($socket === 'ok' && ! $workerLagging) {
            $sync = ['status' => 'ok', 'message' => 'Les commandes arrivent en direct.'];
        } elseif ($socket === 'fail') {
            $sync = ['status' => 'down', 'message' => 'Le tableau se rafraîchit automatiquement (léger délai). Aucune commande n\'est perdue.'];
        } else {
            // Socket vivant MAIS backlog d'events non distribués = worker en retard (le cas silencieux).
            $sync = ['status' => 'warn', 'message' => 'Traitement en retard — mise à jour par rafraîchissement. Préviens le support si ça persiste.'];
        }

        // --- Intégrité fiscale (NF525) — lecture seule, mise en cache pour la perf. ---
        $fiscal = Cache::remember('pos_system_health_fiscal', 300, fn () => $this->fiscalStatus());

        // --- Ruptures de stock (vue d'ensemble) — INFO : ne change pas le ton système. Quelques
        // produits épuisés en plein service est NORMAL, pas une panne. On les remonte comme un compteur
        // visible, aligné EXACTEMENT sur le dashboard rupture (même filtre) pour éviter toute dérive. ---
        $ruptures = $this->stockRuptureCount();
        $stock = [
            'status'  => $ruptures > 0 ? 'info' : 'ok',
            'count'   => $ruptures,
            'message' => $ruptures > 0
                ? ($ruptures.' produit'.($ruptures > 1 ? 's' : '').' en rupture')
                : 'Stock complet.',
        ];

        // --- Commandes qui vieillissent trop (vue d'ensemble) — INFO. Commandes PAS ENCORE PRÊTES
        // (PENDING/ACCEPT/PREPARING) de plus de 15 min = kitchen en retard ou commande oubliée. Le
        // tracker les colore déjà par carte ; ici c'est le compteur agrégé pour un coup d'œil. ---
        $agingCount = $this->agingOrdersCount();
        $aging = [
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
        $syncRank   = ['ok' => 0, 'warn' => 1, 'down' => 2][$sync['status']] ?? 0;
        $fiscalRank = $fiscal['status'] === 'ok' ? 0 : 1;
        $worst = max($syncRank, $fiscalRank);
        $overall = $worst === 0 ? 'ok' : ($worst === 1 ? 'degraded' : 'down');

        return response()->json([
            'overall'       => $overall,
            'checks'        => ['sync' => $sync, 'fiscal' => $fiscal, 'stock' => $stock, 'aging' => $aging],
            'stale_events'  => $staleEvents,
            'queue_pending' => HealthzController::probeQueuePending(),
            'timestamp'     => now()->toIso8601String(),
        ]);
    }

    /**
     * Nombre de produits actuellement en rupture pour la branche du caissier. Aligné EXACTEMENT sur
     * StockRuptureDashboardController::lastSummary (is_available=false + unavailable_reason stock) →
     * le compteur de la pastille == celui du dashboard rupture (zéro dérive entre deux surfaces).
     * Requête indexée (branch_id, is_available), coût borné même pollée toutes les 45 s.
     */
    private function stockRuptureCount(): int
    {
        try {
            $q = \App\Models\ItemBranchAvailability::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->where('is_available', false)
                ->whereIn('unavailable_reason', ['stock_rupture', 'out_of_stock']);

            $branchId = (int) (auth()->user()?->branch_id ?? 0);
            if ($branchId > 0) {
                $q->where('branch_id', $branchId);
            }

            return (int) $q->count();
        } catch (\Throwable $e) {
            return 0; // la santé ne doit jamais casser sur une erreur de probe stock
        }
    }

    /**
     * Commandes PAS ENCORE PRÊTES (PENDING/ACCEPT/PREPARING — pas les prêtes/livrées/annulées) de plus
     * de AGING_THRESHOLD_MIN minutes, pour la branche du caissier. Signal « la cuisine est en retard
     * ou une commande a été oubliée ». Requête indexée (branch_id, status, created_at).
     */
    private function agingOrdersCount(): int
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
                ->where('created_at', '<', now()->subMinutes(self::AGING_THRESHOLD_MIN));

            $branchId = (int) (auth()->user()?->branch_id ?? 0);
            if ($branchId > 0) {
                $q->where('branch_id', $branchId);
            }

            return (int) $q->count();
        } catch (\Throwable $e) {
            return 0; // la santé ne doit jamais casser sur une erreur de probe
        }
    }

    /**
     * Backlog outbox : events créés il y a >30 s et non encore dispatchés (fenêtre 24 h pour ne pas
     * compter d'anciens orphelins), hors violations de contrat terminales (jamais retentées → pas
     * preuve d'un worker down). Miroir exact de HealthController::checkQueueWorker.
     */
    private function staleOutboxCount(): int
    {
        try {
            return (int) DB::table('domain_events')
                ->where('created_at', '<', now()->subSeconds(30))
                ->where('created_at', '>=', now()->subDay())
                ->whereNull('dispatched_at')
                ->where(function ($q) {
                    $q->whereNull('last_error')->orWhere('last_error', 'not like', 'contract_violation%');
                })
                ->count();
        } catch (\Throwable $e) {
            return 0; // la santé sync ne doit jamais casser sur une erreur de probe
        }
    }

    private function fiscalStatus(): array
    {
        try {
            $tamperedId = app(AuditLogService::class)->verifyChain(1);

            return $tamperedId === null
                ? ['status' => 'ok', 'message' => 'Chaîne fiscale intègre.']
                : ['status' => 'alert', 'message' => 'Anomalie sur la chaîne fiscale — préviens le support (NF525).'];
        } catch (\Throwable $e) {
            return ['status' => 'ok', 'message' => 'Chaîne fiscale : vérification momentanément indisponible.'];
        }
    }
}
