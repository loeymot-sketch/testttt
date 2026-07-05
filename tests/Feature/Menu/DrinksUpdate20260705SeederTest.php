<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use Database\Seeders\DrinksUpdate20260705Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [BOISSONS-UPDATE 2026-07-05] Le seeder ajoute les nouvelles boissons (SSOT items) →
 * elles apparaissent partout (caisse/borne/KDS/web) et sont gerables en stock. Idempotent.
 */
class DrinksUpdate20260705SeederTest extends TestCase
{
    use RefreshDatabase;

    private array $slugs = ['coca-cherry', 'tropico', 'ice-tea', 'fanta-citron'];

    public function test_creates_new_drinks_in_boissons_category_available(): void
    {
        $cat = ItemCategory::query()->create(['name' => 'Boissons', 'slug' => 'boissons', 'status' => Status::ACTIVE]);

        $this->seed(DrinksUpdate20260705Seeder::class);

        foreach ($this->slugs as $slug) {
            $item = Item::where('slug', $slug)->first();
            $this->assertNotNull($item, "boisson {$slug} doit exister");
            $this->assertSame($cat->id, (int) $item->item_category_id, "{$slug} dans la categorie Boissons");
            $this->assertSame((int) Status::ACTIVE, (int) $item->status, "{$slug} ACTIVE");
            $this->assertEquals(1, (int) $item->is_available, "{$slug} disponible");
            $this->assertEqualsWithDelta(1.90, (float) $item->price, 0.001, "{$slug} prix 1,90");
        }
        $this->assertSame('Coca Cherry 33cl', Item::where('slug', 'coca-cherry')->value('name'));
        $this->assertSame('Ice Tea Pêche 33cl', Item::where('slug', 'ice-tea')->value('name'));
    }

    public function test_seeder_is_idempotent_no_duplicates(): void
    {
        ItemCategory::query()->create(['name' => 'Boissons', 'slug' => 'boissons', 'status' => Status::ACTIVE]);

        $this->seed(DrinksUpdate20260705Seeder::class);
        $this->seed(DrinksUpdate20260705Seeder::class); // re-run

        foreach ($this->slugs as $slug) {
            $this->assertSame(1, Item::where('slug', $slug)->count(), "{$slug} ne doit exister qu'une fois");
        }
    }

    public function test_image_slugs_are_wired_in_menu_images_config(): void
    {
        $items = config('menu_images.items', []);
        $this->assertSame('coca-cherry.png', $items['coca-cherry'] ?? null);
        $this->assertSame('tropico.png', $items['tropico'] ?? null);
        $this->assertSame('lipton-peche.png', $items['ice-tea'] ?? null);
        $this->assertSame('fanta-citron.png', $items['fanta-citron'] ?? null);
    }
}
