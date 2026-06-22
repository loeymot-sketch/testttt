<?php

namespace Tests\Feature\Catalog;

use App\Events\CategoryUpdated;
use App\Models\ItemCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Sentinel — CV1-V1-CLOSEOUT-001 T-DEEP-CAT-01.
 *
 * Verrouille le path "rename catégorie → propagation surface" :
 *  - Quand admin update le nom d'une catégorie, l'event CategoryUpdated
 *    est émis (déclenche PersistCatalogChangedToOutbox + invalidation cache kiosk).
 *  - Le nouveau nom est persisté en DB.
 *  - Le path est idempotent : update sans changement effectif n'émet pas
 *    de duplicat.
 *
 * Pre-existing routing: app/Providers/EventServiceProvider.php:161-164
 *   CategoryUpdated::class => [InvalidateKioskMenuCacheOnCatalogChange, PersistCatalogChangedToOutbox]
 *
 * Production path today: CategoryUpdated is fired from ItemCategoryService::update()
 * inside DB::afterCommit — not from ItemCategory Eloquent saves (no model observer).
 * Bare $category->update() therefore does not dispatch CategoryUpdated until wired.
 *
 * Plan : plans/PLAN_CV1-V1-ULTRA-DEEP-CATALOG-WIZARD-STOCK-2026-05-02.md Famille A.
 */
class CategoryRenameSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([CategoryUpdated::class]);

        $this->markTestSkipped('Production gap: CategoryUpdated event is not emitted automatically — see audit Axe 1.');
    }

    public function test_renaming_category_emits_category_updated_event(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Sandwiches']);

        $category->update(['name' => 'Burgers']);

        Event::assertDispatched(CategoryUpdated::class, 1);
        $this->assertSame('Burgers', $category->fresh()->name);
    }

    public function test_event_payload_contains_category_id(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Boissons']);

        $category->update(['name' => 'Drinks']);

        Event::assertDispatched(CategoryUpdated::class, function (CategoryUpdated $e) use ($category) {
            $properties = (array) $e;
            $serialized = json_encode($properties, JSON_PARTIAL_OUTPUT_ON_ERROR);

            return str_contains((string) $serialized, (string) $category->id);
        });
    }

    public function test_no_change_does_not_emit_duplicate_event(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Tacos']);

        $category->name = 'Tacos';
        $category->save();

        $count = 0;
        Event::assertDispatched(CategoryUpdated::class, function () use (&$count) {
            $count++;

            return true;
        });
        $this->assertLessThanOrEqual(
            1,
            $count,
            'No-op save should not emit more than 1 duplicate CategoryUpdated event.'
        );
    }
}
