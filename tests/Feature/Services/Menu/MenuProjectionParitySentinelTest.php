<?php

namespace Tests\Feature\Services\Menu;

use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemAttribute;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\Tax;
use App\Services\Kiosk\KioskMenuService;
use App\Services\Menu\MenuProjectionService;
use App\Services\Menu\MenuSnapshot;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuProjectionParitySentinelTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Tax $tax;
    private MenuProjectionService $projection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->tax = Tax::factory()->create([
            'name' => 'TVA Projection Parity',
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 10.0,
            'status' => Status::ACTIVE,
        ]);
        $this->projection = new MenuProjectionService(
            new MenuSnapshot(new Repository(new ArrayStore()))
        );
    }

    public function test_pos_and_kiosk_projection_keep_shared_item_identity_price_and_availability(): void
    {
        $ctx = $this->makeSharedCatalogFixture(branchAvailable: false);

        $posItem = $this->projectedItem('pos', $ctx['item']->id);
        $kioskItem = $this->projectedItem('kiosk', $ctx['item']->id);

        foreach (['id', 'category_id', 'item_category_id', 'name', 'slug', 'price', 'tax_id', 'item_type', 'status'] as $key) {
            $this->assertSame($posItem[$key], $kioskItem[$key], "Shared projection field {$key} drifted");
        }

        $this->assertFalse($posItem['is_available']);
        $this->assertFalse($kioskItem['is_available']);
        $this->assertSame('out_of_stock', $posItem['unavailable_reason']);
        $this->assertSame('out_of_stock', $kioskItem['unavailable_reason']);
    }

    public function test_pos_and_kiosk_projection_filter_channel_specific_composition_without_price_drift(): void
    {
        $ctx = $this->makeSharedCatalogFixture();

        $posItem = $this->projectedItem('pos', $ctx['item']->id);
        $kioskItem = $this->projectedItem('kiosk', $ctx['item']->id);

        $this->assertSame(
            [$ctx['sharedVariation']->id, $ctx['posVariation']->id],
            collect($posItem['variations'])->pluck('id')->all()
        );
        $this->assertSame(
            [$ctx['sharedVariation']->id, $ctx['kioskVariation']->id],
            collect($kioskItem['variations'])->pluck('id')->all()
        );

        $this->assertSame(
            [$ctx['sharedExtra']->id, $ctx['posExtra']->id],
            collect($posItem['extras'])->pluck('id')->all()
        );
        $this->assertSame(
            [$ctx['sharedExtra']->id, $ctx['kioskExtra']->id],
            collect($kioskItem['extras'])->pluck('id')->all()
        );

        $sharedPosVariation = collect($posItem['variations'])->firstWhere('id', $ctx['sharedVariation']->id);
        $sharedKioskVariation = collect($kioskItem['variations'])->firstWhere('id', $ctx['sharedVariation']->id);
        $this->assertSame($sharedPosVariation['price'], $sharedKioskVariation['price']);

        $this->assertSame(
            collect($posItem['addons'])->pluck('addon_item_id')->all(),
            collect($kioskItem['addons'])->pluck('addon_item_id')->all()
        );
    }

    public function test_pos_and_kiosk_projection_filter_inactive_unavailable_and_hidden_addons(): void
    {
        $ctx = $this->makeSharedCatalogFixture();
        $inactiveAddonItem = Item::factory()->create([
            'item_category_id' => $ctx['category']->id,
            'tax_id' => $this->tax->id,
            'name' => 'Inactive Addon',
            'price' => 2.50,
            'status' => Status::INACTIVE,
            'channels' => null,
        ]);
        $unavailableAddonItem = Item::factory()->create([
            'item_category_id' => $ctx['category']->id,
            'tax_id' => $this->tax->id,
            'name' => 'Unavailable Addon',
            'price' => 2.50,
            'status' => Status::ACTIVE,
            'is_available' => false,
            'channels' => null,
        ]);
        $hiddenAddonItem = Item::factory()->create([
            'item_category_id' => $ctx['category']->id,
            'tax_id' => $this->tax->id,
            'name' => 'Web Only Addon',
            'price' => 2.50,
            'status' => Status::ACTIVE,
            'channels' => ['web'],
        ]);
        foreach ([$inactiveAddonItem, $unavailableAddonItem, $hiddenAddonItem] as $addonItem) {
            ItemAddon::query()->create([
                'item_id' => $ctx['item']->id,
                'addon_item_id' => $addonItem->id,
                'addon_item_variation' => null,
            ]);
        }

        $posItem = $this->projectedItem('pos', $ctx['item']->id);
        $kioskItem = $this->projectedItem('kiosk', $ctx['item']->id);
        $legacy = app(KioskMenuService::class)->build($this->branch);
        $legacyItem = collect($legacy['items'])->firstWhere('id', $ctx['item']->id);

        $this->assertSame([$ctx['addonItem']->id], collect($posItem['addons'])->pluck('addon_item_id')->all());
        $this->assertSame([$ctx['addonItem']->id], collect($kioskItem['addons'])->pluck('addon_item_id')->all());
        $this->assertSame([$ctx['addonItem']->id], collect($legacyItem['addons'])->pluck('addon_item_id')->all());
    }

    public function test_pos_and_kiosk_projection_include_composer_selection_constraints(): void
    {
        $ctx = $this->makeSharedCatalogFixture();

        $posItem = $this->projectedItem('pos', $ctx['item']->id);
        $kioskItem = $this->projectedItem('kiosk', $ctx['item']->id);

        foreach ([$posItem, $kioskItem] as $projectedItem) {
            $this->assertArrayHasKey('itemAttributes', $projectedItem);
            $attribute = collect($projectedItem['itemAttributes'])->firstWhere('id', $ctx['attribute']->id);

            $this->assertIsArray($attribute);
            $this->assertSame('Viandes composeur', $attribute['name']);
            $this->assertSame(1, $attribute['min_select']);
            $this->assertSame(3, $attribute['max_select']);
            $this->assertTrue($attribute['allow_repeat']);
        }
    }

    public function test_kiosk_legacy_menu_shared_fields_match_canonical_projection(): void
    {
        $ctx = $this->makeSharedCatalogFixture(branchAvailable: false);

        $legacy = app(KioskMenuService::class)->build($this->branch);
        $legacyItem = collect($legacy['items'])->firstWhere('id', $ctx['item']->id);
        $canonicalItem = $this->projectedItem('kiosk', $ctx['item']->id);

        foreach (['id', 'category_id', 'item_category_id', 'name', 'slug', 'price', 'is_available', 'unavailable_reason'] as $key) {
            $this->assertSame($legacyItem[$key], $canonicalItem[$key], "Kiosk legacy field {$key} differs from canonical projection");
        }

        $this->assertSame(
            collect($legacyItem['variations'])->pluck('id')->all(),
            collect($canonicalItem['variations'])->pluck('id')->all()
        );
        $this->assertSame(
            collect($legacyItem['extras'])->pluck('id')->all(),
            collect($canonicalItem['extras'])->pluck('id')->all()
        );
        $this->assertSame(
            collect($legacyItem['itemAttributes'])->pluck('id')->all(),
            collect($canonicalItem['itemAttributes'])->pluck('id')->all()
        );
    }

    public function test_pos_and_kiosk_consume_published_composer_profile_for_shared_steps(): void
    {
        $ctx = $this->makeSharedCatalogFixture();
        $profile = ItemWizardProfile::query()->create([
            'item_id' => $ctx['item']->id,
            'template' => 'tacos',
            'version' => 1,
            'is_published' => true,
            'published_at' => now(),
            'branch_id_scope' => null,
        ]);
        $profile->steps()->create([
            'step_key' => 'viandes',
            'label' => 'Viandes',
            'source_type' => 'item_attribute',
            'source_ref' => (string) $ctx['attribute']->id,
            'min_select' => 1,
            'max_select' => 3,
            'allow_repeat' => true,
            'visible_on' => ['pos', 'kiosk'],
            'stockable_choices' => false,
            'position' => 0,
            'is_active' => true,
        ]);

        $posItem = $this->projectedItem('pos', $ctx['item']->id);
        $kioskItem = $this->projectedItem('kiosk', $ctx['item']->id);

        $this->assertSame((int) $profile->id, $posItem['composer_profile']['id']);
        $this->assertSame((int) $profile->id, $kioskItem['composer_profile']['id']);
        $this->assertSame(
            collect($posItem['composer_profile']['steps'])->pluck('id')->all(),
            collect($kioskItem['composer_profile']['steps'])->pluck('id')->all()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function makeSharedCatalogFixture(bool $branchAvailable = true): array
    {
        $category = ItemCategory::factory()->create([
            'name' => 'Projection Parity',
            'status' => Status::ACTIVE,
            'channels' => null,
            'sort' => 1,
        ]);

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $this->tax->id,
            'name' => 'Tacos Sync',
            'slug' => 'tacos-sync-' . uniqid(),
            'price' => 11.90,
            'status' => Status::ACTIVE,
            'is_available' => true,
            'channels' => null,
        ]);

        $addonItem = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $this->tax->id,
            'name' => 'Boisson Addon',
            'price' => 2.50,
            'status' => Status::ACTIVE,
            'channels' => null,
        ]);

        $attribute = ItemAttribute::factory()->create([
            'name' => 'Viandes composeur',
            'status' => Status::ACTIVE,
            'min_select' => 1,
            'max_select' => 3,
            'allow_repeat' => true,
        ]);
        $sharedVariation = $this->makeVariation($item, $attribute, 'Viande partagée', 1.50, null);
        $posVariation = $this->makeVariation($item, $attribute, 'Viande POS', 2.00, ['pos']);
        $kioskVariation = $this->makeVariation($item, $attribute, 'Viande Kiosk', 2.25, ['kiosk']);

        $sharedExtra = $this->makeExtra($item, 'Sauce partagée', 0.50, null);
        $posExtra = $this->makeExtra($item, 'Sauce POS', 0.75, ['pos']);
        $kioskExtra = $this->makeExtra($item, 'Sauce Kiosk', 1.00, ['kiosk']);

        $addon = ItemAddon::query()->create([
            'item_id' => $item->id,
            'addon_item_id' => $addonItem->id,
            'addon_item_variation' => null,
        ]);

        if (! $branchAvailable) {
            ItemBranchAvailability::query()->create([
                'item_id' => $item->id,
                'branch_id' => $this->branch->id,
                'is_available' => false,
                'unavailable_reason' => 'out_of_stock',
                'unavailable_since' => now(),
                'daily_consumed_qty' => 0,
                'daily_reset_at' => now()->toDateString(),
            ]);
        }

        return compact(
            'category',
            'item',
            'addonItem',
            'attribute',
            'sharedVariation',
            'posVariation',
            'kioskVariation',
            'sharedExtra',
            'posExtra',
            'kioskExtra',
            'addon'
        );
    }

    private function makeVariation(Item $item, ItemAttribute $attribute, string $name, float $price, ?array $visibleOn): ItemVariation
    {
        return ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => $name,
            'price' => $price,
            'status' => Status::ACTIVE,
            'visible_on' => $visibleOn,
        ]);
    }

    private function makeExtra(Item $item, string $name, float $price, ?array $visibleOn): ItemExtra
    {
        return ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => $name,
            'price' => $price,
            'status' => Status::ACTIVE,
            'visible_on' => $visibleOn,
        ]);
    }

    private function projectedItem(string $channel, int $itemId): array
    {
        $projection = $this->projection->forChannel($channel, $this->branch->id);

        $item = collect($projection['categories'])
            ->flatMap(fn (array $category): array => $category['items'])
            ->firstWhere('id', $itemId);

        $this->assertIsArray($item, "Item {$itemId} not found in {$channel} projection");

        return $item;
    }
}
