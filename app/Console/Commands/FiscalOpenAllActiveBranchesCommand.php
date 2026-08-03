<?php

namespace App\Console\Commands;

use App\Console\Kernel;
use App\Models\ZReport;
use App\Services\Fiscal\ZReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * [GOAL-L2-HEAL-07 2026-05-24] NF525 Z-open safety-net per active branch.
 *
 * Context (Phase L11.1 P0-03 + K.7 FIND-1, P0/P1 operational):
 *   G2-HEAL-06 added a Z-CLOSE safety-net at 23:55 Paris but no COMPANION
 *   Z-OPEN cron. If a cashier never manually opens the next Z after the
 *   midnight close, every business day after that = silent skip = the Z
 *   chain is no longer extended. NF525 segregation breaks: any transaction
 *   recorded after the silent close has nowhere to land except via the
 *   `fiscal_alloc_error_at` flag + retry cron, which is a degraded path.
 *
 * Mandate (V1 LOCAL Le Cayenne):
 *   - Daily safety-net that OPENS a new Z report per active branch a few
 *     minutes AFTER midnight Paris time, so the new business_date starts
 *     with an OPEN Z ready to absorb transactions from the morning shift.
 *   - Idempotent: if a Z is already OPEN for the branch (cashier opened
 *     manually, or this cron already ran today), the branch is silently
 *     skipped (info log, not error). Cannot double-open.
 *   - Per-branch isolation: a single branch failure does NOT abort the
 *     remaining branches — pattern mirrors fiscal-chain-monitor lane
 *     (Kernel.php:211-245) and the companion close lane
 *     (Kernel.php:310-346).
 *
 * Frozen-zone discipline (§7):
 *   ZReportService::open() is FROZEN. This command is a CALLER only — it
 *   does not modify the service, just invokes it once per branch via the
 *   public API. BranchScope is respected: we iterate the canonical
 *   {@see \App\Console\Kernel::activeBranchIds()} helper (status 1 or
 *   Status::ACTIVE per the Wave 2d FISCAL-ADV3C-01 pattern), keeping
 *   parity with the close command.
 *
 * Outcomes (logged on the `fiscal` channel for ops):
 *   - z_open.safety_net.skip     — branch already has an OPEN Z (idempotent)
 *   - z_open.safety_net.success  — opened new Z report, sequence_no emitted
 *   - z_open.safety_net.failed   — open() raised; chain integrity logged
 *
 * Schedule (see app/Console/Kernel.php):
 *   dailyAt('00:05') timezone('Europe/Paris') + onOneServer +
 *   withoutOverlapping + runInBackground.
 *
 * Loop: 23:55 close (G2-HEAL-06) + 00:05 open (L2-HEAL-07) = continuous Z
 * chain extension even if the cashier is absent.
 */
class FiscalOpenAllActiveBranchesCommand extends Command
{
    protected $signature = 'fiscal:open-all-active-branches
        {--branch= : Restrict to a single branch_id (ops recovery override)}
        {--dry-run : Iterate branches and report would-open vs already-open, do NOT open}';

    protected $description = 'NF525 safety-net: open a new Z report for every active branch with no currently-open Z (skip silently if one is already open).';

    public function handle(ZReportService $service): int
    {
        $branchOverride = $this->option('branch');
        $branchOverride = $branchOverride !== null && $branchOverride !== ''
            ? (int) $branchOverride
            : null;

        $dryRun = (bool) $this->option('dry-run');

        // [GOAL-L2-HEAL-07] Canonical active-branch lookup. Mirrors lane
        // #15 (fiscal-chain-monitor), lane #16 (foodking-fiscal-archive-daily)
        // and lane #17 (fiscal:close-all-active-branches G2-HEAL-06) so a
        // future status-data migration (status=1 → status=5) does not
        // silently no-op this lane. See Kernel::activeBranchIds() PHPDoc.
        $branches = Kernel::activeBranchIds();

        if ($branchOverride !== null) {
            $branches = $branches->filter(fn (int $id): bool => $id === $branchOverride)->values();

            if ($branches->isEmpty()) {
                $this->error("FiscalOpenAllActiveBranches: branch_id {$branchOverride} is not active.");
                Log::channel('fiscal')->warning('z_open.safety_net.branch_not_active', [
                    'event'           => 'z_open.safety_net.branch_not_active',
                    'branch_override' => $branchOverride,
                ]);

                return self::FAILURE;
            }
        }

        $opened   = 0;
        $skipped  = 0;
        $failed   = 0;

        foreach ($branches as $branchId) {
            $branchId = (int) $branchId;

            // [GOAL-L2-HEAL-07] Pre-check is mandatory. ZReportService::open()
            // throws RuntimeException("ZReportService: branch {$branchId}
            // already has an OPEN Z report (id=…, sequence_no=…)") at
            // ZReportService.php:106-109 if any open Z exists. A safety-net
            // cron that runs nightly across ALL active branches WILL hit
            // branches whose cashier already opened the new Z manually
            // (early shift) or whose previous run of this cron already
            // opened today. Without this pre-check the `fiscal` channel
            // fills with stack traces every morning, creating a false-alarm
            // pager. Pre-checking via STATUS_OPEN is semantically the same
            // predicate open() uses internally (line 101-104) so the two
            // paths cannot disagree.
            $hasOpen = ZReport::query()
                ->where('branch_id', $branchId)
                ->where('status', ZReport::STATUS_OPEN)
                ->exists();

            if ($hasOpen) {
                $skipped++;
                Log::channel('fiscal')->info('z_open.safety_net.skip', [
                    'event'     => 'z_open.safety_net.skip',
                    'branch_id' => $branchId,
                    'reason'    => 'open_z_exists',
                ]);
                continue;
            }

            if ($dryRun) {
                $opened++;
                Log::channel('fiscal')->info('z_open.safety_net.dry_run.would_open', [
                    'event'     => 'z_open.safety_net.dry_run.would_open',
                    'branch_id' => $branchId,
                ]);
                continue;
            }

            try {
                $zReport = $service->open($branchId);
                $opened++;

                Log::channel('fiscal')->info('z_open.safety_net.success', [
                    'event'         => 'z_open.safety_net.success',
                    'branch_id'     => $branchId,
                    'z_report_id'   => $zReport->id,
                    'sequence_no'   => $zReport->sequence_no,
                ]);
            } catch (Throwable $e) {
                // Per-branch isolation: one branch failure must NOT halt
                // the rest. Log error-channel + continue. Pager backends
                // paging on `fiscal.error` will surface this exactly once
                // per night per failing branch — the desired behaviour.
                $failed++;
                Log::channel('fiscal')->error('z_open.safety_net.failed', [
                    'event'           => 'z_open.safety_net.failed',
                    'branch_id'       => $branchId,
                    'exception_class' => get_class($e),
                    'message'         => $e->getMessage(),
                ]);
            }
        }

        $this->info(sprintf(
            'fiscal:open-all-active-branches: scanned=%d opened=%d skipped=%d failed=%d%s',
            $branches->count(),
            $opened,
            $skipped,
            $failed,
            $dryRun ? ' (DRY-RUN)' : ''
        ));

        // [GOAL-L2-HEAL-07] Non-zero exit if ANY branch failed — pages
        // ops via the structured log + scheduler stderr capture. Skipped
        // branches (already-open Z) are the common case and never page.
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
