<?php

/**
 * [ABUSE — vector fiscal_concurrency 2026-06-17]
 *
 * Adversarial characterization of FiscalSequenceService::next() (FROZEN §7) and its
 * NF525 invariant: fiscal_sequence_no must be strictly monotonic + gap-free per branch,
 * with zero duplicates, even under abuse.
 *
 * IMPORTANT — :memory: LIMITATION (rig note): PHPUnit runs SQLite :memory:, which IGNORES
 * `FOR UPDATE` row locks (BEGIN IMMEDIATE semantics instead). So a TRUE parallel-process
 * lock race CANNOT be proven here — that is left as a burst RECIPE for the orchestrator to
 * run against the shared :8766 MySQL. What IS provable here (and is what this file pins):
 *   - serial correctness / gap-free / monotonic invariant
 *   - SAVEPOINT rollback safety (a rolled-back outer tx must NOT consume a number)
 *   - withTrashed() re-use prevention (soft-deleted allocations still count)
 *   - the "allocate-without-persist-between-calls returns the SAME number" hazard, which
 *     documents WHY every caller MUST persist inside a lockForUpdate tx (the real DB gate)
 */

namespace Tests\Feature\Abuse;

use App\Models\Branch;
use App\Models\Order;
use App\Services\Fiscal\FiscalSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FiscalSequenceConcurrencyAbuseTest extends TestCase
{
    use RefreshDatabase;

    /** @test serial correctness: 50 allocate+persist iterations stay gap-free with zero dupes */
    public function test_fifty_serial_allocations_are_gap_free_and_unique(): void
    {
        $branch = Branch::factory()->create();
        $service = app(FiscalSequenceService::class);

        for ($i = 1; $i <= 50; $i++) {
            $n = $service->next($branch->id);
            Order::factory()->create(['branch_id' => $branch->id, 'fiscal_sequence_no' => $n]);
        }

        $seqs = Order::withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->orderBy('fiscal_sequence_no')
            ->pluck('fiscal_sequence_no')->map(fn ($v) => (int) $v)->all();

        $this->assertSame(range(1, 50), $seqs, 'must be gap-free 1..50');
        $this->assertSame(50, count(array_unique($seqs)), 'zero duplicates');
    }

    /**
     * @test ROLLBACK SAFETY — the code comment at OrderService:1099-1104 claims next() nested
     * inside the caller's DB::transaction creates a SAVEPOINT, so a rolled-back outer tx does
     * NOT consume a fiscal number (next call sees the same MAX again → no GAP). Prove it.
     */
    public function test_rolled_back_outer_transaction_does_not_burn_a_number(): void
    {
        $branch = Branch::factory()->create();
        $service = app(FiscalSequenceService::class);

        // First real allocation persists seq=1.
        $first = $service->next($branch->id);
        Order::factory()->create(['branch_id' => $branch->id, 'fiscal_sequence_no' => $first]);
        $this->assertSame(1, $first);

        // Now: allocate inside an outer tx that ROLLS BACK without persisting.
        try {
            DB::transaction(function () use ($service, $branch) {
                $burned = $service->next($branch->id); // would be 2
                $this->assertSame(2, $burned);
                throw new \RuntimeException('simulate POS checkout failure / cancel');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        // The next genuine allocation MUST still be 2 — the rolled-back attempt left no gap,
        // burned no number. (Because nothing was persisted to orders.fiscal_sequence_no.)
        $second = $service->next($branch->id);
        $this->assertSame(2, $second, 'rolled-back attempt must NOT have consumed a number → no gap');
    }

    /**
     * @test WITHTRASHED RE-USE PREVENTION — a soft-deleted order that allocated a number must
     * still count toward MAX, else the number is re-issued → unique-constraint violation / chain
     * re-use (Z6-P1-WGS invariant at FiscalSequenceService:97-101).
     */
    public function test_soft_deleted_allocation_still_blocks_reuse(): void
    {
        $branch = Branch::factory()->create();
        $service = app(FiscalSequenceService::class);

        $o1 = Order::factory()->create(['branch_id' => $branch->id, 'fiscal_sequence_no' => $service->next($branch->id)]);
        $o2 = Order::factory()->create(['branch_id' => $branch->id, 'fiscal_sequence_no' => $service->next($branch->id)]);
        $this->assertSame([1, 2], [(int) $o1->fiscal_sequence_no, (int) $o2->fiscal_sequence_no]);

        // soft-delete the highest allocation (Order uses SoftDeletes for audit)
        $o2->delete();

        // next() must SKIP past the soft-deleted 2 → return 3, never re-issue 2.
        $next = $service->next($branch->id);
        $this->assertSame(3, $next, 'withTrashed MAX must skip soft-deleted seq → no re-use of 2');
    }

    /**
     * @test PER-BRANCH ISOLATION under interleaving — allocations on branch A must not affect B's
     * sequence even when interleaved (the lock key is per-branch fiscal_seq_b{id}).
     */
    public function test_interleaved_two_branch_allocations_stay_independent(): void
    {
        $a = Branch::factory()->create();
        $b = Branch::factory()->create();
        $svc = app(FiscalSequenceService::class);

        $order = function (Branch $br, int $n) {
            Order::factory()->create(['branch_id' => $br->id, 'fiscal_sequence_no' => $n]);
        };

        $order($a, $svc->next($a->id)); // a=1
        $order($b, $svc->next($b->id)); // b=1
        $order($a, $svc->next($a->id)); // a=2
        $order($a, $svc->next($a->id)); // a=3
        $order($b, $svc->next($b->id)); // b=2

        $this->assertSame([1, 2, 3], Order::withoutGlobalScopes()->where('branch_id', $a->id)
            ->orderBy('fiscal_sequence_no')->pluck('fiscal_sequence_no')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([1, 2], Order::withoutGlobalScopes()->where('branch_id', $b->id)
            ->orderBy('fiscal_sequence_no')->pluck('fiscal_sequence_no')->map(fn ($v) => (int) $v)->all());
    }

    /**
     * @test ALLOCATE-WITHOUT-PERSIST HAZARD (documents the contract, not a vuln in itself).
     * Two next() calls with NO persist between them return the SAME number. This is BY DESIGN —
     * next() only "reserves" MAX+1 from the table; the caller MUST persist inside a lockForUpdate
     * tx. This test exists so any refactor that breaks the "persist-between-calls" contract is
     * caught, and to make explicit that the Cache::lock alone does NOT prevent this — the DB
     * unique key (orders_branch_fiscal_seq_unique) is the ultimate gate on real MySQL.
     */
    public function test_two_reservations_without_persist_collide_by_design(): void
    {
        $branch = Branch::factory()->create();
        $svc = app(FiscalSequenceService::class);

        $a = $svc->next($branch->id);
        $b = $svc->next($branch->id); // nothing persisted between → same MAX+1

        $this->assertSame($a, $b, 'reservations without an intervening persist collide — caller MUST persist in a locked tx');
        $this->assertSame(1, $a);
    }
}
