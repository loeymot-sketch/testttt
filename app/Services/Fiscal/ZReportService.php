<?php

namespace App\Services\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use App\Models\ZReport;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * [POS-9.4.7 / POS-GA-F-01]
 *
 * Opens and closes fiscal Z reports. "Open" reserves the next sequence
 * number for a branch; "close" freezes the aggregates over the period
 * (since the previous close, bounded by `opened_at`) and signs them
 * with an HMAC chained on top of the previous Z report of the same
 * branch.
 *
 * Concurrency: both operations run under `Cache::lock('z_report_b{n}')`
 * so two cashiers pressing "close Z" simultaneously can never create
 * duplicate sequence numbers or double-count orders.
 */
class ZReportService
{
    private const LOCK_TTL_SECONDS     = 10;
    private const LOCK_ACQUIRE_SECONDS = 4;

    private FiscalSealingService $sealing;
    private ?FiscalChainValidator $chainValidator = null;

    public function __construct(
        private ?ConnectionInterface $connection = null,
        ?FiscalSealingService $sealing = null
    ) {
        $this->connection = $connection ?? DB::connection();
        $this->sealing = $sealing ?? app(FiscalSealingService::class);
        // [P11-FZH] FiscalChainValidator NOT injected via constructor to avoid
        // container resolution cycle (validator → service → validator). Lazy
        // resolved through chainValidator() helper instead.
    }

    /**
     * [P11-FZH] Allow tests to override the validator without touching the
     * container (avoids cycle). Production resolves through app() lazily.
     */
    public function setChainValidator(FiscalChainValidator $validator): void
    {
        $this->chainValidator = $validator;
    }

    /**
     * [P11-FZH] Lazy-resolve the chain validator. Avoids a constructor-cycle
     * with FiscalChainValidator (which itself depends on ZReportService).
     */
    private function chainValidator(): FiscalChainValidator
    {
        return $this->chainValidator ??= app(FiscalChainValidator::class);
    }

    /**
     * Open a new Z report for a branch. Fails if one is already open.
     */
    public function open(int $branchId, User|int|null $openedBy = null): ZReport
    {
        if ($branchId <= 0) {
            throw new \InvalidArgumentException('ZReportService::open requires a positive branch_id.');
        }

        $openedById = $openedBy instanceof User ? $openedBy->id : $openedBy;
        $lockKey    = sprintf('z_report_b%d', $branchId);
        $lock       = Cache::lock($lockKey, self::LOCK_TTL_SECONDS);

        try {
            if (!$lock->block(self::LOCK_ACQUIRE_SECONDS)) {
                throw new RuntimeException("ZReportService: cannot acquire {$lockKey}.");
            }

            // [W8.C-P1 / P-MEGA-22 Pilier 1]
            // Validate the historical chain before reserving a new sequence.
            $this->verifyChain($branchId);

            // [P11-FZH / F-VERIFY-08-01] Extended chain validation:
            // re-run Z chain in strict mode + bounded audit_logs tail walk.
            // Feature flag fiscal.chain_validation_enabled (default true) gates
            // the audit-chain extension; the legacy Z chain check above is
            // always executed.
            $this->chainValidator()->assertChainIntegrity($branchId);

            // [P11-FZH] Detect stuck Z STATUS_CLOSING state (recovery hint).
            $this->assertNoPendingClose($branchId);

            return $this->connection->transaction(function () use ($branchId, $openedById) {
                $existingOpen = ZReport::query()
                    ->where('branch_id', $branchId)
                    ->where('status', ZReport::STATUS_OPEN)
                    ->first();
                if ($existingOpen) {
                    throw new RuntimeException(
                        "ZReportService: branch {$branchId} already has an OPEN Z report "
                        . "(id={$existingOpen->id}, sequence_no={$existingOpen->sequence_no})."
                    );
                }

                $nextSeq = ((int) ZReport::query()
                    ->where('branch_id', $branchId)
                    ->max('sequence_no')) + 1;

                $report = ZReport::create([
                    'branch_id'   => $branchId,
                    'sequence_no' => $nextSeq,
                    'opened_at'   => Carbon::now(),
                    'opened_by'   => $openedById,
                    'status'      => ZReport::STATUS_OPEN,
                ]);

                // [POS-9-H.3.2 / F-C7]
                \Illuminate\Support\Facades\Log::channel('fiscal')->info('z_report.open', [
                    'z_report_id' => $report->id,
                    'branch_id'   => $branchId,
                    'sequence_no' => $nextSeq,
                    'opened_by'   => $openedById,
                ]);

                return $report;
            });
        } finally {
            try { $lock->release(); } catch (\Throwable $e) {}
        }
    }

