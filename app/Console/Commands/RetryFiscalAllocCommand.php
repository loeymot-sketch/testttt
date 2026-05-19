<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\FrontendOrder;
use App\Services\FrontendOrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * [iter14 SPECIALIST-3 / FISCAL-ORPHAN-RETRY]
 *
 * Retries NF525 fiscal sequence allocation for kiosk-paid orders that
 * tripped the alloc failure branch in
 * {@see \App\Services\FrontendOrderService::finalizePaidKioskOrder()}.
 *
 * Inputs (table predicate):
 *   - payment_status        = PAID
 *   - fiscal_sequence_no    IS NULL
 *   - fiscal_alloc_error_at IS NOT NULL
 *
 * Action:
 *   - For each match, re-invoke FrontendOrderService::finalizePaidKioskOrder
 *     so the same allocation + status promotion path runs (single SSOT —
 *     no parallel logic that could drift). On success the finalize path
 *     clears `fiscal_alloc_error_at` itself.
 *
 * Bounds:
 *   - `--limit` caps each cron tick (default 50) so a multi-thousand
 *     orphan backlog never starves the queue worker.
 *   - `--branch=` allows operator-driven targeted retries (e.g. once a
 *     specific cache backend is restored).
 *
 * Schedule (see app/Console/Kernel.php):
 *   everyMinute() + withoutOverlapping(5) + onOneServer().
 */
class RetryFiscalAllocCommand extends Command
{
    protected $signature = 'foodking:fiscal:retry-alloc
        {--limit=50 : Max orders to process per tick}
        {--branch= : Restrict to a single branch_id (optional)}';

    protected $description = 'Retry NF525 fiscal sequence allocation for kiosk-paid orders flagged with fiscal_alloc_error_at.';

    public function handle(FrontendOrderService $service): int
    {
        // Defensive: column may not yet exist in fresh test envs that
        // skip the migration (e.g. partial DB rebuild). Skip gracefully
        // rather than crash the scheduler tick.
        if (!Schema::hasColumn('orders', 'fiscal_alloc_error_at')) {
            $this->warn('fiscal_alloc_error_at column missing — migration not yet applied.');
            return self::SUCCESS;
        }

        $limit    = max(1, (int) $this->option('limit'));
        $branchId = $this->option('branch');
        $branchId = $branchId !== null && $branchId !== '' ? (int) $branchId : null;

        // [Z6-P1-WGS 2026-05-19] singular — FrontendOrder HAS SoftDeletes.
        // We MUST NOT retry fiscal alloc on a soft-deleted order (an admin
        // who soft-deleted a kiosk order does not want the cron to revive
        // it). Keep SoftDeletingScope active by dropping only BranchScope.
        $query = FrontendOrder::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('payment_status', PaymentStatus::PAID)
            ->whereNull('fiscal_sequence_no')
            ->whereNotNull('fiscal_alloc_error_at')
            ->orderBy('fiscal_alloc_error_at', 'asc');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $orders = $query->limit($limit)->get();

        if ($orders->isEmpty()) {
            return self::SUCCESS;
        }

        $retriedOk     = 0;
        $retriedFailed = 0;
        $skipped       = 0;

        foreach ($orders as $order) {
            try {
                $promoted = $service->finalizePaidKioskOrder($order);

                if ($promoted) {
                    $retriedOk++;

                    Log::channel('fiscal')->info('kiosk.fiscal_alloc_retry.success', [
                        'event'    => 'kiosk.fiscal_alloc_retry.success',
                        'order_id' => $order->id,
                        'branch_id'=> $order->branch_id,
                    ]);
                } else {
                    // finalize path returned false — either filters
                    // (order_type / payment_method) excluded the row, or
                    // the alloc still fails. The finalize closure already
                    // logged the failure and refreshed the
                    // fiscal_alloc_error_at timestamp; we just count.
                    $order->refresh();
                    if (is_null($order->fiscal_sequence_no)
                        && !is_null($order->fiscal_alloc_error_at)
                    ) {
                        $retriedFailed++;
                    } else {
                        $skipped++;
                    }
                }
            } catch (\Throwable $e) {
                // Defense in depth: finalize already catches alloc errors
                // internally, so an exception here is structural (DB
                // outage etc.). Log and move on rather than abort the
                // batch — the cron tick re-fires next minute.
                $retriedFailed++;
                Log::channel('fiscal')->error('kiosk.fiscal_alloc_retry.crashed', [
                    'event'    => 'kiosk.fiscal_alloc_retry.crashed',
                    'order_id' => $order->id,
                    'branch_id'=> $order->branch_id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $this->info(sprintf(
            'fiscal-retry-alloc: scanned=%d ok=%d failed=%d skipped=%d',
            $orders->count(),
            $retriedOk,
            $retriedFailed,
            $skipped
        ));

        return self::SUCCESS;
    }
}
