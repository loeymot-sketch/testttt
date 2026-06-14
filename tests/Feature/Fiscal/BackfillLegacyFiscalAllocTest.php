<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\ZReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * NF-3 / DELIV-NF525-01 (GOAL 2.0 Phase 2) — legacy fiscal-seq backfill command.
 * Owner-gated, dry-run by default. Allocates a gap-free seq for legacy realized-PAID
 * rows that escaped the retry-cron (no fiscal_alloc_error_at), so they re-enter the Z.
 */
class BackfillLegacyFiscalAllocTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('fiscal.audit_secret', 'unit-test-secret-bf-x');
        Config::set('fiscal.z_report_secret', 'unit-test-secret-bf-z');
    }

    private function legacyPaidNoSeq(int $branchId, Carbon $createdAt, float $total = 25.00, int $status = OrderStatus::DELIVERED): Order
    {
        return Order::factory()->create([
            'branch_id'          => $branchId,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => $status,
            'total'              => $total,
            'fiscal_sequence_no' => null,
            'created_at'         => $createdAt,
        ]);
    }

    private function closedZ(int $branchId, Carbon $closedAt): void
    {
        ZReport::create([
            'branch_id' => $branchId, 'sequence_no' => 1,
            'opened_at' => $closedAt->copy()->subHours(12), 'closed_at' => $closedAt,
            'status' => ZReport::STATUS_CLOSED,
        ]);
    }

    public function test_dry_run_is_default_and_writes_nothing(): void
    {
        $branch = Branch::factory()->create();
        $this->closedZ($branch->id, Carbon::parse('2026-04-22 23:00:00'));
        $order = $this->legacyPaidNoSeq($branch->id, Carbon::parse('2026-04-22 12:00:00'));

        $this->artisan('fiscal:backfill-legacy-alloc')->assertSuccessful();

        $this->assertNull($order->fresh()->fiscal_sequence_no, 'dry-run (default) must write NOTHING');
    }

    public function test_apply_without_confirm_token_is_refused(): void
    {
        $branch = Branch::factory()->create();
        $this->closedZ($branch->id, Carbon::parse('2026-04-22 23:00:00'));
        $order = $this->legacyPaidNoSeq($branch->id, Carbon::parse('2026-04-22 12:00:00'));

        $exit = $this->artisan('fiscal:backfill-legacy-alloc', ['--apply' => true])->run();

        $this->assertNotSame(0, $exit, '--apply without the confirm token must fail');
        $this->assertNull($order->fresh()->fiscal_sequence_no);
    }

    public function test_apply_with_confirm_allocates_and_stamps(): void
    {
        $branch = Branch::factory()->create();
        $this->closedZ($branch->id, Carbon::parse('2026-04-22 23:00:00'));
        $order = $this->legacyPaidNoSeq($branch->id, Carbon::parse('2026-04-22 12:00:00'));

        $this->artisan('fiscal:backfill-legacy-alloc', ['--apply' => true, '--confirm' => 'BACKFILL-FISCAL'])
            ->assertSuccessful();

        $fresh = $order->fresh();
        $this->assertNotNull($fresh->fiscal_sequence_no, 'legacy row must receive a gap-free seq');
        $this->assertNotNull($fresh->fiscal_seq_allocated_at, 'allocation stamp set so NF-1 late-salvage sweeps it');
    }

    public function test_excludes_terminal_and_in_open_window_rows(): void
    {
        $branch = Branch::factory()->create();
        $this->closedZ($branch->id, Carbon::parse('2026-04-22 23:00:00'));

        // Terminal (RETURNED) → excluded.
        $returned = $this->legacyPaidNoSeq($branch->id, Carbon::parse('2026-04-22 12:00:00'), 10.0, OrderStatus::RETURNED);
        // Created AFTER the last closed Z (in-progress window) → excluded.
        $afterZ = $this->legacyPaidNoSeq($branch->id, Carbon::parse('2026-04-23 09:00:00'));

        $this->artisan('fiscal:backfill-legacy-alloc', ['--apply' => true, '--confirm' => 'BACKFILL-FISCAL'])
            ->assertSuccessful();

        $this->assertNull($returned->fresh()->fiscal_sequence_no, 'terminal RETURNED row must NOT be allocated');
        $this->assertNull($afterZ->fresh()->fiscal_sequence_no, 'a row in the in-progress (open) window must NOT be allocated');
    }
}
