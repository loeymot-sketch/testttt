<?php

namespace App\Console\Commands;

use App\Services\Fiscal\AuditLogService;
use Illuminate\Console\Command;

/**
 * [NF525 Wave 1 W-1 heal 2026-05-18]
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
 * Exit codes:
 *   0  = chain verified intact for the given branch.
 *   1  = tampering detected; output names the audit_logs.id of the
 *        first broken row so the operator can pinpoint the breach.
 */
class FiscalVerifyChainCommand extends Command
{
    protected $signature = 'fiscal:verify-chain {--branch=1 : Branch id whose audit chain should be verified}';

    protected $description = 'NF525 audit chain integrity verification (re-walks HMAC chain for a branch).';

    public function handle(AuditLogService $service): int
    {
        $branchId = (int) $this->option('branch');

        $brokenId = $service->verifyChain($branchId);

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
