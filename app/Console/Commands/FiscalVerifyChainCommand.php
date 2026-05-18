<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Console\Command;

/**
 * [NF525 Wave 1 W-1 heal 2026-05-18]
 * [NF525 Wave 3 P1 heal FISCAL-ADV3-01/02 2026-05-18]
 *
 * Operator-facing CLI primitive that re-walks the `audit_logs` HMAC
 * chain for a single branch and reports the first row whose hash no
 * longer matches (or "CHAIN OK" when the chain is intact).
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
    protected $signature = 'fiscal:verify-chain {--branch=1 : Branch id whose audit chain should be verified}';

    protected $description = 'NF525 audit chain integrity verification (re-walks HMAC chain for a branch).';

    public function handle(AuditLogService $service): int
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

        // [Wave 3 P1 FISCAL-ADV3-02] Disambiguate execution errors from
        // tamper detection. DB outage / missing fiscal.audit_secret /
        // unexpected throws surface as exit 3; tamper stays exit 1.
        try {
            $brokenId = $service->verifyChain($branchId);
        } catch (\Throwable $e) {
            $this->error(sprintf(
                'Verification FAILED to execute: %s',
                $e->getMessage()
            ));

            return 3; // exit code 3: execution error
        }

        if ($brokenId === null) {
            $this->info(sprintf('CHAIN OK (branch=%d)', $branchId));

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'TAMPER detected at audit_logs.id=%d (branch=%d)',
            $brokenId,
            $branchId
        ));

        return self::FAILURE;
    }
}
