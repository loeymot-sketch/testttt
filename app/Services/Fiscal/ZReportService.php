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

    public function __construct(
        private ?ConnectionInterface $connection = null
    ) {
        $this->connection = $connection ?? DB::connection();
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
        $query = Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('branch_id', $branchId)
            ->whereNotNull('fiscal_sequence_no')
            ->where('created_at', '<=', $to);

        if ($from) {
            $query->where('created_at', '>', $from);
        }

        $orders = (clone $query)
            ->where('payment_status', '!=', PaymentStatus::UNPAID)
            ->get();

        $totalTtc = 0.0;
        $totalHt  = 0.0;
        $totalTva = 0.0;
        $byMethod = [];
        $byTaxRate = [];
        $orderCount = 0;

        foreach ($orders as $o) {
            $totalTtc += (float) ($o->total ?? 0);
            $totalHt  += (float) ($o->total_ht ?? ($o->subtotal ?? 0));
            $totalTva += (float) ($o->total_tax ?? 0);
            $orderCount++;

            $method = $o->pos_payment_method ?: ($o->payment_method ?: 'unknown');
            $byMethod[$method] = ($byMethod[$method] ?? 0.0) + (float) ($o->total ?? 0);
        }

        $cancelCount = (clone $query)->where('status', OrderStatus::CANCELED)->count();
        $refundCount = (clone $query)->where('status', OrderStatus::RETURNED)->count();

        // [POS-9-H.2.8 / F-B6]
        // Populate total_by_tax_rate by summing order_items.tax_amount
        // grouped by tax_rate, scoped to the exact same order set we
        // just aggregated. Using order_items (the already-persisted,
        // server-recomputed pricing from POS-9.1.8) avoids re-running
        // PricingService and guarantees consistency with the individual
        // receipts that are referenced by fiscal_sequence_no.
        $byTaxRate = [];
        $paidOrderIds = $orders->pluck('id')->all();
        if ($paidOrderIds !== []) {
            $rows = DB::table('order_items')
                ->selectRaw('tax_rate, SUM(tax_amount) AS total_tax_for_rate')
                ->whereIn('order_id', $paidOrderIds)
                ->whereNotNull('tax_rate')
                ->groupBy('tax_rate')
                ->get();
            foreach ($rows as $r) {
                // Normalize the key — tax_rate is stored as a string with
                // inconsistent precision ("10", "10.00", "5.5"), so we
                // cast through float to canonicalise and then back to
                // string for a stable JSON-encoded signed payload.
                $key = rtrim(rtrim(number_format((float) $r->tax_rate, 2, '.', ''), '0'), '.');
                $byTaxRate[$key] = ($byTaxRate[$key] ?? 0.0) + (float) $r->total_tax_for_rate;
            }
            $byTaxRate = array_map(fn ($v) => round((float) $v, 2), $byTaxRate);
            ksort($byTaxRate);
        }

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

        $aggregates = [
            'total_ttc'         => (float) $report->total_ttc,
            'total_ht'          => (float) $report->total_ht,
            'total_tva'         => (float) $report->total_tva,
            'total_by_method'   => (array) ($report->total_by_method   ?? []),
            'total_by_tax_rate' => (array) ($report->total_by_tax_rate ?? []),
            'order_count'       => (int) $report->order_count,
            'cancel_count'      => (int) $report->cancel_count,
            'refund_count'      => (int) $report->refund_count,
        ];

        $expected = $this->sign(
            (int) $report->branch_id,
            (string) ($report->prev_hash ?? ''),
            (int) $report->sequence_no,
            $aggregates,
            $report->closed_at
        );

        return hash_equals($expected, (string) $report->signature);
    }

    private function sign(int $branchId, string $prevHash, int $sequenceNo, array $aggregates, Carbon $closedAt): string
    {
        $secret = Config::get('fiscal.z_report_secret');
        if (!is_string($secret) || $secret === '') {
            throw new RuntimeException('ZReportService: fiscal.z_report_secret is not configured.');
        }
        $secret = $this->assertProductionSafe($secret, 'fiscal.z_report_secret');

        // [POS-9-H.2.7 / F-B4]
        // Timezone stability: signatures must be reproducible regardless
        // of the server's local timezone at verification time. We
        // canonicalise `closed_at` in UTC ISO-8601 so a deployment in
        // Europe/Paris that later migrates to UTC (or vice-versa) can
        // still verify old Z reports.
        ksort($aggregates);
        $canonical = json_encode(
            [
                'branch_id'   => $branchId,
                'sequence_no' => $sequenceNo,
                'closed_at'   => $closedAt->copy()->utc()->toIso8601String(),
                'aggregates'  => $aggregates,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return hash_hmac('sha256', $prevHash . '|' . $canonical, $secret);
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
