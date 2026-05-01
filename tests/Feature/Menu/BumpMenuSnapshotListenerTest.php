<?php

namespace Tests\Feature\Menu;

use App\Events\CategoryUpdated;
use App\Events\ItemAvailabilityChanged;
use App\Events\ItemCreated;
use App\Models\Branch;
use App\Models\Item;
use App\Services\Menu\MenuSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Verifies that {@see \App\Listeners\BumpMenuSnapshotOnItemAvailabilityChanged}
 * lifts the branch-scoped snapshot version whenever an availability flip is
 * observed. Global events must bump every active branch.
 */
class BumpMenuSnapshotListenerTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_scoped_event_bumps_only_that_branch(): void
    {
        $snap = app(MenuSnapshot::class);

        $b1 = Branch::factory()->create();
        $b2 = Branch::factory()->create();

        $v1Before = $snap->current($b1->id);
        $v2Before = $snap->current($b2->id);

        event(ItemAvailabilityChanged::forBranch(
            itemId: 1,
            branchId: (int) $b1->id,
            isAvailable: false,
            reason: 'out_of_stock'
        ));

        $this->assertGreaterThan($v1Before, $snap->current($b1->id), 'Target branch must bump');
        $this->assertSame($v2Before, $snap->current($b2->id), 'Other branch must NOT bump');
    }

    public function test_global_event_bumps_every_active_branch(): void
    {
        $snap = app(MenuSnapshot::class);

        $b1 = Branch::factory()->create();
        $b2 = Branch::factory()->create();

        $v1Before = $snap->current($b1->id);
        $v2Before = $snap->current($b2->id);

        $item = Item::factory()->create();
        event(ItemAvailabilityChanged::fromItem($item));

        $this->assertGreaterThan($v1Before, $snap->current($b1->id));
        $this->assertGreaterThan($v2Before, $snap->current($b2->id));
    }

    public function test_item_created_catalog_event_bumps_snapshot_and_invalidates_kiosk_cache(): void
    {
        $snap = app(MenuSnapshot::class);
        $branch = Branch::factory()->create();
        $before = $snap->current($branch->id);
        Cache::put("kiosk.menu.branch.{$branch->id}", ['stale' => true], 120);

        event(new ItemCreated(123));

        $this->assertGreaterThan($before, $snap->current($branch->id));
        $this->assertFalse(Cache::has("kiosk.menu.branch.{$branch->id}"));
    }

    public function test_category_updated_catalog_event_bumps_snapshot_and_invalidates_kiosk_cache(): void
    {
        $snap = app(MenuSnapshot::class);
        $branch = Branch::factory()->create();
        $before = $snap->current($branch->id);
        Cache::put("kiosk.menu.branch.{$branch->id}", ['stale' => true], 120);

        event(new CategoryUpdated(456));

        $this->assertGreaterThan($before, $snap->current($branch->id));
        $this->assertFalse(Cache::has("kiosk.menu.branch.{$branch->id}"));
    }
}