    /**
     * [P11-FZH] Detect a Z report stuck in a transitional CLOSING state for
     * more than 15s (i.e. crash mid-close). Throws so a new open() refuses
     * to compete on a half-closed branch — manual operator inspection.
     *
     * STATUS_CLOSING is reserved for a future plan (write path not yet
     * activated). This method is a no-op until then.
     */
    private function assertNoPendingClose(int $branchId): void
    {
        if (!defined(ZReport::class . '::STATUS_CLOSING')) {
            return;
        }

        $staleClosing = ZReport::query()
            ->where('branch_id', $branchId)
            ->where('status', ZReport::STATUS_CLOSING)
            ->where('updated_at', '<', \Illuminate\Support\Carbon::now()->subSeconds(15))
            ->first();

        if ($staleClosing) {
            Log::channel('fiscal')->error('z_report.stuck_closing', [
                'event'        => 'fiscal.z_report.stuck_closing',
                'z_report_id'  => $staleClosing->id,
                'branch_id'    => $branchId,
                'stuck_since'  => $staleClosing->updated_at?->toIso8601String(),
            ]);

            throw new RuntimeException(sprintf(
                'ZReportService: branch %d has Z (id=%d) stuck CLOSING >15s. '
                . 'Manual operator intervention required.',
                $branchId,
                $staleClosing->id
            ));
        }
    }

