<?php

namespace Tests\Feature\Stock;

use App\Enums\Status;
use App\Events\ItemAvailabilityChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemBranchAvailability;
use App\Models\ItemVariation;
use App\Models\StockLevel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Sentinel — Mission #2 Vague 2 action 2.1.
 *
 * Asserts the preventive auto-86 cron command behaves correctly:
 * disabled-by-default, branch-scoped optional, dry-run safe, idempotent.
 *
 * Audit: reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md §A.1 #7
 * Plan : plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md task 2.1
 *
 * Contract once unskipped:
 *
 *   1. With config('catalog_v15.auto_86_preventive_cron.enabled') = false:
 *      - Artisan::call('stock:scan-rupture') returns 0
 *      - no item_branch_availability mutation
 *      - log entry 'Skipped: ... enabled=false'
 *
 *   2. With enabled=true, multi-branch scan:
 *      - 2 branches, branch A has item X with all variations on_hand=0,
 *        branch B has item X with one variation on_hand>0
 *      - command flips item X on branch A only
 *      - emits ItemAvailabilityChanged::forBranch(X, A, false, 'stock_rupture')
 *      - branch B is untouched
 *
 *   3. --dry-run:
 *      - no DB mutation, no event dispatched
 *      - log lines [stock.scan-rupture-would-flip] for each candidate
 *
 *   4. Idempotent second run:
 *      - run command twice in a row
 *      - second run flips zero items (already unavailable)
 *      - no duplicate ItemAvailabilityChanged event emitted
 *
 *   5. --branch=ID restricts to single branch.
 *
 *   6. An item that has at least one orderable selection (variation /
 *      extra / addon target) MUST NOT be flipped — confirms via
 *      ChoiceAvailabilityResolver delegation.
 */
class StockScanRuptureCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Event::fake([ItemAvailabilityChanged::class]);
    }

    public function test_skipped_when_flag_disabled(): void
    {
        config(['catalog_v15.auto_86_preventive_cron.enabled' => false]);
        $branch = Branch::factory()->create();
        $item = Item::factory()->create(['status' => Status::ACTIVE]);

        $exit = Artisan::call('stock:scan-rupture');

        $this->assertSame(0, $exit);
        $this->assertDatabaseMissing('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
        ]);
        Event::assertNotDispatched(ItemAvailabilityChanged::class);
    }

    public function test_multi_branch_scan_flips_only_affected(): void
    {
        config(['catalog_v15.auto_86_preventive_cron.enabled' => true]);
        [$branchA, $branchB] = [Branch::factory()->create(), Branch::factory()->create()];
        [$item, $first, $second] = $this->itemWithTwoVariations();

        $this->stock($branchA, ItemVariation::class, $first->id, 0);
        $this->stock($branchA, ItemVariation::class, $second->id, 0);
        $this->stock($branchB, ItemVariation::class, $first->id, 0);
        $this->stock($branchB, ItemVariation::class, $second->id, 5);

        $exit = Artisan::call('stock:scan-rupture');

        $this->assertSame(0, $exit);
        $this->assertUnavailable($item, $branchA);
        $this->assertDatabaseMissing('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branchB->id,
        ]);
        Event::assertDispatchedTimes(ItemAvailabilityChanged::class, 1);
        Event::assertDispatched(ItemAvailabilityChanged::class, fn (ItemAvailabilityChanged $event): bool =>
            $event->itemId === (int) $item->id
            && $event->branchId === (int) $branchA->id
            && $event->isAvailable === false
            && $event->reason === 'stock_rupture'
        );
    }

    public function test_dry_run_does_not_mutate(): void
    {
        config(['catalog_v15.auto_86_preventive_cron.enabled' => true]);
        $branch = Branch::factory()->create();
        [$item, $first, $second] = $this->itemWithTwoVariations();
        $this->stock($branch, ItemVariation::class, $first->id, 0);
        $this->stock($branch, ItemVariation::class, $second->id, 0);

        $exit = Artisan::call('stock:scan-rupture', ['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertDatabaseMissing('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
        ]);
        $summary = Cache::get("stock_scan_rupture:last_summary:branch:{$branch->id}");
        $this->assertSame(1, $summary['items_would_flip']);
        $this->assertSame(0, $summary['items_flipped']);
        Event::assertNotDispatched(ItemAvailabilityChanged::class);
    }

    public function test_idempotent_second_run(): void
    {
        config(['catalog_v15.auto_86_preventive_cron.enabled' => true]);
        $branch = Branch::factory()->create();
        [$item, $first, $second] = $this->itemWithTwoVariations();
        $this->stock($branch, ItemVariation::class, $first->id, 0);
        $this->stock($branch, ItemVariation::class, $second->id, 0);

        $this->assertSame(0, Artisan::call('stock:scan-rupture'));
        $this->assertSame(0, Artisan::call('stock:scan-rupture'));

        $this->assertUnavailable($item, $branch);
        $summary = Cache::get("stock_scan_rupture:last_summary:branch:{$branch->id}");
        $this->assertSame(0, $summary['items_flipped']);
        $this->assertSame(1, $summary['items_already_unavailable']);
        Event::assertDispatchedTimes(ItemAvailabilityChanged::class, 1);
    }

    public function test_branch_option_restricts_scope(): void
    {
        config(['catalog_v15.auto_86_preventive_cron.enabled' => true]);
        [$branchA, $branchB] = [Branch::factory()->create(), Branch::factory()->create()];
        [$item, $first, $second] = $this->itemWithTwoVariations();
        foreach ([$branchA, $branchB] as $branch) {
            $this->stock($branch, ItemVariation::class, $first->id, 0);
            $this->stock($branch, ItemVariation::class, $second->id, 0);
        }

        $exit = Artisan::call('stock:scan-rupture', ['--branch' => $branchB->id]);

        $this->assertSame(0, $exit);
        $this->assertDatabaseMissing('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branchA->id,
        ]);
        $this->assertUnavailable($item, $branchB);
        Event::assertDispatchedTimes(ItemAvailabilityChanged::class, 1);
    }

    public function test_item_with_at_least_one_orderable_choice_not_flipped(): void
    {
        config(['catalog_v15.auto_86_preventive_cron.enabled' => true]);
        $branch = Branch::factory()->create();
        [$item, $first, $second] = $this->itemWithTwoVariations();
        $this->stock($branch, ItemVariation::class, $first->id, 0);
        $this->stock($branch, ItemVariation::class, $second->id, 3);

        $exit = Artisan::call('stock:scan-rupture');

        $this->assertSame(0, $exit);
        $this->assertDatabaseMissing('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
        ]);
        $summary = Cache::get("stock_scan_rupture:last_summary:branch:{$branch->id}");
        $this->assertSame(1, $summary['items_skipped_partial_stock']);
        Event::assertNotDispatched(ItemAvailabilityChanged::class);
    }

    /**
     * @return array{0: Item, 1: ItemVariation, 2: ItemVariation}
     */
    private function itemWithTwoVariations(): array
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $attribute = ItemAttribute::factory()->create(['status' => Status::ACTIVE]);

        $first = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Choice A',
            'price' => 0,
            'status' => Status::ACTIVE,
        ]);
        $second = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Choice B',
            'price' => 0,
            'status' => Status::ACTIVE,
        ]);

        return [$item, $first, $second];
    }

    private function stock(Branch $branch, string $type, int $id, int $onHand): StockLevel
    {
        return StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => $type,
            'stockable_id' => $id,
            'on_hand' => $onHand,
            'reserved' => 0,
            'threshold_low' => 2,
        ]);
    }

    private function assertUnavailable(Item $item, Branch $branch): void
    {
        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => 0,
            'unavailable_reason' => 'stock_rupture',
        ]);

        $row = ItemBranchAvailability::query()
            ->where('item_id', $item->id)
            ->where('branch_id', $branch->id)
            ->first();
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->is_available);
    }
}
