<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [GENIE Wave 0b — FISCAL orphan backfill 2026-06-16]
 *
 * The Wave-0 heals (FISCAL-CPS-01 / FISCAL-DELIV-COD-01) stop NEW off-book PAID orders, but the rows that
 * went PAID with fiscal_sequence_no=NULL BEFORE the heal remain off-book (excluded from every Z and
 * unreachable by the kiosk-only retry cron). This command allocates a gap-free fiscal sequence to each so
 * they become on-book, with an append-only AuditLog "régularisation" trail.
 *
 * IMPORTANT — accounting placement is an OWNER/ACCOUNTANT decision: allocating a sequence makes the order
 * on-book, but whether/how it appears in a Z depends on the Z date-window. These orphans carry HISTORICAL
 * order_datetime, so their value lands in a past (already-closed) window. The correct fiscal treatment of
 * pre-existing off-book revenue (a régularisation Z, or accountant note) is NOT decided here — this command
 * only closes the technical NULL-sequence gap and records the audit trail. Run `--apply` only after owner
 * sign-off. Default is DRY-RUN.
 */
class BackfillFiscalOrphans extends Command
{
    protected $signature = 'fiscal:backfill-orphans {--apply : actually allocate (default is a dry-run report)} {--branch= : restrict to one branch}';

    protected $description = 'Allocate fiscal sequences to historical PAID orders that have fiscal_sequence_no=NULL (Wave 0b).';

    public function handle(): int
    {
        if (app()->environment('production') && !$this->option('apply')) {
            $this->warn('Production: running DRY-RUN. Pass --apply (after owner sign-off) to allocate.');
        }

        $query = Order::query()->where('payment_status', \App\Enums\PaymentStatus::PAID)->whereNull('fiscal_sequence_no');
        if ($this->option('branch')) {
            $query->where('branch_id', (int) $this->option('branch'));
        }

        $orphans = $query->orderBy('id')->get(['id', 'branch_id', 'order_serial_no', 'total', 'order_datetime']);
        $this->info("Found {$orphans->count()} off-book PAID orphan(s) (fiscal_sequence_no=NULL).");

        if ($orphans->isEmpty()) {
            return self::SUCCESS;
        }

        if (!$this->option('apply')) {
            $this->table(
                ['id', 'branch', 'serial', 'total', 'order_datetime'],
                $orphans->take(20)->map(fn ($o) => [$o->id, $o->branch_id, $o->order_serial_no, $o->total, $o->order_datetime])->all()
            );
            $this->warn('DRY-RUN — nothing allocated. Re-run with --apply (owner-gated) to backfill.');
            $this->warn('NOTE: accounting placement of this historical revenue in a Z is an accountant decision (see class docblock).');
            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;
        foreach ($orphans as $orphan) {
            try {
                DB::transaction(function () use ($orphan, &$ok): void {
                    $locked = Order::query()->whereKey($orphan->id)->lockForUpdate()->firstOrFail();
                    if ($locked->fiscal_sequence_no !== null) {
                        return; // a concurrent run already allocated — idempotent skip
                    }
                    $seq = app(FiscalSequenceService::class)->next((int) $locked->branch_id);
                    $locked->fiscal_sequence_no = $seq;
                    $locked->save();

                    app(AuditLogService::class)->write([
                        'branch_id'   => (int) $locked->branch_id,
                        'user_id'     => null,
                        'action'      => 'fiscal.orphan_backfilled',
                        'resource'    => 'order',
                        'resource_id' => (int) $locked->id,
                        'payload'     => [
                            'order_serial_no'    => $locked->order_serial_no,
                            'fiscal_sequence_no' => $seq,
                            'total'              => round((float) $locked->total, 2),
                            'reason'             => 'wave-0b-historical-off-book-regularisation',
                        ],
                    ]);
                    $ok++;
                });
            } catch (\Throwable $e) {
                $failed++;
                Log::channel('fiscal')->error('fiscal.orphan_backfill_failed', [
                    'order_id' => $orphan->id, 'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Backfilled {$ok} order(s), {$failed} failure(s).");
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
