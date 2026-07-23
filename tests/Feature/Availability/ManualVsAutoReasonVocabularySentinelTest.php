<?php

namespace Tests\Feature\Availability;

use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Services\Menu\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [panel-manual-86-reason-collision 2026-07-22 / Vague 2 STOCK-HARDENING]
 *
 * Sentinel locking the item_branch_availability reason vocabulary INSIDE
 * AvailabilityService (see the REASON VOCABULARY block on the class docblock):
 *
 *   - 'out_of_stock'  = AUTO daily-quota 86. The ONLY reason the quota machinery
 *                       (setMaxDailyQty raise / releaseForOrderItems / the reset cron
 *                       + reconcileStaleDailyQuota) will silently flip back to
 *                       available.
 *   - 'stock_rupture' / any manual slug = MANUAL/physical 86. STICKY — never
 *                       auto-cleared by this service's quota self-heal.
 *
 * Investigation verdict for the finding: within AvailabilityService there is NO
 * manual↔auto collision (distinct slugs, and every auto-restore guard keys on
 * `=== 'out_of_stock'`), so the manual panel writing 'stock_rupture' is safe here.
 *
 * The residual StockService restock-reactivation collision (a replenishment un-doing
 * a manual 86 that shared the 'stock_rupture' slug) is now RESOLVED — owner decision
 * « A », 2026-07-23 — WITHOUT renaming the slug: {@see AvailabilityService::toggle()}
 * stamps a distinct PROVENANCE marker (item_branch_availability.manual_unavailable_since,
 * $manual=true by default) that StockService checks before auto-reactivating. The
 * end-to-end stickiness-through-restock behaviour lives in
 * {@see \Tests\Feature\Stock\ManualEightySixStickyThroughRestockTest}; the
 * provenance-stamp contract itself is locked below.
 */
class ManualVsAutoReasonVocabularySentinelTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_quota_86_writes_out_of_stock_reason(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();

        // Available today, 5 already consumed, uncapped.
        ItemBranchAvailability::query()->create([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => true,
            'unavailable_reason' => null,
            'max_daily_qty' => null,
            'daily_consumed_qty' => 5,
            'daily_reset_at' => now()->toDateString(),
        ]);

        // Lower the cap below consumption → auto-86.
        app(AvailabilityService::class)->setMaxDailyQty($item->id, $branch->id, 2);

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => 0,
            'unavailable_reason' => 'out_of_stock',
        ]);
    }

    public function test_manual_toggle_writes_its_own_reason_distinct_from_auto(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();

        app(AvailabilityService::class)->toggle($item->id, $branch->id, false, 'stock_rupture');

        $row = ItemBranchAvailability::query()
            ->where('item_id', $item->id)->where('branch_id', $branch->id)->first();

        $this->assertFalse((bool) $row->is_available);
        $this->assertSame('stock_rupture', $row->unavailable_reason);
        $this->assertNotSame('out_of_stock', $row->unavailable_reason, 'Manual reason must NOT collide with the auto-quota slug.');
    }

    /**
     * [panel-manual-86-reason-collision — owner decision « A », 2026-07-23]
     *
     * Provenance-stamp contract that disambiguates the SHARED 'stock_rupture' slug
     * between a human 86 (sticky through restock) and an auto stock 86 (reactivable):
     *  - a manual toggle ($manual defaults true) stamps manual_unavailable_since;
     *  - the auto path ($manual=false, used by cron stock:scan-rupture) leaves it null;
     *  - re-enabling clears the marker.
     * StockService keys its restock reactivation on this marker being null.
     */
    public function test_manual_toggle_stamps_provenance_marker_and_auto_leaves_it_null(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();
        $service = app(AvailabilityService::class);

        // Manual 86 (default) — provenance stamped.
        $service->toggle($item->id, $branch->id, false, 'stock_rupture');
        $manual = ItemBranchAvailability::query()
            ->where('item_id', $item->id)->where('branch_id', $branch->id)->first();
        $this->assertSame('stock_rupture', $manual->unavailable_reason);
        $this->assertNotNull($manual->manual_unavailable_since, 'Manual 86 must stamp provenance (sticky through restock).');

        // Re-enable clears the marker.
        $service->toggle($item->id, $branch->id, true, null);
        $reenabled = ItemBranchAvailability::query()
            ->where('item_id', $item->id)->where('branch_id', $branch->id)->first();
        $this->assertTrue((bool) $reenabled->is_available);
        $this->assertNull($reenabled->manual_unavailable_since, 'Re-enable must clear the provenance marker.');

        // Auto path ($manual=false) — same slug, NO provenance → stays reactivable.
        $service->toggle($item->id, $branch->id, false, 'stock_rupture', manual: false);
        $auto = ItemBranchAvailability::query()
            ->where('item_id', $item->id)->where('branch_id', $branch->id)->first();
        $this->assertSame('stock_rupture', $auto->unavailable_reason);
        $this->assertNull($auto->manual_unavailable_since, 'Auto stock 86 must NOT be marked manual.');
    }

    public function test_setMaxDailyQty_raise_does_not_reenable_a_manual_86(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();

        // Manually 86'd (stock_rupture) while ALSO holding a quota cap.
        ItemBranchAvailability::query()->create([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'stock_rupture',
            'unavailable_since' => now(),
            'max_daily_qty' => 2,
            'daily_consumed_qty' => 0,
            'daily_reset_at' => now()->toDateString(),
        ]);

        // Raising the cap would auto-restore an 'out_of_stock' row — but this one is
        // manual, so it must stay 86'd.
        app(AvailabilityService::class)->setMaxDailyQty($item->id, $branch->id, 99);

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => 0,
            'unavailable_reason' => 'stock_rupture',
        ]);
    }

    public function test_daily_reconcile_preserves_manual_stock_rupture(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();

        ItemBranchAvailability::query()->create([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'stock_rupture',
            'unavailable_since' => now()->subDay(),
            'max_daily_qty' => 5,
            'daily_consumed_qty' => 5,
            'daily_reset_at' => now()->subDay()->toDateString(),
        ]);

        $reenabled = app(AvailabilityService::class)->reconcileStaleDailyQuota($branch->id);

        $this->assertSame(0, $reenabled, 'Manual stock_rupture must NOT be re-enabled by the quota reconcile.');
        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => 0,
            'unavailable_reason' => 'stock_rupture',
            'daily_consumed_qty' => 0, // counter still reset for day rollover
        ]);
    }
}
