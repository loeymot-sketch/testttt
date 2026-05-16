<?php

namespace App\Services\Fiscal;

use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\OrderPayment;
use App\Models\PaymentTerminal;
use App\Models\ZReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
     * [Wave F F-2 / Sprint 1C] Ajout du breakdown par TPE et net_after_fees :
     *   - by_terminal : list of {terminal_id, name, gateway_type, cash_total,
     *                              card_total, transactions_count, fees_total}
     *   - net_after_fees : Σ amount payments dans la fenêtre − Σ fees_total
     *
     * Read-only : ce breakdown est calculé runtime, JAMAIS persisté sur le
     * Z report (n'altère pas la chaîne HMAC). Les paiements legacy avec
     * terminal_id NULL apparaissent sous une row synthétique "Sans TPE".
     *
     * @param  array<string,mixed>  $aggregates  output ZReportService::aggregate
     * @return array<string,mixed>
     */
    public function enrich(array $aggregates, int $branchId, ?Carbon $from, Carbon $to): array
    {
        $cash       = $this->aggregateForWindow($branchId, $from, $to);
        $terminals  = $this->aggregateByTerminal($branchId, $from, $to);
        $feesTotal  = array_sum(array_column($terminals, 'fees_total'));
        $grossTotal = array_sum(array_map(
            fn ($row) => (float) $row['cash_total'] + (float) $row['card_total'],
            $terminals
        ));

        return array_merge($aggregates, $cash, [
            'by_terminal'    => $terminals,
            'fees_total'     => round((float) $feesTotal, 2),
            'net_after_fees' => round($grossTotal - $feesTotal, 2),
        ]);
    }

    /**
     * Agrégation par TPE pour la fenêtre (from, to] (sur paid_at).
     *
     * Pour chaque PaymentTerminal référencé par un order_payment de la fenêtre,
     * retourne :
     *   - terminal_id, name, gateway_type
     *   - cash_total : Σ amount où mode = CASH
     *   - card_total : Σ amount où mode ∈ {CARD, MOBILE_BANKING, TICKET_RESTAURANT, OTHER}
     *   - transactions_count : nombre de order_payments
     *   - fees_total : (cash_total + card_total) * fee_percent/100
     *                  + transactions_count * fee_fixed
     *
     * Les paiements sans terminal_id (legacy ou COUNTER_DEFERRED) sont
     * regroupés sous une row synthétique {terminal_id: null, name: "Sans TPE",
     * gateway_type: "unknown"} avec fees_total = 0.
     *
     * Bypass BranchScope identique à aggregateForWindow (service-level contract).
     *
     * @return list<array{
     *     terminal_id: int|null,
     *     name: string,
     *     gateway_type: string,
     *     cash_total: float,
     *     card_total: float,
     *     transactions_count: int,
     *     fees_total: float,
     * }>
     */
    public function aggregateByTerminal(int $branchId, ?Carbon $from, Carbon $to): array
    {
        $paymentsQuery = OrderPayment::query()
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('paid_at', '<=', $to);

        if ($from) {
            $paymentsQuery->where('paid_at', '>', $from);
        }

        // Group by terminal_id (NULL bucket allowed) + mode bucket
        $rows = (clone $paymentsQuery)
            ->select([
                'terminal_id',
                DB::raw('SUM(CASE WHEN mode = ' . \App\Enums\PosPaymentMethod::CASH . ' THEN amount ELSE 0 END) as cash_total'),
                DB::raw('SUM(CASE WHEN mode <> ' . \App\Enums\PosPaymentMethod::CASH . ' THEN amount ELSE 0 END) as card_total'),
                DB::raw('COUNT(*) as transactions_count'),
            ])
            ->groupBy('terminal_id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $terminalIds = $rows->pluck('terminal_id')->filter()->unique()->values()->all();
        $terminals = empty($terminalIds)
            ? collect()
            : PaymentTerminal::query()
                ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->whereIn('id', $terminalIds)
                ->get()
                ->keyBy('id');

        $out = [];
        foreach ($rows as $row) {
            $terminalId = $row->terminal_id !== null ? (int) $row->terminal_id : null;
            $terminal   = $terminalId !== null ? $terminals->get($terminalId) : null;

            $cashTotal     = (float) $row->cash_total;
            $cardTotal     = (float) $row->card_total;
            $txCount       = (int) $row->transactions_count;
            $feesTotal     = 0.0;

            if ($terminal instanceof PaymentTerminal) {
                $feePercent = (float) $terminal->fee_percent;
                $feeFixed   = (float) $terminal->fee_fixed;
                $feesTotal  = (($cashTotal + $cardTotal) * $feePercent / 100.0)
                            + ($txCount * $feeFixed);
            }

            $out[] = [
                'terminal_id'        => $terminalId,
                'name'               => $terminal?->name ?? 'Sans TPE',
                'gateway_type'       => $terminal?->gateway_type ?? 'unknown',
                'cash_total'         => round($cashTotal, 2),
                'card_total'         => round($cardTotal, 2),
                'transactions_count' => $txCount,
                'fees_total'         => round($feesTotal, 2),
            ];
        }

        // Sort : terminals with id first (alphabetic name), then "Sans TPE" last
        usort($out, function ($a, $b) {
            if ($a['terminal_id'] === null && $b['terminal_id'] !== null) return 1;
            if ($a['terminal_id'] !== null && $b['terminal_id'] === null) return -1;
            return strcmp((string) $a['name'], (string) $b['name']);
        });

        return $out;
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
