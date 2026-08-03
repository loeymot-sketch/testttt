<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use Database\Seeders\DrinksUpdate20260705Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [OWNER8 2026-07-06] Mission 3 — renommage owner : « Fanta Hawai 33cl » (id 124
 * en prod locale, slug legacy fanta-hawai) devient « Hawaï 33cl » (slug hawai).
 *
 * Le seeder DrinksUpdate20260705Seeder porte la migration : il retrouve la ligne
 * sous son slug legacy, la renomme slug+nom (idempotent, pas de doublon), et le
 * repli image Item::thumb reste câblé via config/menu_images.php (hawai →
 * fanta-fraise.png, clé legacy conservée).
 */
class DrinkNamingTest extends TestCase
{
    use RefreshDatabase;

    private function seedBoissonsCategory(): ItemCategory
    {
        return ItemCategory::query()->create(['name' => 'Boissons', 'slug' => 'boissons', 'status' => Status::ACTIVE]);
    }

    public function test_fresh_seed_creates_hawai_and_never_fanta_hawai(): void
    {
        $this->seedBoissonsCategory();

        $this->seed(DrinksUpdate20260705Seeder::class);

        $hawai = Item::where('slug', 'hawai')->first();
        $this->assertNotNull($hawai, 'la boisson « Hawaï 33cl » (slug hawai) doit exister');
        $this->assertSame('Hawaï 33cl', $hawai->name);
        $this->assertSame(0, Item::where('name', 'like', '%Fanta Hawai%')->count(), '« Fanta Hawai » ne doit plus exister');
        $this->assertSame(0, Item::where('slug', 'fanta-hawai')->count());
    }

    public function test_legacy_fanta_hawai_row_is_renamed_in_place_not_duplicated(): void
    {
        $cat = $this->seedBoissonsCategory();
        // État prod AVANT (VPS / DB locale) : id existant slug fanta-hawai
        $legacy = Item::factory()->create([
            'item_category_id' => $cat->id,
            'slug'             => 'fanta-hawai',
            'name'             => 'Fanta Hawai 33cl',
            'price'            => 1.90,
            'status'           => Status::ACTIVE,
        ]);

        $this->seed(DrinksUpdate20260705Seeder::class);
        $this->seed(DrinksUpdate20260705Seeder::class); // idempotent

        $legacy->refresh();
        $this->assertSame('Hawaï 33cl', $legacy->name, 'MÊME ligne renommée (id conservé — stock/commandes intacts)');
        $this->assertSame('hawai', $legacy->slug);
        $this->assertSame(1, Item::whereIn('slug', ['hawai', 'fanta-hawai'])->count(), 'aucun doublon créé');
    }

    public function test_fuze_tea_exact_spelling_untouched(): void
    {
        $this->seedBoissonsCategory();
        $this->seed(DrinksUpdate20260705Seeder::class);

        $this->assertSame('Fuze Tea 33cl', Item::where('slug', 'fuze-tea')->value('name'));
    }

    public function test_hawai_thumb_fallback_still_resolves_via_menu_images_config(): void
    {
        $items = config('menu_images.items', []);
        $this->assertSame('fanta-fraise.png', $items['hawai'] ?? null, 'clé hawai câblée');
        $this->assertFileExists(public_path('images/menu/fanta-fraise.png'), 'asset repli présent');

        $this->seedBoissonsCategory();
        $this->seed(DrinksUpdate20260705Seeder::class);
        $thumb = Item::where('slug', 'hawai')->first()->thumb;
        // extension-agnostique : le resolver peut servir la variante optimisée
        // (images/menu/thumbs/fanta-fraise.webp) du même visuel.
        $this->assertStringContainsString('fanta-fraise', $thumb, 'Item::thumb repli fonctionne pour hawai');
    }
}
