<?php

namespace App\Services\Fiscal;

use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\ZReport;
use Illuminate\Support\Carbon;

/**
 * [AUDIT-F-003 / Sub-task 5] ZReport cash enrichment — DECORATOR PATTERN.
 *
 * Pourquoi décorateur (et NON modification de ZReportService::aggregate) ?
 * - ZReportService est en frozen-zone (HANDOFF §6).
 * - La signature HMAC est calculée sur les champs originels — y ajouter
 *   cash_variance casserait la chaine sur les Z reports déjà signés en prod.
 * - Le décorateur calcule les champs cash en RUNTIME et les persiste sur
 *   z_reports (colonnes additives, hors signature) à titre d'observabilité
 *   comptable. Aucune régression possible sur la chain validation.
 *
 * Usage typique :
 *   - Au moment où l'admin consulte un Z report sur le dashboard, le résolveur
 *     appelle enrich() pour aller chercher les champs cash live (ou cached
 *     persistés post-close si on appelle persist() explicitement).
 *   - Au moment du close, l'orchestrateur peut optionnellement appeler
 *     persistOnClose() pour figer les valeurs cash dans la DB (read cache).
 *
 * Calcul cash_variance pour la fenêtre Z (from, to] sur la branche :
 *   - Sessions reconciled dont closed_at ∈ (from, to] : Σ variance (chacune ±)
 *   - cash_opening_amount : Σ opening_amount des sessions ouvertes dans la fenêtre
 *   - cash_closing_amount : Σ closing_amount des sessions reconciled dans la fenêtre
 *   - cash_movements_count : count(cash_movements) liés aux sessions de la fenêtre
 */
class ZReportCashEnrichmentService
{
    /**
     * Calcule les agrégats cash pour la fenêtre Z d'une branche.
     *
     * @return array{
     *     cash_opening_amount: float,
     *     cash_closing_amount: float,
     *     cash_variance: float,
     *     cash_movements_count: int,
     * }
     */
    public function aggregateForWindow(int $branchId, ?Carbon $from, Carbon $to): array
    {
        // Bypass BranchScope : on requête explicitement par branch_id depuis
        // un contexte service (cf Order.php pattern POS-9-H.2.5 / F-B5).
        $sessionsQuery = CashDrawerSession::query()
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('branch_id', $branchId);

        // Window définition cohérente avec ZReportService::aggregate :
        // half-open (from, to] sur la timestamp de fermeture des sessions
        // (= moment où l'argent physique est compté = fait fiscal).
        $reconciledInWindow = (clone $sessionsQuery)
            ->where('status', CashDrawerSession::STATUS_RECONCILED)
            ->where('closed_at', '<=', $to);
        if ($from) {
            $reconciledInWindow->where('closed_at', '>', $from);
        }

        $sessions = $reconciledInWindow->get();

        $openingTotal = (float) $sessions->sum(fn (CashDrawerSession $s) => (float) $s->opening_amount);
        $closingTotal = (float) $sessions->sum(fn (CashDrawerSession $s) => (float) ($s->closing_amount ?? 0));
        $varianceTotal = (float) $sessions->sum(fn (CashDrawerSession $s) => (float) ($s->variance ?? 0));

        $sessionIds = $sessions->pluck('id')->all();
        $movementsCount = empty($sessionIds)
            ? 0
            : CashMovement::query()
                ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->whereIn('cash_drawer_session_id', $sessionIds)
                ->count();

        return [
            'cash_opening_amount'  => round($openingTotal, 2),
            'cash_closing_amount'  => round($closingTotal, 2),
            'cash_variance'        => round($varianceTotal, 2),
            'cash_movements_count' => (int) $movementsCount,
        ];
    }

    /**
     * Enrichit un payload de Z report aggregé (output de
     * ZReportService::aggregate()) avec les agrégats cash. Ne modifie PAS la
     * signature originelle.
     *
     * @param  array<string,mixed>  $aggregates  output ZReportService::aggregate
     * @return array<string,mixed>
     */
    public function enrich(array $aggregates, int $branchId, ?Carbon $from, Carbon $to): array
    {
        $cash = $this->aggregateForWindow($branchId, $from, $to);
        return array_merge($aggregates, $cash);
    }

    /**
     * Persiste les champs cash sur un ZReport déjà closed (post-signature).
     * Idempotent : recalcule à partir des sessions sur la fenêtre du report.
     *
     * IMPORTANT : ne touche PAS aux champs signés (total_*, *_count). Ne
     * touche PAS la colonne signature. Donc verifySignature() reste valide.
     */
    public function persistForClosedReport(ZReport $report): ZReport
    {
        if ($report->status !== ZReport::STATUS_CLOSED) {
            return $report;
        }

        $closedAt = $report->closed_at;
        if (! $closedAt instanceof Carbon) {
            $closedAt = $closedAt ? Carbon::parse((string) $closedAt) : now();
        }

        $openedAt = $report->opened_at;
        if ($openedAt && ! $openedAt instanceof Carbon) {
            $openedAt = Carbon::parse((string) $openedAt);
        }

        // La fenêtre du Z report est (previous_close, this_close]. Pour le
        // tout premier Z d'une branche, on accepte tout l'historique (from=null).
        $previousClosedAt = ZReport::query()
            ->where('branch_id', $report->branch_id)
            ->where('status', ZReport::STATUS_CLOSED)
            ->where('id', '!=', $report->id)
            ->where('closed_at', '<=', $closedAt)
            ->orderByDesc('closed_at')
            ->value('closed_at');

        $from = $previousClosedAt ? Carbon::parse((string) $previousClosedAt) : null;

        $cash = $this->aggregateForWindow((int) $report->branch_id, $from, $closedAt);

        // Update direct sans déclencher events / observers — DB query only sur
        // les colonnes additives (post-signature, hors HMAC).
        ZReport::query()->whereKey($report->id)->update($cash);

        return $report->refresh();
    }
}
