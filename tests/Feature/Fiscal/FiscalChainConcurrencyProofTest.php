<?php

namespace Tests\Feature\Fiscal;

use App\Models\Branch;
use App\Models\Order;
use App\Services\Fiscal\FiscalSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DB-level invariant proof for the NF525 fiscal sequence.
 *
 * IMPORTANT LIMITATION (documented on purpose):
 *   A single PHPUnit process cannot genuinely parallelise
 *   FiscalSequenceService::next() — the SQLite :memory: test DB has one
 *   connection and `lockForUpdate` is a no-op there. So this test proves
 *   the *invariant shape* (contiguous, duplicate-free, monotonic) that
 *   the verifier in `scripts/fiscal-load-probe.sh` checks after REAL
 *   multi-process contention. The honest concurrency evidence is the
 *   shell runner; this test guards the same assertions in CI.
 *
 * @see scripts/fiscal-load-probe.sh   real K-process contention probe
 * @see \App\Console\Commands\FiscalLoadProbe   worker + verifier
 */
class FiscalChainConcurrencyProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_invariant_matches_the_shell_probe_verifier(): void
    {
        $branch  = Branch::factory()->create();
        $service = app(FiscalSequenceService::class);

        $count = 300;
        for ($i = 0; $i < $count; $i++) {
            $n = $service->next($branch->id);
            Order::factory()->create([
                'branch_id'          => $branch->id,
                'fiscal_sequence_no' => $n,
            ]);
        }

        // Same query shape as FiscalLoadProbe::verify(): total / distinct /
        // min / max -> derive duplicates and gaps.
        $stats = Order::withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->whereNotNull('fiscal_sequence_no')
            ->selectRaw('COUNT(*) AS total, COUNT(DISTINCT fiscal_sequence_no) AS distinct_total, MIN(fiscal_sequence_no) AS min_no, MAX(fiscal_sequence_no) AS max_no')
            ->first();

        $total      = (int) $stats->total;
        $distinct   = (int) $stats->distinct_total;
        $min        = (int) $stats->min_no;
        $max        = (int) $stats->max_no;
        $duplicates = $total - $distinct;
        $gaps       = ($max - $min + 1) - $distinct;

        $this->assertSame(0, $duplicates, 'No duplicate fiscal numbers allowed.');
        $this->assertSame(0, $gaps, 'Chain must be gap-free between min and max.');
        $this->assertSame(1, $min, 'A fresh branch must start its chain at 1.');
        $this->assertSame($count, $max, "Max must equal the allocation count ({$count}).");
    }
}
