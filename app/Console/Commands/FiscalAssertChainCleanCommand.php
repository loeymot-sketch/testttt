<?php

namespace App\Console\Commands;

use App\Console\Kernel as AppConsoleKernel;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\ZReportService;
use Illuminate\Console\Command;

/**
 * [V1.0.2 Wave C1 UR4-007 2026-05-25]
 *
 * Deploy-gate version of fiscal:verify-chain.
 *
 * Walks BOTH NF525 HMAC chains (audit_logs + z_reports) across ALL
 * active branches returned by Kernel::activeBranchIds(). Exits
 * non-zero on ANY breach — designed for `set -e` pre-deploy shell
 * guards (deploy.sh / CI pipeline) where the only question that
 * matters is "is the chain safe to ship right now?"
 *
 * Difference from fiscal:verify-chain :
 *   - fiscal:verify-chain        : operator-facing diagnostic.
 *                                  Defaults to --branch=1, supports
 *                                  --all, prints per-branch lines,
 *                                  exit codes 0/1/2/3.
 *   - fiscal:assert-chain-clean  : binary deploy-gate. No flags,
 *                                  always sweeps every active
 *                                  branch, terse output (pass/fail
 *                                  line only), exit codes 0/1/3.
 *
 * Deliberate UX split, NOT duplicated logic — both commands consume
 * the same public API on the FROZEN AuditLogService + ZReportService.
 * The two artisans answer two different operator questions:
 *   "Where is the breach?"  → fiscal:verify-chain --all
 *   "Is it safe to deploy?" → fiscal:assert-chain-clean
 *
 * Z service is called with strict=false explicitly: in production env
 * the service defaults to strict=true which THROWS on tamper. That
 * would surface as exit 3 (exec error) instead of exit 1 (tamper) —
 * exactly the wrong signal for a deploy gate. We own exit-code
 * semantics here, not the frozen service.
 *
 * Usage in deploy.sh :
 *   set -e
 *   php artisan fiscal:assert-chain-clean || {
 *     echo "REFUSING DEPLOY — NF525 chain breach detected"
 *     exit 1
 *   }
 *
 * Exit codes :
 *   0 = both chains clean across every active branch
 *   1 = at least one breach detected (audit_logs OR z_reports, any branch)
 *   3 = execution error (DB outage, missing secret, throw, OR zero
 *       active branches — for a deploy gate, "expected branches found
 *       none" is a config-drift signal worth halting on)
 */
class FiscalAssertChainCleanCommand extends Command
{
    protected $signature = 'fiscal:assert-chain-clean';

    protected $description = 'NF525 deploy-gate: exit non-zero if ANY HMAC chain breach across ALL active branches (designed for `set -e` shell guards).';

    public function handle(
        AuditLogService $auditService,
        ZReportService $zService
    ): int {
        try {
            // [V1.0.2 Wave C1] Reuse the same activeBranchIds() definition
            // as the daily fiscal-chain-monitor cron + fiscal:verify-chain
            // --all so the deploy gate and the monitoring lane stay in
            // lockstep — one source of truth for "every active branch".
            $branchIds = AppConsoleKernel::activeBranchIds();

            if ($branchIds->isEmpty()) {
                // Deploy-gate stance: zero active branches mid-deploy is
                // a config drift signal (e.g. accidental hard-delete or
                // status migration), not a "nothing to check, ship it"
                // green-light. Exit 3 routes to ops, not compliance.
                $this->error('NF525 ASSERT ERROR — no active branches found, refusing to clear deploy gate');
                return 3;
            }

            $breachedBranches = [];
            foreach ($branchIds as $branchId) {
                // AuditLogService::verifyChain(?int $branchId): ?int
                //   returns null on clean, broken row id on tamper.
                $auditBrokenId = $auditService->verifyChain($branchId);

                // ZReportService::verifyChain(int $branchId, ?bool $strict): array
                //   { valid: bool, errors: [...] }
                // Force strict=false so tamper surfaces as a result, not
                // an exception — exit-code 1 (tamper) vs 3 (exec error)
                // semantics owned exclusively here, not by the frozen
                // service's strict-mode RuntimeException path.
                $zResult = $zService->verifyChain($branchId, false);

                $auditBad = $auditBrokenId !== null;
                $zBad     = ! ($zResult['valid'] ?? true);

                if ($auditBad || $zBad) {
                    $breachedBranches[] = $branchId;
                }
            }

            if (! empty($breachedBranches)) {
                $this->error(sprintf(
                    'NF525 ASSERT FAILED — chain integrity breach detected on %d/%d branch(es): [%s]',
                    count($breachedBranches),
                    $branchIds->count(),
                    implode(',', $breachedBranches)
                ));
                return 1;
            }

            $this->info(sprintf(
                'NF525 ASSERT OK — all chains clean across %d active branch(es)',
                $branchIds->count()
            ));
            return 0;

        } catch (\Throwable $e) {
            // DB outage, missing fiscal.audit_secret, unexpected throw —
            // distinct exit 3 lane so monitoring can route infra issues
            // to ops while keeping exit 1 reserved for the NF525
            // compliance signal.
            $this->error('NF525 ASSERT ERROR — ' . $e->getMessage());
            return 3;
        }
    }
}
