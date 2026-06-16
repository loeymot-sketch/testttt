<?php

/**
 * [GENIE Wave 0b 2026-06-16] fiscal:backfill-orphans allocates a fiscal sequence to historical PAID orders
 * with fiscal_sequence_no=NULL (off-book pre-heal). Dry-run is non-destructive; --apply is idempotent and
 * writes an NF525 régularisation AuditLog row.
 */

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillFiscalOrphansTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->branch = Branch::factory()->create();
    }

    private function orphan(): Order
    {
        $u = User::factory()->create(['branch_id' => $this->branch->id]);
        return Order::factory()->create([
            'user_id' => $u->id, 'branch_id' => $this->branch->id, 'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::PAID, 'status' => OrderStatus::DELIVERED,
            'total' => 12.0, 'fiscal_sequence_no' => null,
        ]);
    }

    /** @test */
    public function test_dry_run_does_not_allocate(): void
    {
        $o = $this->orphan();
        $this->artisan('fiscal:backfill-orphans')->assertExitCode(0);
        $this->assertNull($o->fresh()->fiscal_sequence_no, 'dry-run must NOT allocate');
    }

    /** @test */
    public function test_apply_allocates_sequences_and_writes_audit(): void
    {
        $a = $this->orphan();
        $b = $this->orphan();

        $this->artisan('fiscal:backfill-orphans', ['--apply' => true])->assertExitCode(0);

        $this->assertNotNull($a->fresh()->fiscal_sequence_no);
        $this->assertNotNull($b->fresh()->fiscal_sequence_no);
        $this->assertGreaterThan((int) $a->fresh()->fiscal_sequence_no, (int) $b->fresh()->fiscal_sequence_no);
        $this->assertSame(2, AuditLog::where('action', 'fiscal.orphan_backfilled')->count());
    }

    /** @test */
    public function test_apply_is_idempotent(): void
    {
        $o = $this->orphan();
        $this->artisan('fiscal:backfill-orphans', ['--apply' => true])->assertExitCode(0);
        $seq = $o->fresh()->fiscal_sequence_no;

        // second run: the order is no longer an orphan → not re-allocated
        $this->artisan('fiscal:backfill-orphans', ['--apply' => true])->assertExitCode(0);
        $this->assertSame((int) $seq, (int) $o->fresh()->fiscal_sequence_no, 'must not re-allocate');
    }
}
