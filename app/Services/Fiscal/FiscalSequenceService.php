<?php

namespace App\Services\Fiscal;

use App\Models\Order;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * [POS-9.4.2 / POS-GA-F-38]
 *
 * Allocates a strictly monotonic, gap-free fiscal sequence number per
 * branch, as required by NF525 / Loi Finance 2018 anti-fraude TVA.
 *
 * Invariant contract:
 *  - every successful call returns MAX(fiscal_sequence_no) + 1 for the
 *    branch (starting at 1);
 *  - concurrent callers on the same branch are serialised by a named
 *    cache lock, so two parallel POS transactions never collide on the
 *    DB unique constraint (orders_branch_fiscal_seq_unique);
 *  - the service only reserves the next number — the caller is
 *    responsible for persisting it on the Order row inside the same
 *    transaction (wire-in deferred to POS-9.4.2b / BLOCKER OrderService).
 *
 * Concurrency strategy:
 *  - Cache::lock('fiscal_seq_{branch}', 5) blocks up to 3 s to acquire;
 *  - inside the lock we run a transactional SELECT MAX / +1 so the DB
 *    value stays authoritative even if the cache is cold or evicted;
 *  - the cache lock is a *correctness* optimisation (it reduces
 *    unique-index contention) — the DB unique key is the ultimate gate.
 */
class FiscalSequenceService
{
    /**
     * Max wait for the branch lock (seconds).
     *
     * Tight enough to fail fast under pathological contention, loose
     * enough to survive a burst of 5-6 concurrent POS checkouts.
     */
    private const LOCK_TTL_SECONDS      = 5;
    private const LOCK_ACQUIRE_SECONDS  = 3;

    public function __construct(
        private ?ConnectionInterface $connection = null
    ) {
        $this->connection = $connection ?? DB::connection();
    }

    /**
     * Reserve and return the next fiscal sequence number for a branch.
     *
     * @throws RuntimeException when the lock cannot be acquired within
     *                          LOCK_ACQUIRE_SECONDS.
     */
    public function next(int $branchId): int
    {
        if ($branchId <= 0) {
            throw new \InvalidArgumentException(
                'FiscalSequenceService::next() requires a positive branch_id.'
            );
        }

        $lockKey = sprintf('fiscal_seq_b%d', $branchId);
        $lock    = Cache::lock($lockKey, self::LOCK_TTL_SECONDS);

        try {
            if (!$lock->block(self::LOCK_ACQUIRE_SECONDS)) {
                throw new RuntimeException(
                    "FiscalSequenceService: could not acquire lock {$lockKey} within "
                    . self::LOCK_ACQUIRE_SECONDS . 's.'
                );
            }

            return $this->connection->transaction(function () use ($branchId) {
                // [POS-9-H.2.10 / F-B10]
                // Defense in depth: even inside the cache lock, a second
                // writer that slipped past (cache outage, split-brain,
                // expired lock) would otherwise race us on the SELECT
                // MAX. `->lockForUpdate()` takes a row-level DB lock on
                // the matching rows so concurrent transactions serialise
                // at the storage engine — keeping sequences strictly
                // monotonic even when the cache is unavailable.
                //
                // SQLite ignores FOR UPDATE (it uses BEGIN IMMEDIATE
                // semantics instead) so this stays a no-op in tests.
                // [Z6-P1-WGS 2026-05-19] NF525 invariant — fiscal sequence is
                // strictly monotonic + gap-free per branch. We MUST consider
                // soft-deleted orders when computing MAX(fiscal_sequence_no)
                // because an Order that allocated a number stays in the
                // table (Order::restoring throws — soft delete is one-way
                // audit) and dropping it would cause sequence_no re-use →
                // chain violation. Singular bypass + ->withTrashed() makes
                // both intents explicit (mirrors ZReportService:337-338
                // canonical pattern, RED-Z6 Q#17).
                $max = (int) Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                    ->withTrashed()
                    ->where('branch_id', $branchId)
                    ->lockForUpdate()
                    ->max('fiscal_sequence_no');

                return $max + 1;
            });
        } finally {
            // Release is idempotent; wrap in try so a double-release never
            // masks the caller's own exception.
            try {
                $lock->release();
            } catch (\Throwable $e) {
                // best-effort — the lock will self-expire anyway.
            }
        }
    }
}
