<?php

namespace Tests\Feature\Fiscal;

use App\Models\Branch;
use App\Models\Order;
use App\Services\Fiscal\FiscalSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [POS-9.4.2 / POS-GA-F-38]
 *
 * Verifies that FiscalSequenceService::next() hands out strictly
 * monotonic, gap-free numbers scoped per branch (NF525 invariant).
 */
class FiscalSequenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_number_for_a_branch_is_one(): void
    {
        $branch  = Branch::factory()->create();
        $service = app(FiscalSequenceService::class);

        $this->assertSame(1, $service->next($branch->id));
    }

    public function test_atomic_per_branch_no_gaps(): void
    {
        $branch  = Branch::factory()->create();
        $service = app(FiscalSequenceService::class);

        $reserved = [];
        for ($i = 0; $i < 10; $i++) {
            $n = $service->next($branch->id);
            Order::factory()->create([
                'branch_id'          => $branch->id,
                'fiscal_sequence_no' => $n,
            ]);
            $reserved[] = $n;
        }

        $this->assertSame(range(1, 10), $reserved, 'Sequence must be gap-free 1..10.');

        $persisted = Order::query()
            ->where('branch_id', $branch->id)
            ->orderBy('fiscal_sequence_no')
            ->pluck('fiscal_sequence_no')
            ->map(fn ($v) => (int) $v)
            ->all();
        $this->assertSame(range(1, 10), $persisted, 'DB rows must mirror the sequence.');
    }

    public function test_sequences_are_independent_per_branch(): void
    {
        $a = Branch::factory()->create();
        $b = Branch::factory()->create();
        $service = app(FiscalSequenceService::class);

        $this->assertSame(1, $service->next($a->id));
        Order::factory()->create(['branch_id' => $a->id, 'fiscal_sequence_no' => 1]);

        $this->assertSame(1, $service->next($b->id), 'Second branch must start at 1.');
        Order::factory()->create(['branch_id' => $b->id, 'fiscal_sequence_no' => 1]);

        $this->assertSame(2, $service->next($a->id));
        $this->assertSame(2, $service->next($b->id));
    }

    public function test_non_positive_branch_id_is_rejected(): void
    {
        $service = app(FiscalSequenceService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->next(0);
    }

    public function test_continues_from_existing_max(): void
    {
        $branch = Branch::factory()->create();
        Order::factory()->create([
            'branch_id'          => $branch->id,
            'fiscal_sequence_no' => 42,
        ]);

        $service = app(FiscalSequenceService::class);
        $this->assertSame(43, $service->next($branch->id));
    }
}
