<?php

namespace Tests\Feature\Pos;

use App\Models\Branch;
use App\Models\Item;
use App\Models\StockLevel;
use App\Models\StockOutflow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [OWNER REPAS-PERSONNEL/PERTES 2026-07-31] Sorties de stock hors-vente depuis la caisse :
 * trace (repas personnel / perte) + décrément du stock direct. Gate permission:pos.
 */
class PosStockOutflowTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key';

    protected function setUp(): void
    {
        parent::setUp();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        if (! Branch::withoutGlobalScopes()->find(1)) {
            Branch::factory()->create(['id' => 1]);
        }
        config(['app.api_key' => self::API_KEY]);
        $this->withHeaders(['x-api-key' => self::API_KEY, 'Accept' => 'application/json']);
    }

    private function cashier(): User
    {
        $u = User::factory()->create(['branch_id' => 1]);
        $u->assignRole('POS Operator');

        return $u;
    }

    private function item(string $name = 'Coca'): Item
    {
        return Item::factory()->create(['name' => $name]);
    }

    private function directStock(int $itemId, int $onHand): void
    {
        StockLevel::withoutGlobalScopes()->forceCreate([
            'branch_id'      => 1,
            'stockable_type' => Item::class,
            'stockable_id'   => $itemId,
            'on_hand'        => $onHand,
        ]);
    }

    /** @test La permission `pos` est requise. */
    public function test_requires_pos_permission(): void
    {
        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger, ['*']);
        $this->postJson('/api/admin/pos/stock-outflow', [
            'item_id' => 1, 'quantity' => 1, 'type' => 'waste',
        ])->assertStatus(403);
    }

    /** @test Repas personnel sur un item à STOCK DIRECT → trace + décrément du stock. */
    public function test_staff_meal_decrements_direct_stock_and_traces(): void
    {
        $item = $this->item('Coca 33cl');
        $this->directStock($item->id, 10);
        Sanctum::actingAs($this->cashier(), ['*']);

        $res = $this->postJson('/api/admin/pos/stock-outflow', [
            'item_id' => $item->id, 'quantity' => 2, 'type' => 'staff_meal', 'note' => 'Pause équipe',
        ]);

        $res->assertStatus(201)->assertJsonPath('outflow.type', 'staff_meal')
            ->assertJsonPath('outflow.stock_decremented', true);

        $this->assertSame(8, (int) StockLevel::withoutGlobalScopes()
            ->where('stockable_type', Item::class)->where('stockable_id', $item->id)->value('on_hand'),
            'Le stock direct doit passer de 10 à 8.');

        $this->assertDatabaseHas('stock_outflows', [
            'item_id' => $item->id, 'quantity' => 2, 'type' => 'staff_meal', 'note' => 'Pause équipe',
        ]);
        // Un StockMovement append-only a bien été écrit (motif canonique manual_out ; le type
        // métier repas/perte vit dans stock_outflows).
        $this->assertDatabaseHas('stock_movements', ['reason' => 'manual_out', 'delta' => -2]);
    }

    /** @test Perte sur un item COMPOSITE (sans stock direct) → trace seule, pas de décrément. */
    public function test_waste_for_item_without_direct_stock_only_traces(): void
    {
        $item = $this->item('Cayenne'); // pas de StockLevel direct
        Sanctum::actingAs($this->cashier(), ['*']);

        $res = $this->postJson('/api/admin/pos/stock-outflow', [
            'item_id' => $item->id, 'quantity' => 1, 'type' => 'waste',
        ]);

        $res->assertStatus(201)->assertJsonPath('outflow.type', 'waste')
            ->assertJsonPath('outflow.stock_decremented', false);
        $this->assertDatabaseHas('stock_outflows', ['item_id' => $item->id, 'type' => 'waste', 'stock_decremented' => false]);
    }

    /** @test L'historique récent liste les sorties. */
    public function test_recent_lists_outflows(): void
    {
        $item = $this->item('Frites');
        Sanctum::actingAs($this->cashier(), ['*']);
        $this->postJson('/api/admin/pos/stock-outflow', ['item_id' => $item->id, 'quantity' => 1, 'type' => 'waste']);

        $res = $this->getJson('/api/admin/pos/stock-outflow/recent');
        $res->assertStatus(200)->assertJsonPath('data.0.item_name', 'Frites')
            ->assertJsonPath('data.0.type_label', 'Perte');
    }

    /** @test Type invalide → 422. */
    public function test_invalid_type_rejected(): void
    {
        $item = $this->item();
        Sanctum::actingAs($this->cashier(), ['*']);
        $this->postJson('/api/admin/pos/stock-outflow', [
            'item_id' => $item->id, 'quantity' => 1, 'type' => 'vol',
        ])->assertStatus(422);
    }

    /** @test La table stock_outflows est append-only (pas d'update). */
    public function test_outflows_are_append_only(): void
    {
        $item = $this->item();
        $o = StockOutflow::withoutGlobalScopes()->create([
            'branch_id' => 1, 'item_id' => $item->id, 'item_name' => 'X', 'quantity' => 1,
            'type' => 'waste', 'user_id' => 1, 'stock_decremented' => false, 'created_at' => now(),
        ]);
        $this->expectException(\LogicException::class);
        $o->update(['quantity' => 5]);
    }
}
