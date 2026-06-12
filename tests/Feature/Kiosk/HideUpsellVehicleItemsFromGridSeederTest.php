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

    /**
     * [HEAL dispute-r3 C-R2-NEW-1 2026-06-12] Collateral regression of this
     * very seeder: defeaturing the 3 vehicles killed the ONLY featured items
     * alive → kiosk upsell pool = 0 → the upsell screen auto-skips forever
     * (`no_suggestions`) and the merchandising surface silently disappeared.
     * The seeder must now ALSO revive the pool by flagging REAL sellable
     * add-ons (drink/dessert) `is_upsell = Ask::YES`, while the vehicles stay
     * out of the grid AND out of the pool (broken-image internal SKUs).
     */
    public function test_seeder_revives_kiosk_upsell_pool_with_real_sellable_items(): void
    {
        $this->seedMinimalSettings();
        [, $category] = $this->fixture();

        // Drifted vehicles (featured, on a customer category).
        foreach (HideUpsellVehicleItemsFromGridSeeder::UPSELL_VEHICLE_SLUGS as $slug) {
            Item::factory()->create([
                'name' => ucwords(str_replace('-', ' ', $slug)),
                'slug' => $slug,
                'item_category_id' => $category->id,
                'is_featured' => Status::ACTIVE,
                'status' => Status::ACTIVE,
            ]);
        }

        // Real sellable add-ons (live catalog slugs) — pool candidates.
        $drinks = ItemCategory::factory()->create(['name' => 'Boissons', 'slug' => 'boissons', 'status' => Status::ACTIVE]);
        $desserts = ItemCategory::factory()->create(['name' => 'Desserts', 'slug' => 'desserts', 'status' => Status::ACTIVE]);
        $pool = [
            ['slug' => 'coca', 'name' => 'Coca-Cola 33cl', 'cat' => $drinks->id],
            ['slug' => 'tiramisu', 'name' => 'Tiramisu', 'cat' => $desserts->id],
            ['slug' => 'glace', 'name' => 'Glace', 'cat' => $desserts->id],
        ];
        foreach ($pool as $row) {
            Item::factory()->create([
                'name' => $row['name'],
                'slug' => $row['slug'],
                'item_category_id' => $row['cat'],
                'is_upsell' => \App\Enums\Ask::NO,
                'is_featured' => Status::INACTIVE,
                'status' => Status::ACTIVE,
            ]);
        }

        $this->seed(HideUpsellVehicleItemsFromGridSeeder::class);

        // Pool flags: the real add-ons are now upsell candidates.
        foreach (HideUpsellVehicleItemsFromGridSeeder::UPSELL_POOL_SLUGS as $slug) {
            $this->assertSame(
                \App\Enums\Ask::YES,
                (int) Item::where('slug', $slug)->firstOrFail()->is_upsell,
                "{$slug} must be flagged is_upsell=YES by the seeder"
            );
        }

        // Vehicles: still defeatured, still NOT in the pool.
        foreach (HideUpsellVehicleItemsFromGridSeeder::UPSELL_VEHICLE_SLUGS as $slug) {
            $vehicle = Item::where('slug', $slug)->firstOrFail();
            $this->assertSame(Status::INACTIVE, (int) $vehicle->is_featured);
            $this->assertNotSame(\App\Enums\Ask::YES, (int) $vehicle->is_upsell, "{$slug} must NOT enter the upsell pool");
        }

        // The kiosk upsell endpoint serves a NON-EMPTY pool again.
        $response = $this->getJson('/api/frontend/item/kiosk-upsell?limit=6')->assertOk();
        $served = collect($response->json('data'))->pluck('slug')->all();
        $this->assertNotEmpty($served, 'kiosk upsell pool must not be empty post-seed');
        $this->assertEmpty(
            array_intersect(HideUpsellVehicleItemsFromGridSeeder::UPSELL_VEHICLE_SLUGS, $served),
            'vehicles must never be served as upsell suggestions'
        );
        foreach ($served as $slug) {
            $this->assertContains($slug, array_column($pool, 'slug'));
        }
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
