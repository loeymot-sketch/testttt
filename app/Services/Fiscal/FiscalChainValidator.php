<?php

namespace App\Services\Fiscal;

use App\Exceptions\FiscalChainCorruptedException;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * [P11-FZH / F-VERIFY-08-01]
 *
 * Single-entry orchestrator that asserts BOTH chains intact for a branch:
 *  - Z chain → delegated to ZReportService::verifyChain() (existing,
 *    fast O(daily) per branch).
 *  - audit_logs chain → bounded TAIL check (config window default 500),
 *    avoids deadlock under the 4s z_report_b{N} cache lock that wraps
 *    Z.open().
 *
 * Feature flag `fiscal.chain_validation_enabled` (env
 * FISCAL_CHAIN_VALIDATION_ENABLED, default true). When false, the audit
 * chain extension is skipped (legacy Z-only behaviour preserved).
 */
class FiscalChainValidator
{
    private ?ZReportService $zReportService = null;
    private ?AuditLogService $auditLogService = null;

    public function __construct(
        ?ZReportService $zReportService = null,
        ?AuditLogService $auditLogService = null,
    ) {
        // Lazy storage to break a constructor-cycle with ZReportService
        // (which itself lazy-resolves FiscalChainValidator).
        $this->zReportService = $zReportService;
        $this->auditLogService = $auditLogService;
    }

    private function zReportService(): ZReportService
    {
        return $this->zReportService ??= app(ZReportService::class);
    }

    private function auditLogService(): AuditLogService
    {
        return $this->auditLogService ??= app(AuditLogService::class);
    }

    /**
     * Assert both chains intact. Throws FiscalChainCorruptedException on any
     * mismatch. No-op when chain_validation_enabled=false.
     *
     * @throws FiscalChainCorruptedException
     */
    public function assertChainIntegrity(int $branchId): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        // 1) Z chain — re-run verifyChain in strict mode. The legacy method
        //    already throws RuntimeException with the canonical message
        //    'NF525 Z-chain verification failed for branch ...' that test
        //    contracts depend on.
        try {
            $this->zReportService()->verifyChain($branchId, true);
        } catch (\RuntimeException $e) {
            // Capture structured errors from a non-strict re-walk for telemetry.
            $errors = [];
            try {
                $report = $this->zReportService()->verifyChain($branchId, false);
                $errors = is_array($report) ? ($report['errors'] ?? []) : [];
            } catch (\Throwable) {
                // best-effort
            }
            throw new FiscalChainCorruptedException(
                FiscalChainCorruptedException::KIND_Z_CHAIN,
                $branchId,
                $errors,
                $e->getMessage(),
                $e
            );
        }

        // 2) Audit chain — bounded tail (avoids full O(N) walk under lock).
        $window = (int) Config::get('fiscal.audit_chain_tail_window', 500);
        $errors = $this->verifyAuditChainTail($branchId, $window);
        if ($errors !== []) {
            Log::channel('fiscal')->error('NF525 audit chain tail verification failed', [
                'event'     => 'fiscal.audit_chain.verification_failed',
                'branch_id' => $branchId,
                'window'    => $window,
                'errors'    => $errors,
            ]);
            throw new FiscalChainCorruptedException(
                FiscalChainCorruptedException::KIND_AUDIT_CHAIN,
                $branchId,
                $errors,
                sprintf(
                    'NF525 audit chain verification failed for branch %d (window=%d, errors=%d). See fiscal log.',
                    $branchId,
                    $window,
                    count($errors)
                )
            );
        }
    }

    /**
     * Re-walk the last $window rows of audit_logs for this branch. Returns
     * an array of structured error rows (empty array if intact).
     *
     * Uses a DESC LIMIT then ASC walk to bound the scan to $window rows
     * — keeps the validator under 50ms even on 10M-row tables.
     *
     * @return array<int, array{audit_log_id:int, kind:string, expected:string, actual:string}>
     */
    public function verifyAuditChainTail(int $branchId, int $window): array
    {
        $tailIds = AuditLog::query()
            ->where('branch_id', $branchId)
            ->orderByDesc('id')
            ->limit(max(1, $window))
            ->pluck('id')
            ->toArray();

        if (empty($tailIds)) {
            return [];
        }

        $oldestId = (int) min($tailIds);

        $rows = AuditLog::query()
            ->where('branch_id', $branchId)
            ->where('id', '>=', $oldestId)
            ->orderBy('id')
            ->cursor();

        $errors       = [];
        $expectedPrev = null;
        $isFirstRow   = true;

        foreach ($rows as $row) {
            /** @var AuditLog $row */
            $rowPrev = $row->prev_hash === null ? null : trim((string) $row->prev_hash);

            // First row of the window: prev_hash anchor is outside the window —
            // we can only validate the current_hash recompute, not the link.
            if (!$isFirstRow) {
                $expectedPrevNorm = $expectedPrev === null ? null : trim((string) $expectedPrev);
                if ($rowPrev !== $expectedPrevNorm) {
                    $errors[] = [
                        'audit_log_id' => (int) $row->id,
                        'kind'         => 'chain_break',
                        'expected'     => (string) $expectedPrevNorm,
                        'actual'       => (string) $rowPrev,
                    ];
                }
            }

            $recomputed = $this->auditLogService()->computeHash(
                (int) ($row->branch_id ?? 0),
                $rowPrev,
                (string) $row->action,
                (array) ($row->payload ?? [])
            );

            $stored = trim((string) $row->current_hash);
            if (!hash_equals($stored, $recomputed)) {
                $errors[] = [
                    'audit_log_id' => (int) $row->id,
                    'kind'         => 'signature_mismatch',
                    'expected'     => $recomputed,
                    'actual'       => $stored,
                ];
            }

            $expectedPrev = $stored;
            $isFirstRow   = false;
        }

        return $errors;
    }

    private function isEnabled(): bool
    {
        return (bool) Config::get('fiscal.chain_validation_enabled', true);
    }
}
