<?php

namespace Tests\Feature\RawMaterials;

use App\Models\RawMaterial;
use App\Models\RawMaterialMovement;
use App\Models\RawMaterialStock;
use App\Services\RawMaterials\RawMaterialStockService;
use Database\Seeders\RawMaterialBaselineSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P1a] Fondations « matières premières »
 * (B1+B2) : schéma, unicité branch+nom, service receive/consume/adjust
 * idempotent, stock signé, seeder idempotent, soft-delete.
 *
 * NF525 : ce domaine ne touche PAS la chaîne fiscale — aucune assertion fiscale.
 */
class RawMaterialFoundationsTest extends TestCase
{
    use RefreshDatabase;

    private function service(): RawMaterialStockService
    {
        return new RawMaterialStockService();
    }

    private function makeMaterial(string $name = 'Viande hachée', array $attrs = []): RawMaterial
    {
        return RawMaterial::create(array_merge([
            'branch_id' => 1,
            'name' => $name,
            'unit' => 'g',
            'is_active' => true,
        ], $attrs));
    }

    public function test_the_four_raw_material_tables_exist_with_key_columns(): void
    {
        $this->assertTrue(Schema::hasTable('raw_materials'));
        $this->assertTrue(Schema::hasTable('raw_material_recipe_lines'));
        $this->assertTrue(Schema::hasTable('raw_material_stocks'));
        $this->assertTrue(Schema::hasTable('raw_material_movements'));

        $this->assertTrue(Schema::hasColumns('raw_materials', [
            'branch_id', 'name', 'unit', 'piece_weight_g', 'avg_cost',
            'threshold_low', 'is_active', 'deleted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('raw_material_recipe_lines', [
            'branch_id', 'subject_type', 'subject_id', 'subject_group', 'raw_material_id', 'qty',
        ]));
        $this->assertTrue(Schema::hasColumns('raw_material_stocks', [
            'raw_material_id', 'branch_id', 'on_hand',
        ]));
        $this->assertTrue(Schema::hasColumns('raw_material_movements', [
            'raw_material_id', 'branch_id', 'delta', 'reason',
            'source_type', 'source_id', 'meta', 'created_at',
        ]));
    }

    public function test_branch_name_uniqueness_is_enforced(): void
    {
        $this->makeMaterial('Poulet');

        $this->expectException(QueryException::class);
        $this->makeMaterial('Poulet'); // même (branch_id=1, name) → viole l'unique
    }

    public function test_receive_creates_stock_and_appends_one_movement(): void
    {
        $material = $this->makeMaterial();

        $stock = $this->service()->receive($material->id, 10, 'delivery', 'invoice', 501);

        $this->assertEqualsWithDelta(10.0, (float) $stock->on_hand, 0.001);
        $this->assertEqualsWithDelta(
            10.0,
            (float) RawMaterialStock::where('raw_material_id', $material->id)->value('on_hand'),
            0.001
        );

        $movements = RawMaterialMovement::where('raw_material_id', $material->id)->get();
        $this->assertCount(1, $movements);
        $this->assertEqualsWithDelta(10.0, (float) $movements->first()->delta, 0.001);
        $this->assertSame('delivery', $movements->first()->reason);
    }

    public function test_double_receive_same_source_is_idempotent(): void
    {
        $material = $this->makeMaterial();
        $service = $this->service();

        $service->receive($material->id, 10, 'delivery', 'invoice', 777);
        $service->receive($material->id, 10, 'delivery', 'invoice', 777); // rejeu même source

        // Un seul mouvement, stock NON doublé.
        $this->assertSame(1, RawMaterialMovement::where('raw_material_id', $material->id)->count());
        $this->assertEqualsWithDelta(
            10.0,
            (float) RawMaterialStock::where('raw_material_id', $material->id)->value('on_hand'),
            0.001
        );
    }

    public function test_manual_receive_without_source_is_not_deduplicated(): void
    {
        $material = $this->makeMaterial();
        $service = $this->service();

        // Deux entrées manuelles (source nulle) DOIVENT toutes deux s'appliquer.
        $service->receive($material->id, 4, 'manual_in');
        $service->receive($material->id, 6, 'manual_in');

        $this->assertSame(2, RawMaterialMovement::where('raw_material_id', $material->id)->count());
        $this->assertEqualsWithDelta(
            10.0,
            (float) RawMaterialStock::where('raw_material_id', $material->id)->value('on_hand'),
            0.001
        );
    }

    public function test_consume_can_drive_on_hand_negative(): void
    {
        $material = $this->makeMaterial();

        // Aucun stock préalable → la conso crée la row et passe en négatif (signé).
        $stock = $this->service()->consume($material->id, 5, 'sale', 'order', 42);

        $this->assertEqualsWithDelta(-5.0, (float) $stock->on_hand, 0.001);
        $movement = RawMaterialMovement::where('raw_material_id', $material->id)->first();
        $this->assertEqualsWithDelta(-5.0, (float) $movement->delta, 0.001);
    }

    public function test_adjust_sets_absolute_target_and_records_signed_variance(): void
    {
        $material = $this->makeMaterial();
        $service = $this->service();

        $service->receive($material->id, 10, 'delivery'); // on_hand = 10
        $stock = $service->adjust($material->id, 7, 'inventory_count', 'count', 3); // cible 7

        $this->assertEqualsWithDelta(7.0, (float) $stock->on_hand, 0.001);

        $variance = RawMaterialMovement::where('raw_material_id', $material->id)
            ->where('reason', 'inventory_count')
            ->first();
        $this->assertNotNull($variance);
        $this->assertEqualsWithDelta(-3.0, (float) $variance->delta, 0.001); // 7 - 10
    }

    public function test_baseline_seeder_is_idempotent_eleven_rows(): void
    {
        $this->seed(RawMaterialBaselineSeeder::class);
        $this->seed(RawMaterialBaselineSeeder::class); // relance

        $this->assertSame(11, RawMaterial::count());
        $this->assertSame(
            '75.00',
            (string) RawMaterial::where('name', 'Viande hachée')->value('piece_weight_g')
        );
        // Pas de canette (plan amendement #2).
        $this->assertSame(0, RawMaterial::where('name', 'like', '%anette%')->count());
    }

    public function test_soft_delete_hides_but_retains_the_row(): void
    {
        $material = $this->makeMaterial('Galette', ['unit' => 'piece']);
        $id = $material->id;

        $material->delete();

        $this->assertSoftDeleted('raw_materials', ['id' => $id]);
        $this->assertNull(RawMaterial::find($id));
        $this->assertNotNull(RawMaterial::withTrashed()->find($id));
    }
}
