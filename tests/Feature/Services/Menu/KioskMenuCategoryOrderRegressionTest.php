<?php

namespace Tests\Feature\Services\Menu;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Services\Kiosk\KioskMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [W-A BORNE P1 regression 2026-06-10]
 *
 * `KioskMenuService::projectCategories()` used the `sortBy([fn, fn])`
 * array-of-closures form, which Laravel interprets as [key, direction]
 * pairs — same root cause as Wave Y A-001 (items, fixed at sortItems).
 * The categories payload came back in reverse/unstable order
 * (10,11,8,9,6,7,5,4,3,2,1) and the kiosk SPA auto-selects
 * `categories[0]` as the landing category → the borne landed on the
 * LAST category (« Boissons ») instead of the first (« Sandwich
 * Cayenne »). This test locks the ascending (sortFor, id) contract.
 */
class KioskMenuCategoryOrderRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_menu_categories_are_sorted_ascending_by_sort_then_id(): void
    {
        $branch = Branch::factory()->create();

        $ids = [];
        foreach ([1, 2, 3, 4, 5] as $sort) {
            $cat = ItemCategory::factory()->create([
                'name'   => "Cat sort {$sort}",
                'sort'   => $sort,
                'status' => Status::ACTIVE,
            ]);
            // Une catégorie sans item actif est filtrée du payload kiosk —
            // créer un item minimal par catégorie.
            Item::factory()->create([
                'item_category_id' => $cat->id,
                'status'           => Status::ACTIVE,
            ]);
            $ids[$sort] = $cat->id;
        }

        $payload = (new KioskMenuService())->build($branch->fresh());

        $orderedSorts = collect($payload['categories'])
            ->map(fn (array $c) => (int) $c['sort'])
            ->values()
            ->all();

        $ascending = $orderedSorts;
        sort($ascending);

        $this->assertSame(
            $ascending,
            $orderedSorts,
            'kiosk menu categories payload must be ascending by sort (landing category = first category)'
        );

        $firstCat = $payload['categories'][0] ?? null;
        $this->assertNotNull($firstCat);
        $this->assertSame(
            $ids[1],
            (int) $firstCat['id'],
            'first category of the payload must be the lowest sort (kiosk SPA auto-selects categories[0])'
        );
    }
}
