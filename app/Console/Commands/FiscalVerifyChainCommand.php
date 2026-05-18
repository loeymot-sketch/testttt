<?php

namespace App\Console\Commands;

use App\Console\Kernel as AppConsoleKernel;
use App\Models\Branch;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\ZReportService;
use Illuminate\Console\Command;

/**
 * [NF525 Wave 1 W-1 heal 2026-05-18]
 * [NF525 Wave 3 P1 heal FISCAL-ADV3-01/02 2026-05-18]
 * [NF525 Wave 3b P0 heal FISCAL-ADV3B-02 2026-05-18]
 *
 * Operator-facing CLI primitive that re-walks BOTH NF525 HMAC chains
 * (`audit_logs` AND `z_reports`) for a single branch and reports the
 * first row whose hash no longer matches (or "CHAIN OK" when intact).
 *
 * Wave 3b FISCAL-ADV3B-02: previously this command only walked the
 * audit_logs chain via AuditLogService::verifyChain. The separate
 * NF525-critical z_reports HMAC chain (ZReportService::verifyChain at
 * app/Services/Fiscal/ZReportService.php:463) was NOT covered — a
 * Z-report tamper would have stayed invisible until the next Z open.
 * The command now invokes BOTH services and aggregates exit semantics.
 *
 * CLAUDE.md §8 references this command as the on-demand chain
 * integrity check, but no implementation existed. Without it, the
 * only path to verify the chain was the pre-bundle hook inside
 * `foodking:fiscal:archive`, which can only be triggered as part
 * of a Z archival run — not on-demand by the owner.
 *
 * Implementation is a thin adapter over the public, frozen
 * AuditLogService::verifyChain() method. The service is FROZEN
 * (CLAUDE.md §7) but its public API is callable from any wrapper.
 *
 * Exit codes (Wave 3 P1 FISCAL-ADV3-02 — distinct codes for monitoring):
 *   0  = chain verified intact for the given branch (or every active
 *        branch when invoked with --all).
 *   1  = TAMPER detected; output names every audit_logs.id / z_reports.id
 *        whose hash no longer matches so the operator can pinpoint each
 *        breach in a single pass (Wave 2d FISCAL-ADV3C-02).
 *   2  = INVALID arguments:
 *          - --branch=N where N does not exist in branches table
 *            (Wave 3 P1 FISCAL-ADV3-01: previously a false-negative
 *            CHAIN OK exit 0 because the verifier iterated an empty
 *            cursor; now a hard-fail before the service call), OR
 *          - --branch=0 (Wave 2d FISCAL-ADV3C-03: reserved sentinel
 *            for admin/global writes, not a "sweep all" alias; pass
 *            --all for the real multi-branch sweep).
 *   3  = EXECUTION ERROR (DB outage, missing/weak secret, unexpected
 *        throw). Distinct from TAMPER so monitoring can route DB/cred
 *        incidents to ops vs. NF525 integrity breaches to compliance.
 *
 * Flags (Wave 2d FISCAL-ADV3C-03):
 *   --branch=<id>  Single-branch verification (default 1 for backward
 *                  compatibility with the daily cron). Refuses 0.
 *   --all          Sweep every active branch returned by
 *                  Kernel::activeBranchIds() — shared with the daily
 *                  fiscal-chain-monitor cron so the two stay in lockstep.
 *                  Exits 1 if any branch surfaces a tamper, 0 otherwise.
 */
class FiscalVerifyChainCommand extends Command
{
    protected $signature = 'fiscal:verify-chain
        {--branch=1 : Branch id whose NF525 chains should be verified (refuses 0, use --all)}
        {--all : Sweep every active branch (Status::ACTIVE or legacy=1), exit 1 if ANY breaches}';

    protected $description = 'NF525 dual-chain integrity verification (re-walks audit_logs + z_reports HMAC chains; pass --branch=<id> for single or --all for sweep).';

