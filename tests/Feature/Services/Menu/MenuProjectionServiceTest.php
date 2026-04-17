<?php

namespace Tests\Feature\Services\Menu;

use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Services\Menu\MenuProjectionService;
use App\Services\Menu\MenuSnapshot;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contract tests for the dual-channel menu projection — V1 section 5.
 *
 * @see app/Services/Menu/MenuProjectionService.php
 * @see docs/MENU_PROJECTIONS.md
 */
class MenuProjectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Tax $tax;
    private MenuProjectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->tax = Tax::factory()->create([
            'name' => 'TVA 10%',
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 10.0,
            'status' => Status::ACTIVE,
        ]);

        $this->service = new MenuProjectionService(
            new MenuSnapshot(new Repository(new ArrayStore()))
        );
    }

    public function test_unsupported_channel_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported menu channel 'mobile-app'");
        $this->service->forChannel('mobile-app', $this->branch->id);
    }

    public function test_envelope_shape_is_contract_stable(): void
    {
        $out = $this->service->forChannel('pos', $this->branch->id);
        $this->assertArrayHasKey('categories', $out);
        $this->assertArrayHasKey('snapshot_version', $out);
        $this->assertArrayHasKey('branch_id', $out);
        $this->assertArrayHasKey('channel', $out);
        $this->assertSame($this->branch->id, $out['branch_id']);
        $this->assertSame('pos', $out['channel']);
        $this->assertIsInt($out['snapshot_version']);
    }

    public function test_null_channels_means_item_visible_everywhere(): void
    {
        $category = $this->makeCategory(channels: null);
        $this->makeItem($category, 'Tacos', 6.5, channels: null);

        $posOut = $this->service->forChannel('pos', $this->branch->id);
        $kioskOut = $this->service->forChannel('kiosk', $this->branch->id);
        $webOut = $this->service->forChannel('web', $this->branch->id);

        $this->assertCount(1, $posOut['categories']);
        $this->assertCount(1, $kioskOut['categories']);
        $this->assertCount(1, $webOut['categories']);
        $this->assertSame('Tacos', $posOut['categories'][0]['items'][0]['name']);
    }

    public function test_kiosk_only_item_is_hidden_on_pos(): void
    {
        $category = $this->makeCategory(channels: ['pos', 'kiosk']);
        $this->makeItem($category, 'Signature Kiosk', 8.0, channels: ['kiosk']);
        $this->makeItem($category, 'Sold Everywhere', 5.0, channels: ['pos', 'kiosk']);

        $pos = $this->service->forChannel('pos', $this->branch->id);
        $kiosk = $this->service->forChannel('kiosk', $this->branch->id);

        $posNames = collect($pos['categories'][0]['items'])->pluck('name')->all();
        $kioskNames = collect($kiosk['categories'][0]['items'])->pluck('name')->all();

        $this->assertSame(['Sold Everywhere'], $posNames);
        $this->assertEqualsCanonicalizing(['Signature Kiosk', 'Sold Everywhere'], $kioskNames);
    }

    public function test_pos_only_category_is_hidden_on_kiosk(): void
    {
        $posOnly = $this->makeCategory(name: 'Backend POS', channels: ['pos']);
        $this->makeItem($posOnly, 'Cashier-only SKU', 2.0, channels: null);

        $shared = $this->makeCategory(name: 'Shared', channels: ['pos', 'kiosk']);
        $this->makeItem($shared, 'Regular', 3.0, channels: null);

        $pos = $this->service->forChannel('pos', $this->branch->id);
        $kiosk = $this->service->forChannel('kiosk', $this->branch->id);

        $this->assertEqualsCanonicalizing(
            ['Backend POS', 'Shared'],
            collect($pos['categories'])->pluck('name')->all()
        );
        $this->assertSame(
            ['Shared'],
            collect($kiosk['categories'])->pluck('name')->all()
        );
    }

    public function test_kiosk_label_overrides_name_only_on_kiosk(): void
    {
        $category = $this->makeCategory(name: 'Tacos Signature', kioskLabel: 'Nos Tacos Borne');
        $this->makeItem($category, 'Tacos M', 6.5);

        $pos = $this->service->forChannel('pos', $this->branch->id);
        $kiosk = $this->service->forChannel('kiosk', $this->branch->id);

        $this->assertSame('Tacos Signature', $pos['categories'][0]['name']);
        $this->assertSame('Nos Tacos Borne', $kiosk['categories'][0]['name']);
    }

    public function test_channel_specific_sort_is_applied(): void
    {
        $a = $this->makeCategory(name: 'A', sort: 10, kioskSort: 2, posSort: 3);
        $b = $this->makeCategory(name: 'B', sort: 20, kioskSort: 1, posSort: 30);
        $c = $this->makeCategory(name: 'C', sort: 30, kioskSort: 3, posSort: 1);

        $this->makeItem($a, 'A item', 1.0);
        $this->makeItem($b, 'B item', 1.0);
        $this->makeItem($c, 'C item', 1.0);

        $kiosk = $this->service->forChannel('kiosk', $this->branch->id);
        $pos = $this->service->forChannel('pos', $this->branch->id);

        $this->assertSame(
            ['B', 'A', 'C'],
            collect($kiosk['categories'])->pluck('name')->all(),
            'Kiosk must honour kiosk_sort (1, 2, 3)'
        );
        $this->assertSame(
            ['C', 'A', 'B'],
            collect($pos['categories'])->pluck('name')->all(),
            'POS must honour pos_sort (1, 3, 30)'
        );
    }

    public function test_availability_row_marks_item_unavailable(): void
    {
        $category = $this->makeCategory();
        $item = $this->makeItem($category, 'Merguez Tacos', 7.0);

        ItemBranchAvailability::query()->create([
            'item_id' => $item->id,
            'branch_id' => $this->branch->id,
            'is_available' => false,
            'unavailable_reason' => 'out_of_stock',
            'unavailable_since' => now(),
            'daily_consumed_qty' => 0,
            'daily_reset_at' => now()->toDateString(),
        ]);

        $out = $this->service->forChannel('pos', $this->branch->id);
        $projected = $out['categories'][0]['items'][0];
        $this->assertFalse($projected['available']);
        $this->assertSame('out_of_stock', $projected['unavailable_reason']);
    }

    public function test_absent_availability_row_defaults_to_available(): void
    {
        $category = $this->makeCategory();
        $this->makeItem($category, 'No-row item', 5.0);

        $out = $this->service->forChannel('pos', $this->branch->id);
        $this->assertTrue($out['categories'][0]['items'][0]['available']);
        $this->assertArrayNotHasKey('unavailable_reason', $out['categories'][0]['items'][0]);
    }

    public function test_availability_is_scoped_to_the_requested_branch(): void
    {
        $otherBranch = Branch::factory()->create();
        $category = $this->makeCategory();
        $item = $this->makeItem($category, 'X', 5.0);

        ItemBranchAvailability::query()->create([
            'item_id' => $item->id,
            'branch_id' => $otherBranch->id, // different branch
            'is_available' => false,
            'unavailable_reason' => 'out_of_stock',
            'unavailable_since' => now(),
            'daily_consumed_qty' => 0,
            'daily_reset_at' => now()->toDateString(),
        ]);

        $out = $this->service->forChannel('pos', $this->branch->id);
        $this->assertTrue(
            $out['categories'][0]['items'][0]['available'],
            'Branch isolation must prevent cross-branch availability leaks'
        );
    }

    public function test_kiosk_emoji_is_exposed_only_on_kiosk(): void
    {
        $category = $this->makeCategory();
        $this->makeItem($category, 'Burger', 6.0, kioskEmoji: '🍔');

        $pos = $this->service->forChannel('pos', $this->branch->id);
        $kiosk = $this->service->forChannel('kiosk', $this->branch->id);

        $this->assertArrayNotHasKey('emoji', $pos['categories'][0]['items'][0]);
        $this->assertSame('🍔', $kiosk['categories'][0]['items'][0]['emoji']);
    }

    public function test_allergen_flags_are_passed_through(): void
    {
        $category = $this->makeCategory();
        $this->makeItem($category, 'Tacos halal', 6.0, allergens: ['halal', 'gluten']);

        $out = $this->service->forChannel('kiosk', $this->branch->id);
        $this->assertSame(['halal', 'gluten'], $out['categories'][0]['items'][0]['allergens']);
    }

    public function test_snapshot_version_is_returned_and_monotonic(): void
    {
        $snapshot = new MenuSnapshot(new Repository(new ArrayStore()));
        $service = new MenuProjectionService($snapshot);

        $this->makeItem($this->makeCategory(), 'X', 1.0);

        $v1 = $service->forChannel('pos', $this->branch->id)['snapshot_version'];
        $snapshot->bump($this->branch->id);
        $v2 = $service->forChannel('pos', $this->branch->id)['snapshot_version'];

        $this->assertGreaterThan($v1, $v2);
    }

    private function makeCategory(
        ?string $name = null,
        ?int $sort = null,
        ?array $channels = null,
        ?int $kioskSort = null,
        ?int $posSort = null,
        ?string $kioskLabel = null,
    ): ItemCategory {
        return ItemCategory::factory()->create(array_filter([
            'name' => $name ?? ('Cat '.uniqid()),
            'status' => Status::ACTIVE,
            'sort' => $sort ?? 1,
            'wizard_template' => 'tacos',
            'has_menu' => true,
            'channels' => $channels,
            'kiosk_sort' => $kioskSort,
            'pos_sort' => $posSort,
            'kiosk_label' => $kioskLabel,
        ], fn ($v) => $v !== null));
    }

    /**
     * @param  array<int, string>|null  $channels
     * @param  array<int, string>|null  $allergens
     */
    private function makeItem(
        ItemCategory $category,
        string $name,
        float $price,
        ?array $channels = null,
        ?array $allergens = null,
        ?string $kioskEmoji = null,
    ): Item {
        return Item::factory()->create(array_filter([
            'item_category_id' => $category->id,
            'tax_id' => $this->tax->id,
            'name' => $name,
            'price' => $price,
            'status' => Status::ACTIVE,
            'channels' => $channels,
            'allergen_flags' => $allergens,
            'kiosk_emoji' => $kioskEmoji,
        ], fn ($v) => $v !== null));
    }
}
