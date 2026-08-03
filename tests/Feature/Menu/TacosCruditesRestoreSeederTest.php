<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use Database\Seeders\TacosCruditesRestore20260707Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [owner 2026-07-07] Restauration des crudités gratuites sur les Tacos (M=26,
 * L=97). Verrouille le fix « la borne n'affichait aucun choix de crudité pour
 * un tacos » : le seeder recâble Salade/Tomate/Oignon (0 €, group_label=crudite),
 * de façon idempotente, sans doublon, et restaure les lignes soft-deleted.
 */
class TacosCruditesRestoreSeederTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED = ['Salade', 'Tomate', 'Oignon'];

    private function makeTacos(int $id, string $name): Item
    {
        $cat = ItemCategory::query()->firstOrCreate(
            ['slug' => 'nos-tacos'],
            ['name' => 'Nos Tacos', 'status' => Status::ACTIVE]
        );

        return Item::factory()->create([
            'id'               => $id,
            'name'             => $name,
            'item_category_id' => $cat->id,
            'status'           => Status::ACTIVE,
        ]);
    }

    public function test_wires_three_free_crudites_on_both_tacos(): void
    {
        $this->makeTacos(26, 'Tacos M');
        $this->makeTacos(97, 'Tacos L');

        $this->seed(TacosCruditesRestore20260707Seeder::class);

        foreach ([26, 97] as $tacosId) {
            $crud = ItemExtra::where('item_id', $tacosId)
                ->where('group_label', 'crudite')
                ->where('status', Status::ACTIVE)
                ->get();

            $this->assertSame(
                collect(self::EXPECTED)->sort()->values()->all(),
                $crud->pluck('name')->sort()->values()->all(),
                "tacos #{$tacosId} doit porter exactement Salade/Tomate/Oignon"
            );
            $this->assertEqualsWithDelta(0.0, (float) $crud->sum('price'), 0.001, 'crudités gratuites (0 €)');
            foreach ($crud as $e) {
                $this->assertNull($e->visible_on, 'crudité kiosk-visible (visible_on=null)');
            }
        }
    }

    public function test_seeder_is_idempotent_and_restores_soft_deleted(): void
    {
        $this->makeTacos(26, 'Tacos M');
        $this->makeTacos(97, 'Tacos L');

        $this->seed(TacosCruditesRestore20260707Seeder::class);
        $this->seed(TacosCruditesRestore20260707Seeder::class);

        $this->assertSame(
            3,
            ItemExtra::where('item_id', 26)->where('group_label', 'crudite')->count(),
            'pas de doublon après double seed'
        );

        // soft-delete une crudité → le seeder la restaure
        ItemExtra::where('item_id', 26)->where('name', 'Salade')->first()->delete();
        $this->seed(TacosCruditesRestore20260707Seeder::class);
        $restored = ItemExtra::where('item_id', 26)->where('name', 'Salade')->first();
        $this->assertNotNull($restored, 'crudité soft-deleted → restaurée');
        $this->assertNull($restored->deleted_at);
        $this->assertSame((int) Status::ACTIVE, (int) $restored->status);
    }
}
