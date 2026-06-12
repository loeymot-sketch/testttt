<?php

namespace Tests\Feature\Kiosk;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Services\Kiosk\KioskMenuService;
use Database\Seeders\HideUpsellVehicleItemsFromGridSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [HEAL dispute-r1 ADV-F-P1-2 2026-06-12] The 3 internal upsell SKUs
 * (« Menu (Frites + Boisson) », « Frites Seules », « Boisson Seule ») were
 * drifted onto the Sandwich category (+featured) → they OPENED the customer
 * grid as broken EN tiles. items.item_category_id is NOT NULL, so the fix
 * moves them into a dedicated INTERNAL category (channels=["admin"]) that no
 * channel-aware surface renders, while their own item channels stay NULL =
 * still orderable BY ID via item_addons (PricingService addon validation
 * checks the ITEM channels, never the category).
 */
class HideUpsellVehicleItemsFromGridSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_moves_drifted_upsell_vehicles_to_internal_category(): void
    {
        $this->seedMinimalSettings();
        [$branch, $category] = $this->fixture();

        // Drifted state observed live: Sandwich category + featured.
        $vehicles = collect(HideUpsellVehicleItemsFromGridSeeder::UPSELL_VEHICLE_SLUGS)
            ->map(fn (string $slug) => Item::factory()->create([
                'name' => ucwords(str_replace('-', ' ', $slug)),
                'slug' => $slug,
                'item_category_id' => $category->id,
                'is_featured' => Status::ACTIVE,
                'status' => Status::ACTIVE,
                'channels' => null,
            ]));

        $realSandwich = Item::factory()->create([
            'name' => 'Sandwich Cayenne Test',
            'slug' => 'sandwich-cayenne-test',
            'item_category_id' => $category->id,
            'status' => Status::ACTIVE,
            'channels' => null,
        ]);

        $this->seed(HideUpsellVehicleItemsFromGridSeeder::class);

        $internal = ItemCategory::query()
            ->where('slug', HideUpsellVehicleItemsFromGridSeeder::INTERNAL_CATEGORY_SLUG)
            ->firstOrFail();
        $this->assertSame(['admin'], (array) $internal->channels, 'internal category must be admin-only');
        $this->assertFalse($internal->isVisibleOn('kiosk'));

        foreach ($vehicles as $vehicle) {
            $fresh = $vehicle->fresh();
            $this->assertSame((int) $internal->id, (int) $fresh->item_category_id, "{$fresh->slug} must live in the internal category");
            $this->assertSame(Status::INACTIVE, (int) $fresh->is_featured);
            $this->assertSame(Status::ACTIVE, (int) $fresh->status, 'item stays ACTIVE → still orderable by id');
            $this->assertTrue($fresh->isVisibleOn('kiosk'), 'item channels untouched → addon validation keeps passing');
        }

        // Non-clobber: the real product keeps its category.
        $this->assertSame((int) $category->id, (int) $realSandwich->fresh()->item_category_id);

        // Kiosk grid payload: vehicles gone, real product still there.
        $menu = app(KioskMenuService::class)->build($branch);
        $gridItemSlugs = collect($menu['items'] ?? [])->pluck('slug')->filter()->all();
        $gridItemNames = collect($menu['items'] ?? [])->pluck('name')->filter()->all();
        foreach (HideUpsellVehicleItemsFromGridSeeder::UPSELL_VEHICLE_SLUGS as $slug) {
            $this->assertNotContains($slug, $gridItemSlugs, "{$slug} must not be in the kiosk grid payload");
            $this->assertNotContains(ucwords(str_replace('-', ' ', $slug)), $gridItemNames);
        }
        $this->assertTrue(
            in_array('sandwich-cayenne-test', $gridItemSlugs, true) || in_array('Sandwich Cayenne Test', $gridItemNames, true),
            'the real product must remain in the kiosk grid payload'
        );
        $gridCategorySlugs = collect($menu['categories'] ?? [])->pluck('slug')->filter()->all();
        $this->assertNotContains(
            HideUpsellVehicleItemsFromGridSeeder::INTERNAL_CATEGORY_SLUG,
            $gridCategorySlugs,
            'internal category must not appear in the kiosk sidebar'
        );
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seedMinimalSettings();
        [, $category] = $this->fixture();

        Item::factory()->create([
            'name' => 'Frites Seules',
            'slug' => 'frites-seules',
            'item_category_id' => $category->id,
            'is_featured' => Status::ACTIVE,
            'status' => Status::ACTIVE,
        ]);

        $this->seed(HideUpsellVehicleItemsFromGridSeeder::class);
        $first = Item::where('slug', 'frites-seules')->first()->toArray();
        $categoriesAfterFirst = ItemCategory::where('slug', HideUpsellVehicleItemsFromGridSeeder::INTERNAL_CATEGORY_SLUG)->count();

        $this->seed(HideUpsellVehicleItemsFromGridSeeder::class);
        $second = Item::where('slug', 'frites-seules')->first()->toArray();
        $categoriesAfterSecond = ItemCategory::where('slug', HideUpsellVehicleItemsFromGridSeeder::INTERNAL_CATEGORY_SLUG)->count();

        $this->assertSame(1, $categoriesAfterFirst);
        $this->assertSame(1, $categoriesAfterSecond, 'internal category must not duplicate');
        $this->assertSame($first['item_category_id'], $second['item_category_id']);
        $this->assertSame($first['is_featured'], $second['is_featured']);
    }

    /**
     * @return array{0: Branch, 1: ItemCategory}
     */
    private function fixture(): array
    {
        $branch = Branch::factory()->create();
        Tax::factory()->create(['status' => Status::ACTIVE]);
        $category = ItemCategory::factory()->create([
            'name' => 'Sandwich Cayenne',
            'status' => Status::ACTIVE,
        ]);

        return [$branch, $category];
    }
}
