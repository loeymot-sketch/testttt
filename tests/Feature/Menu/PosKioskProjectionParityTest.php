<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Services\Menu\MenuProjectionService;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Sentinel — Mission #1 Vague 2 action 2.5.
 *
 * For a given branch, asserts that the set of items + categories visible
 * on POS and on Kiosk is reconciled — same item ids appear on both
 * surfaces unless explicitly restricted by `channels`.
 *
 * Audit: reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_1_CATALOG_SYNC_2026-05-02.md §A.1 #2
 * Plan : plans/PLAN_CV1-CATALOG-CONVERGENCE-001_2026-05-02.md tasks 2.4 → 2.5
 *
 * Contract once unskipped:
 *
 *   1. For an item with channels=NULL (back-compat) on a branch where it
 *      is_available=true, both POS and Kiosk projections list it.
 *
 *   2. For an item with channels=['kiosk'] only, POS does NOT list it but
 *      Kiosk does.
 *
 *   3. For an item with channels=['pos'] only, Kiosk does NOT list it but
 *      POS does.
 *
 *   4. For an item available globally but with item_branch_availability(branch=X)
 *      is_available=false, neither POS nor Kiosk list it as orderable
 *      (POS may still list with disabled state — to confirm in plan).
 *
 *   5. The category set on POS is a (possibly improper) subset of the
 *      category set on Kiosk minus the kiosk-only categories.
 */
class PosKioskProjectionParityTest extends TestCase
{
    use RefreshDatabase;

    private int $branchId;
    private Tax $tax;
    private ItemCategory $categoryShared;
    private ItemCategory $categoryKioskOnly;
    private ItemCategory $categoryPosOnly;
    private Item $itemShared;
    private Item $itemKioskOnly;
    private Item $itemPosOnly;
    private Item $itemUnavailable;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);
        $this->branchId = (int) $branch->id;
        $this->tax = Tax::factory()->create(['status' => Status::ACTIVE]);

        $this->categoryShared = $this->makeCategory('Parity Shared', null);
        $this->categoryKioskOnly = $this->makeCategory('Parity Kiosk', ['kiosk']);
        $this->categoryPosOnly = $this->makeCategory('Parity POS', ['pos']);

        $this->itemShared = $this->makeItem('Parity Shared Item', $this->categoryShared, null);
        $this->itemKioskOnly = $this->makeItem('Parity Kiosk Item', $this->categoryKioskOnly, ['kiosk']);
        $this->itemPosOnly = $this->makeItem('Parity POS Item', $this->categoryPosOnly, ['pos']);
        $this->itemUnavailable = $this->makeItem('Parity Unavailable Item', $this->categoryShared, null);

        ItemBranchAvailability::create([
            'item_id' => $this->itemUnavailable->id,
            'branch_id' => $this->branchId,
            'is_available' => false,
            'unavailable_reason' => 'parity_sentinel',
            'daily_consumed_qty' => 0,
            'daily_reset_at' => now()->toDateString(),
        ]);
    }

    public function test_item_visible_on_both_when_channels_null(): void
    {
        $projection = app(MenuProjectionService::class);

        $posItems = $this->flattenItems($projection->forChannel('pos', $this->branchId));
        $kioskItems = $this->flattenItems($projection->forChannel('kiosk', $this->branchId));

        $this->assertContains($this->itemShared->id, $posItems->keys()->all());
        $this->assertContains($this->itemShared->id, $kioskItems->keys()->all());
        $this->assertTrue((bool) $posItems[$this->itemShared->id]['is_available']);
        $this->assertTrue((bool) $kioskItems[$this->itemShared->id]['is_available']);
    }

    public function test_kiosk_only_item_not_on_pos(): void
    {
        $projection = app(MenuProjectionService::class);

        $posItems = $this->flattenItems($projection->forChannel('pos', $this->branchId));
        $kioskItems = $this->flattenItems($projection->forChannel('kiosk', $this->branchId));

        $this->assertNotContains($this->itemKioskOnly->id, $posItems->keys()->all());
        $this->assertContains($this->itemKioskOnly->id, $kioskItems->keys()->all());
    }

    public function test_pos_only_item_not_on_kiosk(): void
    {
        $projection = app(MenuProjectionService::class);

        $posItems = $this->flattenItems($projection->forChannel('pos', $this->branchId));
        $kioskItems = $this->flattenItems($projection->forChannel('kiosk', $this->branchId));

        $this->assertContains($this->itemPosOnly->id, $posItems->keys()->all());
        $this->assertNotContains($this->itemPosOnly->id, $kioskItems->keys()->all());
    }

    public function test_branch_unavailable_item_disabled_on_both(): void
    {
        $projection = app(MenuProjectionService::class);

        $posItems = $this->flattenItems($projection->forChannel('pos', $this->branchId));
        $kioskItems = $this->flattenItems($projection->forChannel('kiosk', $this->branchId));

        $this->assertContains($this->itemUnavailable->id, $posItems->keys()->all());
        $this->assertContains($this->itemUnavailable->id, $kioskItems->keys()->all());
        $this->assertFalse((bool) $posItems[$this->itemUnavailable->id]['is_available']);
        $this->assertFalse((bool) $kioskItems[$this->itemUnavailable->id]['is_available']);
    }

    public function test_pos_categories_subset_of_kiosk(): void
    {
        $projection = app(MenuProjectionService::class);

        $posCategoryIds = collect($projection->forChannel('pos', $this->branchId)['categories'] ?? [])
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $kioskCategoryIds = collect($projection->forChannel('kiosk', $this->branchId)['categories'] ?? [])
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($posCategoryIds as $categoryId) {
            if ($categoryId === $this->categoryPosOnly->id) {
                continue;
            }
            $this->assertContains($categoryId, $kioskCategoryIds);
        }

        $this->assertContains($this->categoryKioskOnly->id, $kioskCategoryIds);
        $this->assertNotContains($this->categoryKioskOnly->id, $posCategoryIds);
    }

    private function makeCategory(string $name, ?array $channels): ItemCategory
    {
        return ItemCategory::factory()->create([
            'name' => $name,
            'status' => Status::ACTIVE,
            'channels' => $channels,
        ]);
    }

    private function makeItem(string $name, ItemCategory $category, ?array $channels): Item
    {
        return Item::factory()->create([
            'name' => $name,
            'item_category_id' => $category->id,
            'tax_id' => $this->tax->id,
            'status' => Status::ACTIVE,
            'channels' => $channels,
            'is_available' => true,
        ]);
    }

    private function flattenItems(array $projection): \Illuminate\Support\Collection
    {
        return collect($projection['categories'] ?? [])
            ->flatMap(static fn (array $category) => $category['items'] ?? [])
            ->keyBy('id');
    }
}
