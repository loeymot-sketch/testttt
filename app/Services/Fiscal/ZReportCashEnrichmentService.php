<?php

namespace App\Services\Fiscal;

use App\Models\AuditLog;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\DeliveryBoyCashMovement;
use App\Models\DeliveryBoyCashSession;
use App\Models\OrderPayment;
use App\Models\PaymentTerminal;
use App\Models\ZReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    // -------------------------------------------------------------------
    // [V1.0.2 Wave 6b-1.5 — 2026-05-18] DELIVERY CASH ENRICHMENT
    // -------------------------------------------------------------------
    //
    // Path A (composition extension) — Planner H plan §7 line 315 :
    //   "Extend ZReportCashEnrichmentService to also aggregate
    //    delivery_boy_cash_sessions + delivery_boy_cash_movements per branch
    //    per day".
    //
    // Frozen-zone discipline :
    //   - ZReportService.php (signed-aggregate path) UNCHANGED.
    //   - AuditLogService.php (HMAC chain writer) UNCHANGED.
    //   - audit_logs rows NEVER written from this service — only READ.
    //   - z_reports columns NEVER added/modified for delivery cash : the
    //     aggregation is RUNTIME (computed on demand). If the caller wants
    //     persistence, they emit a sidecar storage call (out of scope here
    //     — there is no ZReportClosed event in the codebase yet, so no
    //     listener is wired ; documented gap in evidence).
    // -------------------------------------------------------------------

    /**
     * [V1.0.2 Wave 6b-1.5] Aggregate doorstep cash totals for a branch's Z
     * window. Mirrors the half-open `(previousClose, closeAt]` window
     * convention used by `persistForClosedReport()` so POS + delivery rows
     * cover the exact same period.
     *
     * Counts ONLY sessions whose `closed_at` lies in the window — sessions
     * that opened before `from` but closed inside the window land in the
     * correct period. Sessions still OPEN are intentionally excluded (the
     * physical count has not happened yet → not yet a fiscal fact).
     *
     * @return array{
     *     delivery_cash_collected_total: float,
     *     delivery_cash_change_given_total: float,
     *     session_count: int,
     *     sessions: list<array{
     *         id: int,
     *         delivery_boy_id: int,
     *         opening_amount: float,
     *         closing_amount: float,
     *         expected_closing_amount: float,
     *         variance: float,
     *         status: string,
     *         opened_at: ?string,
     *         closed_at: ?string,
     *     }>,
     * }
     */
    public function enrichClose(int $branchId, Carbon $closeAt): array
    {
        // Window = (previousZClosedAt, closeAt]. First Z of a branch → from=null
        // (whole history). Mirror persistForClosedReport L249-257 verbatim.
        $previousClosedAt = ZReport::query()
            ->where('branch_id', $branchId)
            ->where('status', ZReport::STATUS_CLOSED)
            ->where('closed_at', '<', $closeAt)
            ->orderByDesc('closed_at')
            ->value('closed_at');

        $from = $previousClosedAt ? Carbon::parse((string) $previousClosedAt) : null;

        // Filter on `closed_at` (NOT `opened_at`) so a session opened pre-window
        // that closed during the window is correctly counted. Bypass BranchScope
        // explicitly + filter by branch_id — service-level contract.
        $sessionsQuery = DeliveryBoyCashSession::query()
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('branch_id', $branchId)
            ->whereIn('status', [
                DeliveryBoyCashSession::STATUS_CLOSED,
                DeliveryBoyCashSession::STATUS_RECONCILED,
            ])
            ->whereNotNull('closed_at')
            ->where('closed_at', '<=', $closeAt);

        if ($from) {
            $sessionsQuery->where('closed_at', '>', $from);
        }

        $sessions = $sessionsQuery->orderBy('closed_at')->get();

        if ($sessions->isEmpty()) {
            return [
                'delivery_cash_collected_total'    => 0.0,
                'delivery_cash_change_given_total' => 0.0,
                'session_count'                    => 0,
                'sessions'                         => [],
            ];
        }

        $sessionIds = $sessions->pluck('id')->all();

        // Aggregate movements for the in-window sessions. We sum the IN
        // (order_collect) and OUT (change_given) buckets separately so the
        // Z report can show gross collected vs change rendered.
        $movementAggregates = DeliveryBoyCashMovement::query()
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->whereIn('delivery_boy_cash_session_id', $sessionIds)
            ->select([
                'type',
                'direction',
                DB::raw('SUM(amount) as amount_sum'),
            ])
            ->groupBy('type', 'direction')
            ->get();

        $collectedTotal   = 0.0;
        $changeGivenTotal = 0.0;

        foreach ($movementAggregates as $row) {
            $amount = (float) $row->amount_sum;

            if (
                $row->type === DeliveryBoyCashMovement::TYPE_ORDER_COLLECT
                && $row->direction === DeliveryBoyCashMovement::DIRECTION_IN
            ) {
                $collectedTotal += $amount;
            } elseif (
                $row->type === DeliveryBoyCashMovement::TYPE_CHANGE_GIVEN
                && $row->direction === DeliveryBoyCashMovement::DIRECTION_OUT
            ) {
                $changeGivenTotal += $amount;
            }
            // adjustment / drawer_open / drawer_close intentionally excluded
            // from the "collected vs change" totals — they live in the raw
            // movements list (audit-side) but are not "fiscal cash" facts.
        }

        $sessionsOut = $sessions->map(fn (DeliveryBoyCashSession $s) => [
            'id'                      => (int) $s->id,
            'delivery_boy_id'         => (int) $s->delivery_boy_id,
            'opening_amount'          => round((float) $s->opening_amount, 2),
            'closing_amount'          => round((float) ($s->closing_amount ?? 0), 2),
            'expected_closing_amount' => round((float) ($s->expected_closing_amount ?? 0), 2),
            'variance'                => round((float) ($s->variance ?? 0), 2),
            'status'                  => (string) $s->status,
            'opened_at'               => $s->opened_at ? Carbon::parse((string) $s->opened_at)->toIso8601String() : null,
            'closed_at'               => $s->closed_at ? Carbon::parse((string) $s->closed_at)->toIso8601String() : null,
        ])->values()->all();

        return [
            'delivery_cash_collected_total'    => round($collectedTotal, 2),
            'delivery_cash_change_given_total' => round($changeGivenTotal, 2),
            'session_count'                    => $sessions->count(),
            'sessions'                         => $sessionsOut,
        ];
    }

    /**
     * [V1.0.2 Wave 6b-1.5] Raw movement query exposed for audit reconciliation.
     * Returns the DeliveryBoyCashMovement rows whose parent session closed in
     * (start, end] for the given branch — same window semantics as
     * `enrichClose()` to keep audit + aggregation consistent.
     *
     * @return Collection<int, DeliveryBoyCashMovement>
     */
    public function getDeliveryMovementsBetween(int $branchId, Carbon $start, Carbon $end): Collection
    {
        // Find sessions whose closed_at lies in (start, end] (matches the
        // enrichClose window semantics).
        $sessionIds = DeliveryBoyCashSession::query()
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('branch_id', $branchId)
            ->whereIn('status', [
                DeliveryBoyCashSession::STATUS_CLOSED,
                DeliveryBoyCashSession::STATUS_RECONCILED,
            ])
            ->whereNotNull('closed_at')
            ->where('closed_at', '>', $start)
            ->where('closed_at', '<=', $end)
            ->pluck('id')
            ->all();

        if (empty($sessionIds)) {
            return collect();
        }

        return DeliveryBoyCashMovement::query()
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->whereIn('delivery_boy_cash_session_id', $sessionIds)
            ->orderBy('id')
            ->get();
    }

    /**
     * [V1.0.2 Wave 6b-1.5] Cross-check : sum of
     * `cash.delivery.movement.recorded` audit_log entries for a branch in
     * (start, end] === sum of `delivery_boy_cash_movements` rows for the
     * same window (signed).
     *
     * NF525 integrity probe — READ-ONLY. Never writes to audit_logs nor to
     * delivery_boy_cash_movements. Designed for the closing-Z verification
     * path and for ad-hoc operator audits.
     *
     * Sign convention :
     *   - audit_log payload stores `amount` UNSIGNED + `direction` ∈ {in,out}.
     *     We sum signed (in=+, out=-) to match movement.signedAmount().
     *   - movements use DeliveryBoyCashMovement::signedAmount() — same sign.
     *
     * Discrepancies returned for forensics : each entry is keyed by source
     * (audit_log row vs movement row) so an operator can pinpoint the drift.
     *
     * @return array{
     *     ok: bool,
     *     audit_total: float,
     *     movements_total: float,
     *     audit_count: int,
     *     movements_count: int,
     *     discrepancies: list<array{kind: string, detail: string}>,
     * }
     */
    public function verifyConsistencyVsAuditLog(int $branchId, Carbon $start, Carbon $end): array
    {
        // Audit-side : query the per-branch audit chain by action namespace.
        // The audit_logs table is global (one chain per branch_id), not subject
        // to BranchScope — we filter by branch_id explicitly for clarity.
        $auditRows = AuditLog::query()
            ->where('branch_id', $branchId)
            ->where('action', 'cash.delivery.movement.recorded')
            ->where('created_at', '>', $start)
            ->where('created_at', '<=', $end)
            ->get();

        $auditTotal = 0.0;
        $auditCount = $auditRows->count();
        $auditMovementIds = [];

        foreach ($auditRows as $row) {
            $payload = (array) ($row->payload ?? []);
            $amount = (float) ($payload['amount'] ?? 0);
            $direction = (string) ($payload['direction'] ?? '');
            $movementId = isset($payload['movement_id']) ? (int) $payload['movement_id'] : null;

            $signed = ($direction === DeliveryBoyCashMovement::DIRECTION_IN ? 1.0 : -1.0) * $amount;
            $auditTotal += $signed;

            if ($movementId !== null) {
                $auditMovementIds[$movementId] = true;
            }
        }

        // Movement-side : raw DB rows for the same window, signed via the model.
        // We use `created_at` to mirror the audit-side timestamp predicate
        // (audit_logs.created_at is the canonical event time — the movement
        // row's created_at is written atomically inside the same transaction,
        // see DeliveryBoyCashSessionService::recordMovement L355-372).
        $movements = DeliveryBoyCashMovement::query()
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('created_at', '>', $start)
            ->where('created_at', '<=', $end)
            ->get();

        $movementsTotal = 0.0;
        $movementsCount = $movements->count();
        $movementIds = [];

        foreach ($movements as $m) {
            $movementsTotal += $m->signedAmount();
            $movementIds[(int) $m->id] = true;
        }

        $discrepancies = [];

        if (abs($auditTotal - $movementsTotal) > 0.005) {
            $discrepancies[] = [
                'kind'   => 'sum_mismatch',
                'detail' => sprintf(
                    'audit signed-sum=%.2f vs movements signed-sum=%.2f (delta=%.2f)',
                    $auditTotal,
                    $movementsTotal,
                    $auditTotal - $movementsTotal,
                ),
            ];
        }

        if ($auditCount !== $movementsCount) {
            $discrepancies[] = [
                'kind'   => 'count_mismatch',
                'detail' => sprintf(
                    'audit rows=%d vs movement rows=%d',
                    $auditCount,
                    $movementsCount,
                ),
            ];
        }

        // movement_id present in audit_logs but no row in delivery_boy_cash_movements
        foreach (array_keys($auditMovementIds) as $auditMid) {
            if (! isset($movementIds[$auditMid])) {
                $discrepancies[] = [
                    'kind'   => 'audit_orphan_movement_id',
                    'detail' => "audit_logs payload references movement_id={$auditMid} but no row found in delivery_boy_cash_movements",
                ];
            }
        }

        // movement row exists but no matching audit_logs payload references it
        foreach (array_keys($movementIds) as $mid) {
            if (! isset($auditMovementIds[$mid])) {
                $discrepancies[] = [
                    'kind'   => 'movement_missing_audit_row',
                    'detail' => "movement_id={$mid} has no `cash.delivery.movement.recorded` audit_log entry",
                ];
            }
        }

        return [
            'ok'              => empty($discrepancies),
            'audit_total'     => round($auditTotal, 2),
            'movements_total' => round($movementsTotal, 2),
            'audit_count'     => $auditCount,
            'movements_count' => $movementsCount,
            'discrepancies'   => $discrepancies,
        ];
    }
}
