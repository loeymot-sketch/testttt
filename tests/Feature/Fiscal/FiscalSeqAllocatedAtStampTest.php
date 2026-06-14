<?php

namespace Tests\Feature\Fiscal;

use App\Models\FrontendOrder;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NF-1-prereq (GOAL 2.0 Phase 2, FISC-EXH-01 — 2026-06-15).
 *
 * `fiscal_seq_allocated_at` must be stamped the moment a fiscal_sequence_no is first
 * assigned, on EVERY model-based allocation path (the Order/FrontendOrder saving hook
 * is the single source of truth). The frozen ZReportService late-salvage window keys on
 * this column, so an unstamped allocation would silently escape the salvage.
 */
class FiscalSeqAllocatedAtStampTest extends TestCase
{
    use RefreshDatabase;

    public function test_allocating_sequence_stamps_allocated_at_on_order(): void
    {
        $order = Order::factory()->create(['fiscal_sequence_no' => null]);
        $this->assertNull($order->fresh()->fiscal_seq_allocated_at, 'precondition: no seq, no stamp');

        $order->fiscal_sequence_no = 7001; // direct property set (the kiosk/COD pattern)
        $order->save();

        $this->assertNotNull(
            $order->fresh()->fiscal_seq_allocated_at,
            'fiscal_seq_allocated_at MUST be stamped when fiscal_sequence_no is first set.'
        );
    }

    public function test_allocating_sequence_stamps_allocated_at_on_frontend_order(): void
    {
        // FrontendOrder has no factory; it shares the `orders` table, so create the row
        // via Order then load+mutate it through the FrontendOrder model to exercise ITS hook.
        $base = Order::factory()->create(['fiscal_sequence_no' => null]);
        $order = FrontendOrder::withoutGlobalScopes()->findOrFail($base->id);
        $order->fiscal_sequence_no = 7002;
        $order->save();

        $this->assertNotNull(
            $order->fresh()->fiscal_seq_allocated_at,
            'Kiosk (FrontendOrder) allocation must stamp fiscal_seq_allocated_at too.'
        );
    }

    public function test_existing_stamp_is_never_overwritten(): void
    {
        $order = Order::factory()->create(['fiscal_sequence_no' => 7003]);
        $stamp = $order->fresh()->fiscal_seq_allocated_at;
        $this->assertNotNull($stamp, 'created-with-seq row should be stamped');

        // A later unrelated save must NOT move the allocation timestamp.
        $order->total = 99.99;
        $order->save();

        $this->assertEquals(
            $stamp->toDateTimeString(),
            $order->fresh()->fiscal_seq_allocated_at->toDateTimeString(),
            'The allocation timestamp is immutable once set — a later save must not re-stamp it.'
        );
    }

    public function test_row_without_sequence_is_never_stamped(): void
    {
        $order = Order::factory()->create(['fiscal_sequence_no' => null]);
        $order->total = 12.34;
        $order->save();

        $this->assertNull(
            $order->fresh()->fiscal_seq_allocated_at,
            'An order with no fiscal_sequence_no must never carry an allocation stamp.'
        );
    }
}