    /**
     * Close the currently open Z report for a branch. Aggregates, signs,
     * persists. Rejects a second close attempt.
     */
    public function close(int $branchId, User|int|null $closedBy = null): ZReport
    {
        $started = microtime(true);
        $context = ['op' => 'z_report.close', 'branch_id' => $branchId];

        try {
            if ($branchId <= 0) {
                throw new \InvalidArgumentException('ZReportService::close requires a positive branch_id.');
            }

            $closedById = $closedBy instanceof User ? $closedBy->id : $closedBy;
            $lockKey    = sprintf('z_report_b%d', $branchId);
            $lock       = Cache::lock($lockKey, self::LOCK_TTL_SECONDS);

            try {
                if (!$lock->block(self::LOCK_ACQUIRE_SECONDS)) {
                    throw new RuntimeException("ZReportService: cannot acquire {$lockKey}.");
                }

                // [W8.C-P1 / P-MEGA-22 Pilier 1]
                // Validate the historical chain before computing a new close signature.
                $this->verifyChain($branchId);

                $result = $this->connection->transaction(function () use ($branchId, $closedById) {
                    $open = ZReport::query()
                        ->where('branch_id', $branchId)
                        ->where('status', ZReport::STATUS_OPEN)
                        ->lockForUpdate()
                        ->first();

                    if (!$open) {
                        throw new RuntimeException("ZReportService: no open Z report to close for branch {$branchId}.");
                    }

                    $closedAt   = Carbon::now();

                    // [iter14 SPECIALIST-3 / FISCAL-ORPHAN-RETRY]
                    // Pre-check: warn the operator if any kiosk-paid order in
                    // the closing window is still missing its NF525 fiscal
                    // sequence (i.e. the retry cron has not caught up). These
                    // rows are correctly excluded from the aggregate (fiscal
                    // gap-prevention invariant), but a silent exclusion at Z
                    // close is the exact failure mode the iter13 audit
                    // flagged. Surfacing it here gives ops a chance to delay
                    // the close until the retry succeeds.
                    //
                    // Bound to (opened_at, closedAt] to match the aggregate's
                    // half-open window so we don't re-warn forever about
                    // historical orphans that predate the retry mechanism.
                    $this->warnOnOrphanedPaidOrders($branchId, $open->opened_at, $closedAt);

                    $aggregates = $this->aggregate($branchId, $open->opened_at, $closedAt);

                    $prevHash = (string) (ZReport::query()
                        ->where('branch_id', $branchId)
                        ->where('status', ZReport::STATUS_CLOSED)
                        ->orderByDesc('sequence_no')
                        ->value('signature') ?? '');

                    $signature = $this->sign($branchId, $prevHash, $open->sequence_no, $aggregates, $closedAt);

                    $open->forceFill(array_merge($aggregates, [
                        'closed_at' => $closedAt,
                        'closed_by' => $closedById,
                        'prev_hash' => $prevHash !== '' ? $prevHash : null,
                        'signature' => $signature,
                        'status'    => ZReport::STATUS_CLOSED,
                    ]))->save();

                    // [POS-9-H.3.2 / F-C7]
                    // Full numeric snapshot — the signature prefix is enough
                    // to cross-reference the HMAC without leaking the full
                    // secret-derived hash in logs.
                    \Illuminate\Support\Facades\Log::channel('fiscal')->info('z_report.close', [
                        'z_report_id'     => $open->id,
                        'branch_id'       => $branchId,
                        'sequence_no'     => $open->sequence_no,
                        'closed_by'       => $closedById,
                        'total_ttc'       => (float) $aggregates['total_ttc'],
                        'total_ht'        => (float) $aggregates['total_ht'],
                        'total_tva'       => (float) $aggregates['total_tva'],
                        'order_count'     => (int)  $aggregates['order_count'],
                        'cancel_count'    => (int)  $aggregates['cancel_count'],
                        'refund_count'    => (int)  $aggregates['refund_count'],
                        'signature_prefix'=> substr($signature, 0, 12),
                    ]);

                    return $open->refresh();
                });
                $context['outcome'] = 'success';

                return $result;
            } finally {
                try { $lock->release(); } catch (\Throwable $e) {}
            }
        } catch (\Throwable $e) {
            $context['outcome'] = 'failure';
            $context['exception_class'] = get_class($e);
            throw $e;
        } finally {
            $context['duration_ms'] = (int) ((microtime(true) - $started) * 1000);
            try {
                Log::channel('stack')->info('[FISCAL_TIMING]', $context);
            } catch (\Throwable $logEx) {
            }
        }
    }

