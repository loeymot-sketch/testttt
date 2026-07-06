<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use Database\Seeders\OnionCuitExtra20260706Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [OWNER8 2026-07-06] Seeder DATA « Oignons cuits » : extra gratuit ajouté sur
 * chaque item porteur de la crudité « Oignon » ACTIVE — idempotent, restore,
 * jamais sur les items sans oignon ni sur les porteurs inactifs.
 */
class OnionCuitExtraSeederTest extends TestCase
{
    use RefreshDatabase;

    private function makeItemWithOnion(bool $activeOnion = true): Item
    {
        $cat = ItemCategory::query()->firstOrCreate(
            ['slug' => 'sandwichs'],
            ['name' => 'Sandwichs', 'status' => Status::ACTIVE]
        );
        $item = Item::factory()->create(['item_category_id' => $cat->id, 'status' => Status::ACTIVE]);
        ItemExtra::create([
            'item_id'      => $item->id,
            'name'         => 'Oignon',
            'price'        => 0,
            'status'       => $activeOnion ? Status::ACTIVE : Status::INACTIVE,
            'group_label'  => 'crudite',
            'is_available' => 1,
        ]);

        return $item;
    }

    public function test_creates_free_oignons_cuits_on_active_onion_carriers_only(): void
    {
        $carrier = $this->makeItemWithOnion(activeOnion: true);
        $inactive = $this->makeItemWithOnion(activeOnion: false);
        $noOnion = Item::factory()->create([
            'item_category_id' => ItemCategory::where('slug', 'sandwichs')->value('id'),
            'status'           => Status::ACTIVE,
        ]);

        $this->seed(OnionCuitExtra20260706Seeder::class);

        $row = ItemExtra::where('item_id', $carrier->id)->where('name', 'Oignons cuits')->first();
        $this->assertNotNull($row, 'porteur actif → extra créé');
        $this->assertEqualsWithDelta(0.0, (float) $row->price, 0.001, 'gratuit (0 €)');
        $this->assertSame((int) Status::ACTIVE, (int) $row->status);
        $this->assertSame('crudite', $row->group_label, 'même groupe crudité que le cru');

        $this->assertSame(0, ItemExtra::where('item_id', $inactive->id)->where('name', 'Oignons cuits')->count(), 'porteur INACTIF ignoré');
        $this->assertSame(0, ItemExtra::where('item_id', $noOnion->id)->where('name', 'Oignons cuits')->count(), 'item sans oignon ignoré');
    }

    public function test_seeder_is_idempotent_and_restores_soft_deleted(): void
    {
        $carrier = $this->makeItemWithOnion();

        $this->seed(OnionCuitExtra20260706Seeder::class);
        $this->seed(OnionCuitExtra20260706Seeder::class);
        $this->assertSame(1, ItemExtra::where('item_id', $carrier->id)->where('name', 'Oignons cuits')->count(), 'pas de doublon');

        ItemExtra::where('item_id', $carrier->id)->where('name', 'Oignons cuits')->first()->delete();
        $this->seed(OnionCuitExtra20260706Seeder::class);
        $restored = ItemExtra::where('item_id', $carrier->id)->where('name', 'Oignons cuits')->first();
        $this->assertNotNull($restored, 'soft-deleted → restauré');
        $this->assertNull($restored->deleted_at);
    }
}
