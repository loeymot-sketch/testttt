<?php

namespace Tests\Feature\Stock;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\RawMaterial;
use App\Models\RawMaterialMovement;
use App\Models\RawMaterialStock;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\Stock\UnifiedStockViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * [PHASE 3d — VUE CONSO & STOCK UNIFIÉE 2026-07-24] Agrégateur lecture seule :
 * matières premières + boissons dans un seul tableau + section « à acheter ».
 *
 * Couvre : les deux rayons, conso récente fenêtrée (matière 'sale' / boisson
 * sortante), avg_cost NULL-safe, valeur de stock, statuts out/low/ok, section
 * à-acheter fondue + triée, totaux, et hard-scope branche (isolation).
 *
 * NF525 : domaine ADDITIF — aucune assertion fiscale, service 0 écriture.
 */
class UnifiedStockViewServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // stock_levels.branch_id est FK-contraint sur branches ; la V1 mono-poste
        // vit sur la branche 1 (que le service hard-scope). Les tables matière
        // (raw_material_*) n'ont pas cette FK — d'où le seed ciblé ici.
        if (Branch::find(1) === null) {
            Branch::factory()->create(['id' => 1]);
        }
    }

    private function service(): UnifiedStockViewService
    {
        return app(UnifiedStockViewService::class);
    }

    private function material(array $attrs = []): RawMaterial
    {
        return RawMaterial::create(array_merge([
            'branch_id' => 1,
            'name' => 'Matière '.Str::random(4),
            'unit' => 'g',
            'is_active' => true,
        ], $attrs));
    }

    private function rawStock(RawMaterial $m, float $onHand, int $branchId = 1): void
    {
        RawMaterialStock::create([
            'raw_material_id' => $m->id,
            'branch_id' => $branchId,
            'on_hand' => $onHand,
        ]);
    }

    private function rawSale(RawMaterial $m, float $delta, $createdAt, int $branchId = 1): void
    {
        RawMaterialMovement::create([
            'raw_material_id' => $m->id,
            'branch_id' => $branchId,
            'delta' => $delta,
            'reason' => 'sale',
            'source_type' => 'order_item',
            'source_id' => 1,
            'created_at' => $createdAt,
        ]);
    }

    private function boissonCategory(): ItemCategory
    {
        return ItemCategory::create([
            'name' => 'Boissons',
            'slug' => 'boissons-'.Str::random(6),
            'status' => Status::ACTIVE,
        ]);
    }

    private function drink(ItemCategory $cat, string $name, ?int $onHand, ?int $threshold = null): Item
    {
        $item = Item::factory()->create([
            'item_category_id' => $cat->id,
            'status' => Status::ACTIVE,
            'name' => $name,
            'price' => 2,
        ]);

        if ($onHand !== null) {
            StockLevel::query()->create([
                'branch_id' => 1,
                'stockable_type' => Item::class,
                'stockable_id' => $item->id,
                'on_hand' => $onHand,
                'reserved' => 0,
                'threshold_low' => $threshold,
            ]);
        }

        return $item;
    }

    private function drinkOut(Item $item, int $delta, $createdAt): void
    {
        $levelId = (int) StockLevel::query()
            ->where('stockable_type', Item::class)
            ->where('stockable_id', $item->id)
            ->value('id');

        // Sortie de stock = décrément à la création de commande (delta négatif).
        StockMovement::query()->create([
            'stock_level_id' => $levelId,
            'branch_id' => 1,
            'delta' => $delta,
            'reason' => 'order_created',
            'created_at' => $createdAt,
        ]);
    }

    // ── Tests ───────────────────────────────────────────────────────────────

    public function test_overview_returns_both_shelves_and_totals_structure(): void
    {
        $flour = $this->material(['name' => 'Farine', 'unit' => 'g', 'avg_cost' => 0.002, 'threshold_low' => 500]);
        $this->rawStock($flour, 4000);

        $cat = $this->boissonCategory();
        $this->drink($cat, 'Coca 33cl', 24, 6);

        $out = $this->service()->overview(1);

        $this->assertSame(1, $out['branch_id']);
        $this->assertArrayHasKey('raw_materials', $out);
        $this->assertArrayHasKey('resold_products', $out);
        $this->assertArrayHasKey('to_buy', $out);
        $this->assertArrayHasKey('totals', $out);
        $this->assertCount(1, $out['raw_materials']);
        $this->assertCount(1, $out['resold_products']);
        $this->assertSame('Farine', $out['raw_materials'][0]['name']);
        $this->assertSame('Coca 33cl', $out['resold_products'][0]['name']);
        // Farine 4000 × 0.002 = 8.00 € de valeur stock.
        $this->assertSame(8.0, $out['totals']['raw_material_stock_value']);
    }

    public function test_recent_consumption_sums_only_sale_movements_inside_window(): void
    {
        $meat = $this->material(['name' => 'Viande', 'avg_cost' => 0.01]);
        $this->rawStock($meat, 5000);
        $this->rawSale($meat, -100, now()->subDays(2));
        $this->rawSale($meat, -50, now()->subDays(5));
        // Hors fenêtre 30j → exclu.
        $this->rawSale($meat, -9999, now()->subDays(40));

        $row = $this->service()->overview(1)['raw_materials'][0];

        $this->assertSame(150.0, $row['recent_consumption']);
    }

    public function test_avg_cost_is_null_safe_and_flagged_for_banner(): void
    {
        // Matière SANS avg_cost (owner n'a pas posé les prix).
        $noCost = $this->material(['name' => 'Oignons', 'avg_cost' => null]);
        $this->rawStock($noCost, 1000);
        // Matière AVEC coût.
        $withCost = $this->material(['name' => 'Fromage', 'avg_cost' => 0.005]);
        $this->rawStock($withCost, 2000);

        $out = $this->service()->overview(1);
        $rows = collect($out['raw_materials'])->keyBy('name');

        $this->assertFalse($rows['Oignons']['has_cost']);
        $this->assertNull($rows['Oignons']['stock_value']);
        $this->assertTrue($rows['Fromage']['has_cost']);
        $this->assertSame(10.0, $rows['Fromage']['stock_value']); // 2000 × 0.005
        // Bandeau : 1 matière sans coût ; valeur totale = SEULEMENT le coût connu.
        $this->assertSame(1, $out['totals']['missing_cost_count']);
        $this->assertSame(10.0, $out['totals']['raw_material_stock_value']);
    }

    public function test_status_out_low_ok_and_to_buy_section_merges_both_shelves(): void
    {
        // Matière en rupture (on_hand 0).
        $ruptRaw = $this->material(['name' => 'Sel', 'threshold_low' => 100]);
        $this->rawStock($ruptRaw, 0);
        // Matière sous le seuil.
        $lowRaw = $this->material(['name' => 'Poivre', 'threshold_low' => 100]);
        $this->rawStock($lowRaw, 40);
        // Matière OK.
        $okRaw = $this->material(['name' => 'Huile', 'threshold_low' => 100]);
        $this->rawStock($okRaw, 900);

        $cat = $this->boissonCategory();
        $this->drink($cat, 'Fanta', 0, 6);   // rupture boisson
        $this->drink($cat, 'Sprite', 30, 6); // OK boisson

        $out = $this->service()->overview(1);
        $rows = collect($out['raw_materials'])->keyBy('name');

        $this->assertSame('out', $rows['Sel']['status']);
        $this->assertSame('low', $rows['Poivre']['status']);
        $this->assertSame('ok', $rows['Huile']['status']);

        // À acheter = Sel(out) + Poivre(low) + Fanta(out) = 3, Sprite/Huile exclus.
        $names = collect($out['to_buy'])->pluck('name')->all();
        $this->assertCount(3, $out['to_buy']);
        $this->assertContains('Sel', $names);
        $this->assertContains('Poivre', $names);
        $this->assertContains('Fanta', $names);
        $this->assertNotContains('Huile', $names);
        $this->assertNotContains('Sprite', $names);

        // Ruptures triées en tête (out avant low).
        $this->assertSame('out', $out['to_buy'][0]['status']);
        $this->assertSame('low', $out['to_buy'][count($out['to_buy']) - 1]['status']);

        // Totaux cohérents.
        $this->assertSame(2, $out['totals']['out_count']);  // Sel + Fanta
        $this->assertSame(1, $out['totals']['low_count']);  // Poivre
        $this->assertSame(3, $out['totals']['to_buy_count']);
    }

    public function test_resold_drink_consumption_and_exclusions(): void
    {
        $cat = $this->boissonCategory();
        $tracked = $this->drink($cat, 'Ice Tea', 20, 5);
        $this->drinkOut($tracked, -3, now()->subDays(1));
        $this->drinkOut($tracked, -2, now()->subDays(3));
        $this->drinkOut($tracked, -50, now()->subDays(60)); // hors fenêtre

        // Boisson SANS stock_level → hors rayon.
        $this->drink($cat, 'Eau plate', null);
        // Item hors catégorie Boissons → jamais dans le rayon boissons.
        $other = ItemCategory::create(['name' => 'Tacos', 'slug' => 'tacos-'.Str::random(4), 'status' => Status::ACTIVE]);
        $this->drink($other, 'Tacos M', 10, 2);

        $out = $this->service()->overview(1);
        $names = collect($out['resold_products'])->pluck('name')->all();

        $this->assertContains('Ice Tea', $names);
        $this->assertNotContains('Eau plate', $names);
        $this->assertNotContains('Tacos M', $names);

        $row = collect($out['resold_products'])->firstWhere('name', 'Ice Tea');
        $this->assertSame(5, $row['recent_consumption']); // 3 + 2, le 50 hors fenêtre exclu
        $this->assertSame(20, $row['on_hand']);
    }

    public function test_branch_isolation_hard_scope(): void
    {
        // Matière de la branche 2 : ne DOIT jamais apparaître dans l'overview branche 1.
        $foreign = $this->material(['name' => 'Étrangère', 'branch_id' => 2]);
        $this->rawStock($foreign, 999, 2);
        // Matière branche 1.
        $mine = $this->material(['name' => 'Locale', 'branch_id' => 1]);
        $this->rawStock($mine, 10);

        $names = collect($this->service()->overview(1)['raw_materials'])->pluck('name')->all();

        $this->assertContains('Locale', $names);
        $this->assertNotContains('Étrangère', $names);
    }

    public function test_inactive_material_is_excluded(): void
    {
        $active = $this->material(['name' => 'Active', 'is_active' => true]);
        $this->rawStock($active, 10);
        $inactive = $this->material(['name' => 'Inactive', 'is_active' => false]);
        $this->rawStock($inactive, 10);

        $names = collect($this->service()->overview(1)['raw_materials'])->pluck('name')->all();

        $this->assertContains('Active', $names);
        $this->assertNotContains('Inactive', $names);
    }
}
