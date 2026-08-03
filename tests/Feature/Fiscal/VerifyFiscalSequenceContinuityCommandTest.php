<?php

namespace Tests\Feature\Fiscal;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [TERRAIN-HEAL 2026-07-16 · FISCAL-NO-GAP-DETECTOR]
 *
 * `fiscal:verify-sequence-continuity` comble l'angle mort d'observabilité NF525 :
 * ni verify-chain (HMAC) ni verify-z-membership (appartenance) ne scannent la
 * CONTINUITÉ 1..MAX de orders.fiscal_sequence_no. Ces tests verrouillent :
 *  (1) une séquence sans trou → exit 0 (SUCCESS) ;
 *  (2) une séquence avec un trou → exit 1 (FAILURE) + numéro manquant nommé.
 */
class VerifyFiscalSequenceContinuityCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedOrder(int $branchId, int $seq): void
    {
        $order = Order::factory()->create(['branch_id' => $branchId]);
        // fiscal_sequence_no est protégé/alloué par le service — on l'écrit en direct pour le test.
        Order::withoutGlobalScope(BranchScope::class)->whereKey($order->id)
            ->update(['fiscal_sequence_no' => $seq]);
    }

    public function test_gap_free_sequence_passes(): void
    {
        $branch = Branch::factory()->create();
        foreach ([1, 2, 3, 4] as $seq) {
            $this->seedOrder($branch->id, $seq);
        }

        $this->artisan('fiscal:verify-sequence-continuity', ['--branch' => $branch->id])
            ->assertExitCode(0);
    }

    public function test_gap_is_detected_and_fails(): void
    {
        $branch = Branch::factory()->create();
        foreach ([1, 2, 4] as $seq) { // 3 manquant
            $this->seedOrder($branch->id, $seq);
        }

        $this->artisan('fiscal:verify-sequence-continuity', ['--branch' => $branch->id])
            ->expectsOutputToContain('3')
            ->assertExitCode(1);
    }

    public function test_soft_deleted_allocated_order_still_counts(): void
    {
        // Un order soft-deleted APRÈS allocation garde son numéro → ne doit PAS créer de faux trou.
        $branch = Branch::factory()->create();
        foreach ([1, 2, 3] as $seq) {
            $this->seedOrder($branch->id, $seq);
        }
        Order::withoutGlobalScope(BranchScope::class)->where('branch_id', $branch->id)
            ->where('fiscal_sequence_no', 2)->delete(); // soft-delete

        $this->artisan('fiscal:verify-sequence-continuity', ['--branch' => $branch->id])
            ->assertExitCode(0); // 1,2,3 toujours vus via withTrashed → gap-free
    }
}