    /**
     * Recompute aggregates for the period. Exposed so XReport (POS-9.4.8)
     * can reuse exactly the same algorithm without drift.
     *
     * Returns keys compatible with the `z_reports` table columns plus a
     * few derived metrics used for signing.
     *
     * @return array<string,mixed>
     */
    public function aggregate(int $branchId, ?Carbon $from, Carbon $to): array
    {
        // [POS-9-H.2.4 / F-C4]
        // Only orders that received a fiscal_sequence_no are NF525 fiscal
        // events. Legacy orders (pre-POS-9.4 migration) and orders whose
        // sequence allocation failed must NOT be aggregated into a Z,
        // otherwise the signed totals would include untraceable rows
        // and silently break the "sequential, gap-free" invariant.
        //
        // [POS-9-H.2.5 / F-B5]
        // withoutGlobalScopes() drops BranchScope AND SoftDeletingScope
        // — undesirable for soft-deletes, which must stay excluded so
        // a cancelled-then-restored order is never double-counted.
        // Scope reset to `BranchScope` only.
        // [POS-9-H.2.6 / F-B3]
        // Half-open interval (from, to]:
        //   - lower bound STRICT (>): an order created at exactly $from
        //     was already counted in the previous window (whose upper
        //     bound is <= $from). Counting it again here would produce
        //     a double-count on the boundary instant — a direct violation
        //     of "every receipt in exactly one Z".
        //   - upper bound INCLUSIVE (<=): the close timestamp itself
        //     must be absorbed (it cannot float to the next Z whose lower
        //     bound will be >$to).
        // When $from is null (first Z ever for this branch), the lower
        // bound is open (we accept the entire history up to $to).
        $baseQuery = Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('branch_id', $branchId)
            ->whereNotNull('fiscal_sequence_no')
            ->where('payment_status', '!=', PaymentStatus::UNPAID);

        $windowQuery = (clone $baseQuery)
            ->where('created_at', '<=', $to);
        if ($from) {
            $windowQuery->where('created_at', '>', $from);
        }

        $terminalStatuses = [
            OrderStatus::CANCELED,
            OrderStatus::REJECTED,
            OrderStatus::RETURNED,
        ];

        $orders = (clone $windowQuery)
            ->whereNotIn('status', $terminalStatuses)
            ->get();

        $totalTtc = 0.0;
        $totalHt  = 0.0;
        $totalTva = 0.0;
        $byMethod = [];
        $byTaxRate = [];
        $orderCount = 0;

        foreach ($orders as $o) {
            $this->applyOrderToTotals($o, 1, $totalTtc, $totalHt, $totalTva, $byMethod);
            $orderCount++;
        }

        // M-08 policy:
        // - pre-Z refund/void rows are evidence counters only; they do
        //   not create positive revenue in the closing Z;
        // - post-Z refund/void rows are negative adjustments in the next
        //   Z window, keyed by updated_at because status transitions are
        //   persisted on the same order row.
        $preZCancelCount = (clone $windowQuery)
            ->whereIn('status', [OrderStatus::CANCELED, OrderStatus::REJECTED])
            ->count();
        $preZRefundCount = (clone $windowQuery)
            ->where('status', OrderStatus::RETURNED)
            ->count();

        $postZCanceled = collect();
        $postZReturned = collect();
        if ($from) {
            $postZAdjustmentQuery = (clone $baseQuery)
                ->where('created_at', '<=', $from)
                ->where('updated_at', '>', $from)
                ->where('updated_at', '<=', $to);

            $postZCanceled = (clone $postZAdjustmentQuery)
                ->whereIn('status', [OrderStatus::CANCELED, OrderStatus::REJECTED])
                ->get();
            $postZReturned = (clone $postZAdjustmentQuery)
                ->where('status', OrderStatus::RETURNED)
                ->get();

            foreach ($postZCanceled->concat($postZReturned) as $o) {
                $this->applyOrderToTotals($o, -1, $totalTtc, $totalHt, $totalTva, $byMethod);
            }
        }

        $cancelCount = $preZCancelCount + $postZCanceled->count();
        $refundCount = $preZRefundCount + $postZReturned->count();

        // [POS-9-H.2.8 / F-B6]
        // Populate total_by_tax_rate by summing order_items.tax_amount
        // grouped by tax_rate, scoped to the exact same order set we
        // just aggregated. Using order_items (the already-persisted,
        // server-recomputed pricing from POS-9.1.8) avoids re-running
        // PricingService and guarantees consistency with the individual
        // receipts that are referenced by fiscal_sequence_no.
        $byTaxRate = [];
        $byTaxRate = $this->taxBreakdownForOrders($orders->pluck('id')->all(), 1, $byTaxRate);
        $adjustmentOrderIds = $postZCanceled->concat($postZReturned)->pluck('id')->all();
        $byTaxRate = $this->taxBreakdownForOrders($adjustmentOrderIds, -1, $byTaxRate);
        $byTaxRate = array_map(fn ($v) => round((float) $v, 2), $byTaxRate);
        ksort($byTaxRate);

        // Normalise rounding to 2 decimals so the signed aggregates are stable.
        $byMethod = array_map(fn ($v) => round((float) $v, 2), $byMethod);
        ksort($byMethod);

        return [
            'total_ttc'         => round($totalTtc, 2),
            'total_ht'          => round($totalHt,  2),
            'total_tva'         => round($totalTva, 2),
            'total_by_method'   => $byMethod,
            'total_by_tax_rate' => $byTaxRate,
            'order_count'       => $orderCount,
            'cancel_count'      => $cancelCount,
            'refund_count'      => $refundCount,
        ];
    }

