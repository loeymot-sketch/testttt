<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemCategory;
use Database\Seeders\KdsStationAssignmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [SYNC-BORNE 2026-07-01] Verrouille le mapping catégorie → station KDS.
 * Le rapport cowork relevait kds_station=null sur tous les articles ; ce seeder
 * assigne un poste réel pour rendre le filtre KDS par station opérationnel.
 */
class KdsStationAssignmentSeederTest extends TestCase
{
    use RefreshDatabase;

    private function itemInCategory(string $catName): Item
    {
        $cat = ItemCategory::factory()->create(['name' => $catName]);

        return Item::factory()->create(['item_category_id' => $cat->id, 'kds_station' => 'none']);
    }

    public function test_seeder_maps_each_category_to_the_right_station(): void
    {
        $boisson = $this->itemInCategory('Boissons');
        $dessert = $this->itemInCategory('Desserts');
        $tacos = $this->itemInCategory('Tacos');
        $burger = $this->itemInCategory('Burgers');
        $frites = $this->itemInCategory('Frites');
        $interne = $this->itemInCategory('Technique (interne — upsell)');

        (new KdsStationAssignmentSeeder())->run();

        $this->assertSame('bar', $boisson->fresh()->kds_station, 'boisson → bar');
        $this->assertSame('cuisine_froide', $dessert->fresh()->kds_station, 'dessert → froide');
        $this->assertSame('cuisine_chaude', $tacos->fresh()->kds_station, 'tacos → chaude');
        $this->assertSame('cuisine_chaude', $burger->fresh()->kds_station, 'burger → chaude');
        $this->assertSame('cuisine_chaude', $frites->fresh()->kds_station, 'frites → chaude');
        $this->assertSame('none', $interne->fresh()->kds_station, 'interne/upsell → none');
    }

    public function test_seeder_is_idempotent(): void
    {
        $tacos = $this->itemInCategory('Tacos');

        (new KdsStationAssignmentSeeder())->run();
        (new KdsStationAssignmentSeeder())->run();

        $this->assertSame('cuisine_chaude', $tacos->fresh()->kds_station);
    }

    public function test_no_served_item_is_left_without_a_real_station(): void
    {
        $this->itemInCategory('Sandwichs');
        $this->itemInCategory('Bols');
        $this->itemInCategory('Boissons');

        (new KdsStationAssignmentSeeder())->run();

        // Aucun item de catégorie « plat/boisson » ne doit rester 'none'.
        $foodNone = Item::whereHas('category', function ($q) {
            $q->whereNotIn('name', ['Technique (interne — upsell)']);
        })->where('kds_station', 'none')->count();
        $this->assertSame(0, $foodNone, 'aucun plat/boisson ne reste sans station');
    }
}