    public function handle(AuditLogService $auditService, ZReportService $zService): int
    {
        // [Wave 2d FISCAL-ADV3C-03 2026-05-18] Explicit sweep flag.
        //
        // Previously `--branch=0` was treated as "cross-branch sweep" by
        // the docstring while the underlying service calls actually filtered
        // `branch_id = 0` (admin/global writes only). Operators following
        // the docstring after a suspected breach got a false-negative
        // "CHAIN OK exit 0" for any real tamper in branch_id >= 1.
        //
        // Now: `--branch=0` is rejected (exit 2). `--all` does the real
        // sweep by reusing Kernel::activeBranchIds(), so the daily cron
        // and the on-demand operator command share one definition of
        // "every active branch" — no second source of drift.
        if ($this->option('all')) {
            return $this->handleAllSweep($auditService, $zService);
        }

        $branchId = (int) $this->option('branch');

        if ($branchId === 0) {
            // [Wave 2d FISCAL-ADV3C-03 2026-05-18] Two short lines instead of
            // one long one to dodge Symfony Console block-formatter wrap.
            $this->error('Branch ID 0 is reserved (admin/global writes).');
            $this->line('Pass --all to sweep every active branch.');

            return self::INVALID; // exit code 2
        }

        // [Wave 3 P1 FISCAL-ADV3-01 / Wave 2d FISCAL-ADV3C-03] Validate branch
        // existence before walking the chain. Any non-zero id must resolve to
        // a Branch row, otherwise we'd return a false-negative CHAIN OK
        // because an empty cursor naturally short-circuits to null.
        if (! Branch::where('id', $branchId)->exists()) {
            $this->error(sprintf('Branch ID %d not found.', $branchId));

            return self::INVALID; // exit code 2
        }

        // [Wave 3 P1 FISCAL-ADV3-02 + Wave 3b FISCAL-ADV3B-02]
        // Disambiguate execution errors from tamper detection.
        // DB outage / missing fiscal.audit_secret / unexpected throws
        // surface as exit 3; tamper stays exit 1.
        //
        // Force strict=false on ZReportService::verifyChain so it
        // returns the structured result array (never throws on tamper);
        // exit-code 1 semantics are owned exclusively here, not by the
        // frozen service's strict-mode RuntimeException path. This keeps
        // exit 1 = TAMPER and exit 3 = exec error cleanly separated.
        try {
            $auditBrokenId = $auditService->verifyChain($branchId);
            $zResult = $zService->verifyChain($branchId, false);
        } catch (\Throwable $e) {
            $this->error(sprintf(
                'Verification FAILED to execute: %s',
                $e->getMessage()
            ));

            return 3; // exit code 3: execution error
        }

        $tamperFragments = [];

        if ($auditBrokenId !== null) {
            $tamperFragments[] = sprintf('audit_logs.id=%d', $auditBrokenId);
        }

        // ZReportService::verifyChain returns:
        //   ['valid' => bool, 'first_z_id' => ?int, 'last_z_id' => ?int,
        //    'count' => int, 'errors' => [['z_id' => int, 'kind' => str, ...], ...]]
        //
        // [Wave 2d FISCAL-ADV3C-02 2026-05-18] Loop ALL errors, not just
        // errors[0]. ZReportService::verifyChain accumulates three breach
        // kinds (chain_break, sequence_gap, signature_mismatch) into the
        // errors[] array (see ZReportService.php:486-544). Previously the
        // command surfaced only the first row, forcing operators to run
        // N-1 cron passes to fully enumerate a coordinated tamper — and
        // worse, opened a window where a re-imported archive could re-sign
        // over a still-corrupted prev_hash. Now every breach is named in
        // a single stdout line.
        if (! ($zResult['valid'] ?? true)) {
            $zErrors = $zResult['errors'] ?? [];
            if (empty($zErrors)) {
                $tamperFragments[] = 'z_reports.chain=invalid';
            } else {
                foreach ($zErrors as $err) {
                    $zId  = $err['z_id'] ?? null;
                    $kind = $err['kind'] ?? 'unknown';
                    $tamperFragments[] = $zId !== null
                        ? sprintf('z_reports.id=%d (%s)', $zId, $kind)
                        : sprintf('z_reports.chain=invalid (%s)', $kind);
                }
            }
        }

        if (empty($tamperFragments)) {
            $this->info(sprintf('CHAIN OK (audit_logs + z_reports) (branch=%d)', $branchId));

            return self::SUCCESS;
        }

        // [Wave 2d FISCAL-ADV3C-02 2026-05-18] Symfony Console's block
        // formatter ($this->error) wraps long single lines and can split
        // a fragment across rows, breaking substring-match assertions
        // when multiple breaches are reported. Emit the header via
        // $this->error then each fragment via $this->line so every id
        // stays on its own row + still passes operator parsing
        // (`grep z_reports.id=` enumerates all).
        $this->error(sprintf(
            'TAMPER detected (branch=%d, breaches=%d)',
            $branchId,
            count($tamperFragments)
        ));
        foreach ($tamperFragments as $fragment) {
            $this->line('  - '.$fragment);
        }

        return self::FAILURE;
    }

