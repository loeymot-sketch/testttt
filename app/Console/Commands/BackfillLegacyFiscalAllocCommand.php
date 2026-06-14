<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Models\ZReport;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [NF-3 / DELIV-NF525-01 — GOAL 2.0 Phase 2, 2026-06-15].
 *
 * ONE-SHOT, OWNER-GATED legacy backfill. The retry-cron (RetryFiscalAllocCommand) only
 * salvages rows that carry `fiscal_alloc_error_at` (an attempted-and-failed allocation).
 * Pre-heal legacy rows that reached PAID + seq=NULL WITHOUT that flag escape the cron
 * forever. This command allocates a gap-free fiscal_sequence_no for those rows so they
 * re-enter the Z (via the NF-1 late-salvage window, which keys on fiscal_seq_allocated_at).
 *
 * SAFETY:
 *   - --dry-run is the DEFAULT (true). It prints the candidate table + per-branch counts +
 *     TTC and writes NOTHING. Run it, review with the owner, THEN run --apply.
 *   - --apply requires an explicit --confirm=BACKFILL-FISCAL token (no accidental mutation).
 *   - Predicate is deliberately narrow: realized PAID sales only (non-terminal status,
 *     not soft-deleted, not a refund mirror, total>0) created BEFORE the last closed Z
 *     (a row inside an already-OPEN Z is left to the normal flow). Each allocation is a
 *     per-row tx + a Log::channel('fiscal') line + an append-only AuditLogService entry.
 *   - This is NOT the cron — it never auto-runs. Retroactive fiscal-seq allocation on
 *     historical sales is an NF525-sensitive operation: run on prod only with a verified,
 *     test-restored backup and owner sign-off (DEP-4).
 */
class BackfillLegacyFiscalAllocCommand extends Command
{
    protected $signature = 'fiscal:backfill-legacy-alloc
        {--apply : Actually allocate sequences (default is dry-run, writes nothing)}
        {--confirm= : Required with --apply: pass BACKFILL-FISCAL to authorize mutation}
        {--branch= : Limit to a single branch_id}';

    protected $description = 'OWNER-GATED one-shot backfill of fiscal_sequence_no for legacy realized-PAID rows that escaped the retry-cron (NF-3 / DELIV-NF525-01). Dry-run by default.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        if ($apply && $this->option('confirm') !== 'BACKFILL-FISCAL') {
            $this->error('Refused: --apply requires --confirm=BACKFILL-FISCAL (retroactive fiscal allocation is NF525-sensitive).');
            return self::FAILURE;
        }

        $candidates = $this->candidates();

        if ($candidates->isEmpty()) {
            $this->info('No legacy realized-PAID + seq=NULL rows to backfill. Nothing to do.');
            return self::SUCCESS;
        }

        $this->table(
            ['id', 'branch', 'type', 'pay', 'status', 'total', 'created_at'],
            $candidates->map(fn (Order $o) => [
                $o->id, $o->branch_id, $o->order_type, $o->payment_status, $o->status,
                number_format((float) $o->total, 2), (string) $o->created_at,
            ])->all()
        );

        $byBranch = $candidates->groupBy('branch_id')->map(fn ($g) => [
            'count' => $g->count(),
            'ttc'   => number_format((float) $g->sum('total'), 2),
        ]);
        foreach ($byBranch as $branchId => $stats) {
            $this->line("  branch={$branchId}: {$stats['count']} row(s), TTC {$stats['ttc']}");
        }

        if (! $apply) {
            $this->warn('DRY-RUN (default): nothing written. Review with the owner, then re-run with --apply --confirm=BACKFILL-FISCAL.');
            return self::SUCCESS;
        }

        $done = 0;
        $failed = 0;
        foreach ($candidates as $order) {
            try {
                DB::transaction(function () use ($order, &$done): void {
                    $locked = Order::withoutGlobalScope(BranchScope::class)
                        ->whereKey($order->id)->lockForUpdate()->firstOrFail();
                    // Re-check under lock — a concurrent path may have allocated already.
                    if ($locked->fiscal_sequence_no !== null) {
                        return;
                    }
                    $seq = app(FiscalSequenceService::class)->next((int) $locked->branch_id);
                    DB::table('orders')->where('id', $locked->id)->update([
                        'fiscal_sequence_no'      => $seq,
                        'fiscal_alloc_error_at'   => null,
                        'fiscal_seq_allocated_at' => now(), // NF-1 late-salvage sweeps it into the current Z
                        'updated_at'              => now(),
                    ]);
                    Log::channel('fiscal')->info('legacy.fiscal_backfill.allocated', [
                        'event' => 'legacy.fiscal_backfill.allocated',
                        'order_id' => (int) $locked->id,
                        'branch_id' => (int) $locked->branch_id,
                        'fiscal_sequence_no' => $seq,
                    ]);
                    try {
                        app(AuditLogService::class)->write([
                            'branch_id'   => (int) $locked->branch_id,
                            'user_id'     => null,
                            'action'      => 'fiscal.legacy_backfill_alloc',
                            'resource'    => 'order',
                            'resource_id' => (int) $locked->id,
                            'payload'     => ['fiscal_sequence_no' => $seq, 'tool' => 'fiscal:backfill-legacy-alloc'],
                        ]);
                    } catch (\Throwable) {
                        // best-effort audit; the allocation itself is the NF525 record
                    }
                    $done++;
                });
            } catch (\Throwable $e) {
                $failed++;
                Log::channel('fiscal')->error('legacy.fiscal_backfill.failed', [
                    'event' => 'legacy.fiscal_backfill.failed',
                    'order_id' => (int) $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Backfill complete: {$done} allocated, {$failed} failed. Run fiscal:verify-chain --all + fiscal:verify-z-membership to confirm.");
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Narrow predicate: realized PAID sales with no seq, created BEFORE the last closed Z,
     * non-terminal, not soft-deleted, not a refund mirror, total>0.
     *
     * @return \Illuminate\Support\Collection<int, Order>
     */
    private function candidates(): \Illuminate\Support\Collection
    {
        $branchOpt = $this->option('branch');

        $query = Order::withoutGlobalScope(BranchScope::class)
            ->whereNull('fiscal_sequence_no')
            ->where('payment_status', PaymentStatus::PAID)
            ->whereNull('parent_order_id')
            ->where('total', '>', 0)
            ->whereNotIn('status', [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED]);

        if ($branchOpt !== null && $branchOpt !== '') {
            $query->where('branch_id', (int) $branchOpt);
        }

        return $query->get()->filter(function (Order $o): bool {
            // Only rows that sit BEFORE the last closed Z for their branch — a row inside an
            // already-OPEN window is left to the normal allocation flow (never manufacture a
            // cross-Z orphan by numbering an in-progress window's row).
            $lastClosed = ZReport::withoutGlobalScope(BranchScope::class)
                ->where('branch_id', $o->branch_id)
                ->where('status', ZReport::STATUS_CLOSED)
                ->orderByDesc('closed_at')
                ->first();
            if (! $lastClosed || $lastClosed->closed_at === null) {
                return false; // no closed Z yet for this branch → nothing is "legacy-escaped"
            }
            return $o->created_at !== null && $o->created_at->lt($lastClosed->closed_at);
        })->values();
    }
}
