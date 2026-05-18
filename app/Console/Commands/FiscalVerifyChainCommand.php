<?php

namespace App\Console\Commands;

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
 *   0  = chain verified intact for the given branch.
 *   1  = TAMPER detected; output names the audit_logs.id of the
 *        first broken row so the operator can pinpoint the breach.
 *   2  = INVALID arguments (branch id does not exist in branches table).
 *        Wave 3 P1 FISCAL-ADV3-01: previously `--branch=99` on a single
 *        resto returned a false-negative `CHAIN OK exit 0` because the
 *        verifier iterated an empty cursor. Now hard-fails before the
 *        service call.
 *   3  = EXECUTION ERROR (DB outage, missing/weak secret, unexpected
 *        throw). Distinct from TAMPER so monitoring can route DB/cred
 *        incidents to ops vs. NF525 integrity breaches to compliance.
 */
class FiscalVerifyChainCommand extends Command
{
    protected $signature = 'fiscal:verify-chain {--branch=1 : Branch id whose NF525 chains should be verified}';

    protected $description = 'NF525 dual-chain integrity verification (re-walks audit_logs + z_reports HMAC chains for a branch).';

    public function handle(AuditLogService $auditService, ZReportService $zService): int
    {
        $branchId = (int) $this->option('branch');

        // [Wave 3 P1 FISCAL-ADV3-01] Validate branch existence before
        // walking the chain. Admin/global branch_id=0 is permitted
        // (cross-branch sweep). Any other id must resolve to a Branch
        // row, otherwise we'd return a false-negative CHAIN OK because
        // an empty cursor naturally short-circuits to null.
        if ($branchId !== 0 && ! Branch::where('id', $branchId)->exists()) {
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
}
