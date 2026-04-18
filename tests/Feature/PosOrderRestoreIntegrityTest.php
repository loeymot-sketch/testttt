<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [POS-9-H.3.5 / F-A7]
 *
 * OrderService::destroy() performs a soft-delete on the Order row and
 * cascading HARD deletes on OrderAddress + OrderCoupon. Calling
 * $order->restore() afterwards would revive the Order but leave it
 * structurally incomplete — the address and coupon rows are gone.
 *
 * The model's `restoring` event now throws a RuntimeException so that
 * any accidental $order->restore() fails fast and loudly, rather than
 * producing a silently-broken aggregate that still contributes to Z/X
 * reports.
 */
class PosOrderRestoreIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_restoring_a_soft_deleted_order_throws(): void
    {
        $branch = Branch::factory()->create();
        $order  = Order::factory()->create(['branch_id' => $branch->id]);

        // Bypass the BranchScope for the test actor.
        $order->delete();
        $this->assertTrue($order->trashed(), 'precondition: order must be soft-deleted.');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Order::restore\(\) is disabled/');

        $order->restore();
    }

    public function test_force_deleting_a_trashed_order_still_works(): void
    {
        // We block restore(), not forceDelete() — an admin-only cleanup
        // (e.g. GDPR erasure after the 6-year NF525 window) must still
        // be able to permanently remove the row.
        $branch = Branch::factory()->create();
        $order  = Order::factory()->create(['branch_id' => $branch->id]);
        $id     = $order->id;

        $order->delete();
        $order->forceDelete();

        $this->assertDatabaseMissing('orders', ['id' => $id]);
    }

    public function test_save_on_a_live_order_is_not_blocked_by_restore_guard(): void
    {
        // The `restoring` event only fires on restore() — ordinary
        // mutations on a non-trashed order must keep working.
        $branch = Branch::factory()->create();
        $order  = Order::factory()->create(['branch_id' => $branch->id]);

        $order->status = $order->status;
        $order->save();

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }
}
