<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Fiscal\FiscalSequenceService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;

/**
 * Real-concurrency probe for the NF525 fiscal sequence.
 *
 * Unlike `kiosk:simulate-orders` (a single-process sequential for-loop
 * that never contends on the lock), this command is designed to be
 * launched as K parallel OS processes by `scripts/fiscal-load-probe.sh`.
 * Each process opens its own DB connection, so the parallel callers
 * genuinely contend on FiscalSequenceService::next()'s cache lock +
 * row-level lockForUpdate — the only honest way to prove the gap-free /
 * no-duplicate invariant under load (CLAUDE.md §3.7/§3.8, §11 evidence).
 *
 * Modes:
 *   --setup            create a fresh, empty branch (+ a worker user) and
 *                      print "BRANCH_ID=<id>" for the shell runner to read.
 *   {branch} {count}   worker: allocate `count` fiscal numbers for `branch`
 *                      via the real service and persist Orders.
 *   --verify=<branch>  assert the branch's persisted fiscal numbers form a
 *                      contiguous, duplicate-free run; non-zero exit on any
 *                      gap or collision.
 */
class FiscalLoadProbe extends Command
{
    protected $signature = 'fiscal:load-probe
                            {branch=0 : Branch id to allocate against (worker mode)}
                            {count=50 : How many fiscal numbers this worker allocates}
                            {--setup : Create a fresh empty branch and print BRANCH_ID=<id>}
                            {--verify= : Verify gap-free/no-dup invariant for the given branch}';

    protected $description = 'Real multi-process concurrency probe for the NF525 fiscal sequence (gap-free / no-dup proof).';

    public function handle(FiscalSequenceService $sequence): int
    {
        if ($this->option('setup')) {
            return $this->setup();
        }

        if ($this->option('verify') !== null) {
            return $this->verify((int) $this->option('verify'));
        }

        return $this->work($sequence, (int) $this->argument('branch'), (int) $this->argument('count'));
    }

    private function setup(): int
    {
        // Factories fill every NOT NULL column (city/state/zip/...) so the
        // probe is robust across schema additions.
        $branch = Branch::factory()->create([
            'name' => 'LoadProbe ' . now()->format('His') . '-' . random_int(1000, 9999),
        ]);

        // A worker user FK for the Orders the probe persists.
        User::factory()->create(['branch_id' => $branch->id]);

        // Machine-readable line the shell runner greps for.
        $this->line('BRANCH_ID=' . $branch->id);

        return self::SUCCESS;
    }

    private function work(FiscalSequenceService $sequence, int $branchId, int $count): int
    {
        if ($branchId <= 0) {
            $this->error('Worker mode requires a positive {branch}. Run --setup first.');
            return self::FAILURE;
        }

        $userId = User::where('branch_id', $branchId)->value('id')
            ?? User::query()->value('id');

        for ($i = 0; $i < $count; $i++) {
            $this->allocateOneWithRetry($sequence, $branchId, $userId);
        }

        return self::SUCCESS;
    }

    /**
     * Allocate + persist one fiscal number, modelling the correct caller
     * contract: the service reserves MAX+1, and the DB unique key
     * (orders_branch_fiscal_seq_unique) is the ULTIMATE gate. On a weak /
     * slow lock driver two callers may compute the same number or fail to
     * acquire the lock — both are transient and the caller must re-allocate.
     * Because next() recomputes from persisted rows, a rejected insert never
     * burns a number, so retries converge to a gap-free chain.
     */
    private function allocateOneWithRetry(FiscalSequenceService $sequence, int $branchId, ?int $userId): void
    {
        $maxAttempts = 50;

        for ($attempt = 1; ; $attempt++) {
            try {
                $n = $sequence->next($branchId);

                $order = new Order([
                    'order_serial_no' => date('dmy') . random_int(100000, 999999),
                    'user_id'         => $userId,
                    'branch_id'       => $branchId,
                    'subtotal'        => 15.00,
                    'total_tax'       => 1.50,
                    'total'           => 16.50,
                    'order_type'      => 10,
                    'order_datetime'  => now(),
                    'payment_method'  => 1,
                    'payment_status'  => 10,
                    'status'          => 1,
                    'source'          => 'load_probe',
                ]);
                // fiscal_sequence_no is intentionally NOT mass-assignable
                // (fiscal safety) — set it directly, mirroring OrderService.
                $order->fiscal_sequence_no = $n;
                $order->save();

                return;
            } catch (LockTimeoutException $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
            } catch (QueryException $e) {
                if (!$this->isTransientContention($e) || $attempt >= $maxAttempts) {
                    throw $e;
                }
            }

            // Jittered back-off before re-allocating.
            usleep(random_int(2000, 9000));
        }
    }

    /**
     * True for the transient, retryable contention errors a correct caller
     * absorbs: the unique-key rejection of a duplicate fiscal number, or a
     * busy/locked storage engine (SQLite under parallel writers).
     */
    private function isTransientContention(QueryException $e): bool
    {
        $msg = $e->getMessage();

        return ((string) $e->getCode() === '23000')          // integrity constraint violation
            || str_contains($msg, 'UNIQUE')
            || str_contains($msg, 'database is locked')
            || str_contains($msg, 'database table is locked');
    }

    private function verify(int $branchId): int
    {
        if ($branchId <= 0) {
            $this->error('--verify requires a positive branch id.');
            return self::FAILURE;
        }

        $stats = Order::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereNotNull('fiscal_sequence_no')
            ->selectRaw('COUNT(*) AS total, COUNT(DISTINCT fiscal_sequence_no) AS distinct_total, MIN(fiscal_sequence_no) AS min_no, MAX(fiscal_sequence_no) AS max_no')
            ->first();

        $total    = (int) $stats->total;
        $distinct = (int) $stats->distinct_total;
        $min      = (int) $stats->min_no;
        $max      = (int) $stats->max_no;

        if ($total === 0) {
            $this->error("Branch {$branchId}: no fiscal rows to verify.");
            return self::FAILURE;
        }

        $duplicates = $total - $distinct;                 // >0 means a collision slipped past the lock
        $expectedSpan = $max - $min + 1;                  // contiguous run size
        $gaps = $expectedSpan - $distinct;                // >0 means a hole in the chain

        $this->info("Branch {$branchId}: total={$total} distinct={$distinct} min={$min} max={$max}");

        $ok = true;
        if ($duplicates !== 0) {
            $this->error("VIOLATION: {$duplicates} duplicate fiscal number(s) — lock failed under concurrency.");
            $ok = false;
        }
        if ($gaps !== 0) {
            $this->error("VIOLATION: {$gaps} gap(s) in the chain [{$min}..{$max}] — sequence not gap-free.");
            $ok = false;
        }

        if ($ok) {
            $this->info("gap-free OK / 0 dup — contiguous chain {$min}..{$max} ({$total} allocations) verified.");
            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