    /**
     * Independent verifier: recomputes the signature of a closed Z report
     * and returns true iff it still matches what was persisted.
     */
    public function verifySignature(ZReport $report): bool
    {
        if ($report->status !== ZReport::STATUS_CLOSED || !$report->signature) {
            return false;
        }

        $expected = $this->computeSignature($report, (string) ($report->prev_hash ?? ''));
        return hash_equals($expected, (string) $report->signature);
    }

    /**
     * [W8.C-P1 / P-MEGA-22 Pilier 1] Verify the integrity of the full
     * historical Z-report chain for a branch.
     *
     * @return array{
     *     valid: bool,
     *     first_z_id: int|null,
     *     last_z_id: int|null,
     *     count: int,
     *     errors: array<int, array{z_id: int, kind: string, expected: string, actual: string}>
     * }
     */
    public function verifyChain(int $branchId, ?bool $strict = null): array
    {
        if ($strict === null) {
            $configuredStrict = Config::get('fiscal.verify_chain_strict');
            // [W8.C-P1 REM F-S1] Cast safe pour env strings : (bool) 'false' === true (piège PHP).
            // FILTER_NULL_ON_FAILURE retombe sur l'env Laravel si valeur invalide ('truc', '').
            if (is_null($configuredStrict)) {
                $strict = app()->environment('production');
            } elseif (is_bool($configuredStrict)) {
                $strict = $configuredStrict;
            } else {
                $parsed = filter_var($configuredStrict, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $strict = $parsed ?? app()->environment('production');
            }
        }

        $genesisPrevHash = (string) Config::get('fiscal.genesis_prev_hash', str_repeat('0', 64));
        $zReports = ZReport::query()
            ->where('branch_id', $branchId)
            ->where('status', ZReport::STATUS_CLOSED)
            ->orderBy('id', 'asc')
            ->get();

        $result = [
            'valid' => true,
            'first_z_id' => $zReports->first()?->id,
            'last_z_id' => null,
            'count' => 0,
            'errors' => [],
        ];

        if ($zReports->isEmpty()) {
            return $result;
        }

        $previousSignature = null;
        $expectedSequenceNo = null;

        foreach ($zReports as $zReport) {
            $actualPrevHash = (string) ($zReport->prev_hash ?? '');
            $expectedPrevHash = $previousSignature ?? $genesisPrevHash;

            $chainMatches = $previousSignature === null
                ? ($actualPrevHash === '' || hash_equals($expectedPrevHash, $actualPrevHash))
                : hash_equals($expectedPrevHash, $actualPrevHash);

            if (!$chainMatches) {
                $result['valid'] = false;
                $result['errors'][] = [
                    'z_id' => (int) $zReport->id,
                    'kind' => 'chain_break',
                    'expected' => $expectedPrevHash,
                    'actual' => $actualPrevHash,
                ];
            }

            if ($expectedSequenceNo !== null && (int) $zReport->sequence_no !== $expectedSequenceNo) {
                $result['valid'] = false;
                $result['errors'][] = [
                    'z_id' => (int) $zReport->id,
                    'kind' => 'sequence_gap',
                    'expected' => (string) $expectedSequenceNo,
                    'actual' => (string) $zReport->sequence_no,
                ];
            }

            $recomputedSignature = $this->computeSignature($zReport, $actualPrevHash);
            if (!hash_equals((string) $zReport->signature, $recomputedSignature)) {
                $result['valid'] = false;
                $result['errors'][] = [
                    'z_id' => (int) $zReport->id,
                    'kind' => 'signature_mismatch',
                    'expected' => $recomputedSignature,
                    'actual' => (string) $zReport->signature,
                ];
            }

            $previousSignature = (string) $zReport->signature;
            $expectedSequenceNo = (int) $zReport->sequence_no + 1;
            $result['last_z_id'] = (int) $zReport->id;
            $result['count']++;
        }

        if (!$result['valid']) {
            try {
                Log::channel('fiscal')->error('NF525 Z-chain verification failed', [
                    'event' => 'fiscal.z_chain.verification_failed',
                    'branch_id' => $branchId,
                    'first_z_id' => $result['first_z_id'],
                    'last_z_id' => $result['last_z_id'],
                    'count' => $result['count'],
                    'errors' => $result['errors'],
                    'mode' => $strict ? 'strict' : 'degraded',
                ]);
            } catch (\Throwable $e) {
                Log::warning('[W8.C-P1] Fiscal log channel failed: ' . $e->getMessage());
            }

            if ($strict) {
                throw new RuntimeException(sprintf(
                    'NF525 Z-chain verification failed for branch %d (count=%d, errors=%d). See fiscal log for details.',
                    $branchId,
                    $result['count'],
                    count($result['errors'])
                ));
            }
        }

        return $result;
    }

    /**
     * [iter14 SPECIALIST-3 / FISCAL-ORPHAN-RETRY]
     *
     * Counts kiosk-paid orders in the closing window that are still
     * missing their NF525 fiscal sequence and emits a warning on the
     * fiscal log channel when the count is non-zero. Best-effort: a
     * failure here MUST NOT abort the Z close (the rows are correctly
     * excluded from the aggregate either way — the warn is observability,
     * not correctness).
     *
     * Window: half-open `(from, to]`, matching {@see aggregate()}.
     */
    private function warnOnOrphanedPaidOrders(int $branchId, ?Carbon $from, Carbon $to): void
    {
        try {
            $query = Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->where('branch_id', $branchId)
                ->where('payment_status', PaymentStatus::PAID)
                ->whereNull('fiscal_sequence_no')
                ->where('created_at', '<=', $to);

            if ($from) {
                $query->where('created_at', '>', $from);
            }

            $count = (int) $query->count();

            if ($count > 0) {
                Log::channel('fiscal')->warning('z_report.close.orphan_paid_orders_in_window', [
                    'event'         => 'fiscal.z_report.orphan_paid_in_window',
                    'branch_id'     => $branchId,
                    'window_from'   => $from?->toIso8601String(),
                    'window_to'     => $to->toIso8601String(),
                    'orphan_count'  => $count,
                    'note'          => 'kiosk-paid orders missing fiscal_sequence_no — retry cron foodking:fiscal:retry-alloc may still be in flight or alloc backend is degraded.',
                ]);
            }
        } catch (\Throwable $e) {
            // Best-effort observability — never let a count() crash break
            // a Z close.
            Log::warning('[FISCAL-ORPHAN-RETRY] warnOnOrphanedPaidOrders failed: ' . $e->getMessage());
        }
    }

    private function sign(int $branchId, string $prevHash, int $sequenceNo, array $aggregates, Carbon $closedAt): string
    {
        // [POS-9-H.2.7 / F-B4]
        // Timezone stability: signatures must be reproducible regardless
        // of the server's local timezone at verification time. We
        // canonicalise `closed_at` in UTC ISO-8601 so a deployment in
        // Europe/Paris that later migrates to UTC (or vice-versa) can
        // still verify old Z reports.
        ksort($aggregates);
        return $this->sealing->signZReport($branchId, $prevHash, $sequenceNo, $aggregates, $closedAt);
    }

    private function applyOrderToTotals(Order $order, int $sign, float &$totalTtc, float &$totalHt, float &$totalTva, array &$byMethod): void
    {
        $totalTtc += $sign * (float) ($order->total ?? 0);
        $totalHt  += $sign * (float) ($order->total_ht ?? ($order->subtotal ?? 0));
        $totalTva += $sign * (float) ($order->total_tax ?? 0);

        $method = (string) ($order->pos_payment_method ?: ($order->payment_method ?: 'unknown'));
        $byMethod[$method] = ($byMethod[$method] ?? 0.0) + ($sign * (float) ($order->total ?? 0));
    }

    /**
     * @param array<int, int> $orderIds
     * @param array<string, float> $byTaxRate
     * @return array<string, float>
     */
    private function taxBreakdownForOrders(array $orderIds, int $sign, array $byTaxRate): array
    {
        if ($orderIds === []) {
            return $byTaxRate;
        }

        $rows = DB::table('order_items')
            ->selectRaw('tax_rate, SUM(tax_amount) AS total_tax_for_rate')
            ->whereIn('order_id', $orderIds)
            ->whereNotNull('tax_rate')
            ->groupBy('tax_rate')
            ->get();

        foreach ($rows as $r) {
            // Normalize the key — tax_rate is stored as a string with
            // inconsistent precision ("10", "10.00", "5.5"), so we
            // cast through float to canonicalise and then back to
            // string for a stable JSON-encoded signed payload.
            $key = rtrim(rtrim(number_format((float) $r->tax_rate, 2, '.', ''), '0'), '.');
            $byTaxRate[$key] = ($byTaxRate[$key] ?? 0.0) + ($sign * (float) $r->total_tax_for_rate);
        }

        return $byTaxRate;
    }

    private function computeSignature(ZReport $report, string $prevHash): string
    {
        $closedAt = $report->closed_at instanceof Carbon
            ? $report->closed_at
            : Carbon::parse($report->closed_at);

        $aggregates = [
            'total_ttc' => (float) $report->total_ttc,
            'total_ht' => (float) $report->total_ht,
            'total_tva' => (float) $report->total_tva,
            'total_by_method' => (array) ($report->total_by_method ?? []),
            'total_by_tax_rate' => (array) ($report->total_by_tax_rate ?? []),
            'order_count' => (int) $report->order_count,
            'cancel_count' => (int) $report->cancel_count,
            'refund_count' => (int) $report->refund_count,
        ];

        return $this->sign(
            (int) $report->branch_id,
            $prevHash,
            (int) $report->sequence_no,
            $aggregates,
            $closedAt
        );
    }

    /**
     * [POS-9-H.2.1 / F-C1]
     *
     * Refuse to sign with a weak or default secret when APP_ENV=production.
     * In local/testing we keep short dev strings so CI stays fast.
     */
    private function assertProductionSafe(string $secret, string $key): string
    {
        $env = app()->environment();
        if ($env !== 'production') {
            return $secret;
        }

        $sentinels = (array) Config::get('fiscal.dev_sentinels', []);
        if (in_array($secret, $sentinels, true)) {
            throw new RuntimeException(
                "ZReportService: {$key} is set to a known dev sentinel in APP_ENV=production. "
                . 'Rotate the secret (see docs/FISCAL_SECRETS.md).'
            );
        }

        $min = (int) Config::get('fiscal.min_secret_length', 32);
        if (strlen($secret) < $min) {
            throw new RuntimeException(
                "ZReportService: {$key} is shorter than {$min} characters in APP_ENV=production. "
                . 'Generate a strong secret (e.g. openssl rand -hex 32).'
            );
        }

        return $secret;
    }
}