    /**
     * [Wave 2d FISCAL-ADV3C-03 2026-05-18]
     *
     * Real cross-branch sweep — closes the gap that `--branch=0` used to
     * pretend to fill. Reuses Kernel::activeBranchIds() so the operator
     * CLI and the daily fiscal-chain-monitor cron share one definition
     * of "active". Per-branch try/catch routes throws to exit 3 lane
     * (consistent with single-branch semantic). Aggregate exit:
     *   - any branch tamper  → exit 1 (TAMPER)
     *   - any branch throw   → exit 3 (execution error) WHEN no tamper
     *     surfaced; tamper precedes exec error in priority because
     *     NF525 breach is the more urgent operator signal.
     *   - all branches clean → exit 0
     */
    private function handleAllSweep(AuditLogService $auditService, ZReportService $zService): int
    {
        $branchIds = AppConsoleKernel::activeBranchIds();

        if ($branchIds->isEmpty()) {
            $this->warn('No active branches found; nothing to verify.');

            return self::SUCCESS;
        }

        $tampered = [];
        $errored = [];

        foreach ($branchIds as $branchId) {
            try {
                $auditBrokenId = $auditService->verifyChain($branchId);
                $zResult = $zService->verifyChain($branchId, false);
            } catch (\Throwable $e) {
                $errored[] = ['branch_id' => $branchId, 'message' => $e->getMessage()];
                $this->line(sprintf('  ! branch=%d EXEC ERROR: %s', $branchId, $e->getMessage()));

                continue;
            }

            $fragments = [];

            if ($auditBrokenId !== null) {
                $fragments[] = sprintf('audit_logs.id=%d', $auditBrokenId);
            }

            if (! ($zResult['valid'] ?? true)) {
                foreach (($zResult['errors'] ?? []) as $err) {
                    $zId  = $err['z_id'] ?? null;
                    $kind = $err['kind'] ?? 'unknown';
                    $fragments[] = $zId !== null
                        ? sprintf('z_reports.id=%d (%s)', $zId, $kind)
                        : sprintf('z_reports.chain=invalid (%s)', $kind);
                }
            }

            if (empty($fragments)) {
                $this->line(sprintf('  + branch=%d CHAIN OK', $branchId));
            } else {
                $tampered[] = ['branch_id' => $branchId, 'fragments' => $fragments];
                $this->line(sprintf('  - branch=%d TAMPER:', $branchId));
                foreach ($fragments as $fragment) {
                    $this->line('      * '.$fragment);
                }
            }
        }

        if (! empty($tampered)) {
            $this->error(sprintf(
                'SWEEP COMPLETE — TAMPER detected on %d/%d branches (exec_errors=%d)',
                count($tampered),
                $branchIds->count(),
                count($errored)
            ));

            return self::FAILURE; // exit 1 — tamper precedes exec error
        }

        if (! empty($errored)) {
            $this->error(sprintf(
                'SWEEP COMPLETE — %d/%d branches errored (no tampers observed on the rest)',
                count($errored),
                $branchIds->count()
            ));

            return 3; // exec error
        }

        $this->info(sprintf(
            'SWEEP COMPLETE — CHAIN OK on every active branch (%d total)',
            $branchIds->count()
        ));

        return self::SUCCESS;
    }
}
