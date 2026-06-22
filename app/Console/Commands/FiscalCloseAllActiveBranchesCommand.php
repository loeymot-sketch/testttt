<?php

namespace App\Console\Commands;

use App\Console\Kernel;
use App\Models\ZReport;
use App\Services\Fiscal\ZReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * [GOAL-G2-HEAL-06 2026-05-23] NF525 Z-close safety-net per active branch.
 *
 * Context (Phase G.6 audit finding, P1 operational):
 *   Z-close has NO production trigger today — no UI button, no cron, no
 *   documented manual runbook. Owners must run a custom curl or php tinker
 *   every night. F.10 verified the SERVICE-LAYER invariants (13/13 GREEN)
 *   but the operational/UI layer was missing.
 *
 * Mandate (V1 LOCAL Le Cayenne):
 *   - Daily safety-net that closes any still-OPEN Z report per active branch
 *     just before midnight Paris time, preserving same-day business_date
 *     semantics required by NF525.
 *   - Double-close-safe: if a cashier (manual call) already closed earlier
 *     in the evening, the branch is silently skipped (info log, not error).
 *   - Per-branch isolation: a single branch failure does NOT abort the
 *     remaining branches — pattern mirrors fiscal-chain-monitor lane
 *     (Kernel.php:192-226).
 *
 * Frozen-zone discipline (§7):
 *   ZReportService::close() is FROZEN. This command is a CALLER only — it
 *   does not modify the service, just invokes it once per branch via the
 *   public API. BranchScope is respected: we iterate the canonical
 *   {@see \App\Console\Kernel::activeBranchIds()} helper (status 1 or
 *   Status::ACTIVE per the Wave 2d FISCAL-ADV3C-01 pattern).
 *
 * Outcomes (logged on the `fiscal` channel for ops):
 *   - z_close.safety_net.skip     — no open Z (already closed / dark branch)
 *   - z_close.safety_net.success  — closed Z report, sequence_no emitted
 *   - z_close.safety_net.failed   — close() raised; chain integrity logged
 *
 * Schedule (see app/Console/Kernel.php):
 *   dailyAt('23:55') timezone('Europe/Paris') + onOneServer +
 *   withoutOverlapping + runInBackground.
 */
class FiscalCloseAllActiveBranchesCommand extends Command
{
    protected $signature = 'fiscal:close-all-active-branches
        {--branch= : Restrict to a single branch_id (ops recovery override)}
        {--dry-run : Iterate branches and report would-close vs already-closed, do NOT close}';

    protected $description = 'NF525 safety-net: close the currently-open Z report for every active branch (skip silently if none).';

    public function handle(ZReportService $service): int
    {
        $branchOverride = $this->option('branch');
        $branchOverride = $branchOverride !== null && $branchOverride !== ''
            ? (int) $branchOverride
            : null;

        $dryRun = (bool) $this->option('dry-run');

        // [GOAL-G2-HEAL-06] Canonical active-branch lookup. Mirrors lane
        // #15 (fiscal-chain-monitor) and lane #16 (foodking-fiscal-archive-daily)
        // so a future status-data migration (status=1 → status=5) does not
        // silently no-op this lane. See Kernel::activeBranchIds() PHPDoc.
        $branches = Kernel::activeBranchIds();

        if ($branchOverride !== null) {
            $branches = $branches->filter(fn (int $id): bool => $id === $branchOverride)->values();

            if ($branches->isEmpty()) {
                $this->error("FiscalCloseAllActiveBranches: branch_id {$branchOverride} is not active.");
                Log::channel('fiscal')->warning('z_close.safety_net.branch_not_active', [
                    'event'           => 'z_close.safety_net.branch_not_active',
                    'branch_override' => $branchOverride,
                ]);

                return self::FAILURE;
            }
        }

        $closed   = 0;
        $skipped  = 0;
        $failed   = 0;

        foreach ($branches as $branchId) {
            $branchId = (int) $branchId;

            // [GOAL-G2-HEAL-06] Pre-check is mandatory. ZReportService::close()
            // throws RuntimeException("no open Z report to close for branch
            // {$branchId}") at line 211 if no open Z exists. A safety-net cron
            // that runs nightly across ALL active branches WILL hit dark
            // branches (no traffic that day). Without this pre-check the
            // `fiscal` channel fills with stack traces every night, creating
            // a false-alarm pager. Pre-checking via STATUS_OPEN is
            // semantically the same predicate close() uses internally
            // (line 206) so the two paths cannot disagree.
            $hasOpen = ZReport::query()
                ->where('branch_id', $branchId)
                ->where('status', ZReport::STATUS_OPEN)
                ->exists();

            if (! $hasOpen) {
                $skipped++;
                Log::channel('fiscal')->info('z_close.safety_net.skip', [
                    'event'     => 'z_close.safety_net.skip',
                    'branch_id' => $branchId,
                    'reason'    => 'no_open_z',
                ]);
                continue;
            }

            if ($dryRun) {
                $closed++;
                Log::channel('fiscal')->info('z_close.safety_net.dry_run.would_close', [
                    'event'     => 'z_close.safety_net.dry_run.would_close',
                    'branch_id' => $branchId,
                ]);
                continue;
            }

            try {
                $zReport = $service->close($branchId);
                $closed++;

                Log::channel('fiscal')->info('z_close.safety_net.success', [
                    'event'         => 'z_close.safety_net.success',
                    'branch_id'     => $branchId,
                    'z_report_id'   => $zReport->id,
                    'sequence_no'   => $zReport->sequence_no,
                ]);
            } catch (Throwable $e) {
                // Per-branch isolation: one branch failure must NOT halt the
                // rest. Log error-channel + continue. Pager backends paging
                // on `fiscal.error` will surface this exactly once per night
                // per failing branch — the desired behaviour.
                $failed++;
                Log::channel('fiscal')->error('z_close.safety_net.failed', [
                    'event'           => 'z_close.safety_net.failed',
                    'branch_id'       => $branchId,
                    'exception_class' => get_class($e),
                    'message'         => $e->getMessage(),
                ]);
            }
        }

        $this->info(sprintf(
            'fiscal:close-all-active-branches: scanned=%d closed=%d skipped=%d failed=%d%s',
            $branches->count(),
            $closed,
            $skipped,
            $failed,
            $dryRun ? ' (DRY-RUN)' : ''
        ));

        // [GOAL-G2-HEAL-06] Non-zero exit if ANY branch failed — pages
        // ops via the structured log + scheduler stderr capture. Skipped
        // branches (no open Z) are the common case and never page.
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
