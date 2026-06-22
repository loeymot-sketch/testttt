<?php

namespace Tests\Feature\Menu;

use App\Events\ItemAvailabilityChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Services\Menu\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Sentinel — Mission #2 Vague 2 action 2.5.
 *
 * Asserts that lowering max_daily_qty below the current daily_consumed_qty
 * triggers an immediate auto-86 evaluation, instead of waiting for the
 * next order to consume.
 *
 * Audit: reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md §C #8
 * Plan : plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md task 2.5
 */
class MaxDailyQtyChangeReEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ItemAvailabilityChanged::class]);
    }

    public function test_lowering_max_daily_below_consumed_triggers_auto_86(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create(['channels' => ['pos']]);
        $row = ItemBranchAvailability::query()->create([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => true,
            'max_daily_qty' => 50,
            'daily_consumed_qty' => 40,
            'daily_reset_at' => now()->toDateString(),
        ]);

        app(AvailabilityService::class)->setMaxDailyQty($item->id, $branch->id, 30);

        $row->refresh();
        $this->assertFalse((bool) $row->is_available);
        $this->assertSame('out_of_stock', $row->unavailable_reason);
        $this->assertSame(30, (int) $row->max_daily_qty);

        Event::assertDispatched(ItemAvailabilityChanged::class, function (ItemAvailabilityChanged $e) use ($branch, $item): bool {
            return $e->itemId === $item->id
                && $e->branchId === $branch->id
                && $e->isAvailable === false
                && $e->reason === 'out_of_stock';
        });
    }

    public function test_raising_max_daily_restores_availability(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create(['channels' => ['pos']]);
        $row = ItemBranchAvailability::query()->create([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'out_of_stock',
            'unavailable_since' => now(),
            'max_daily_qty' => 30,
            'daily_consumed_qty' => 40,
            'daily_reset_at' => now()->toDateString(),
        ]);

        app(AvailabilityService::class)->setMaxDailyQty($item->id, $branch->id, 60);

        $row->refresh();
        $this->assertTrue((bool) $row->is_available);
        $this->assertNull($row->unavailable_reason);

        Event::assertDispatched(ItemAvailabilityChanged::class, fn (ItemAvailabilityChanged $e): bool =>
            $e->itemId === $item->id && $e->isAvailable === true);
    }

    public function test_setting_max_daily_null_unlimited_restores_availability(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create(['channels' => ['pos']]);
        ItemBranchAvailability::query()->create([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'out_of_stock',
            'max_daily_qty' => 10,
            'daily_consumed_qty' => 15,
            'daily_reset_at' => now()->toDateString(),
        ]);

        $row = app(AvailabilityService::class)->setMaxDailyQty($item->id, $branch->id, null);

        $this->assertTrue((bool) $row->is_available);
        $this->assertNull($row->max_daily_qty);
        Event::assertDispatched(ItemAvailabilityChanged::class);
    }

    public function test_idempotent_no_duplicate_event(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create(['channels' => ['pos']]);
        ItemBranchAvailability::query()->create([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => true,
            'max_daily_qty' => 50,
            'daily_consumed_qty' => 0,
            'daily_reset_at' => now()->toDateString(),
        ]);

        $svc = app(AvailabilityService::class);
        $svc->setMaxDailyQty($item->id, $branch->id, 50);
        $svc->setMaxDailyQty($item->id, $branch->id, 50);

        Event::assertNotDispatched(ItemAvailabilityChanged::class);
    }
}
