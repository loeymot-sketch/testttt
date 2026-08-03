<?php

namespace Tests\Feature\RawMaterials;

use App\Console\Commands\RawMaterialFoodCostCommand;
use App\Enums\OrderStatus;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RawMaterial;
use App\Models\RawMaterialMovement;
use App\Models\RawMaterialRecipeLine;
use App\Models\RawMaterialStock;
use App\Models\User;
use App\Services\RawMaterials\FoodCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P2b] Rejeu de conso sur l'historique
 * (`raw-materials:replay-consumption`) + food cost (`FoodCostService` +
 * `raw-materials:food-cost`).
 *
 * Prouve : rejeu idempotent (2 runs = même stock), exclusion annulées/refusées/
 * retournées, dry-run n'écrit rien, fenêtre de dates, coût produit NULL-safe
 * (avg_cost NULL → coût inconnu flaggé, jamais 0 trompeur), marge correcte quand
 * les coûts sont connus, rapport food cost généré non-vide avec « en attente prix ».
 *
 * NF525 : domaine ADDITIF — lit les snapshots, aucune assertion fiscale.
 */
class FoodCostAndReplayTest extends TestCase
{
    use RefreshDatabase;

    private ItemCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = ItemCategory::create([
            'name' => 'Sandwichs',
            'slug' => 'sandwichs-'.Str::random(6),
            'status' => Status::ACTIVE,
        ]);

        // Rapport propre entre les tests (chemin partagé dans le repo).
        $path = base_path(RawMaterialFoodCostCommand::REPORT_PATH);
        if (File::exists($path)) {
            File::delete($path);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function material(string $name, string $unit = 'g', ?float $avgCost = null): RawMaterial
    {
        return RawMaterial::create([
            'branch_id' => 1,
            'name' => $name,
            'unit' => $unit,
            'avg_cost' => $avgCost,
            'is_active' => true,
        ]);
    }

    private function recipe(string $subjectType, int $subjectId, RawMaterial $mat, float $qty, ?string $group = null): RawMaterialRecipeLine
    {
        return RawMaterialRecipeLine::create([
            'branch_id' => 1,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_group' => $group,
            'raw_material_id' => $mat->id,
            'qty' => $qty,
        ]);
    }

    private function makeItem(string $name, float $price = 10.0): Item
    {
        return Item::forceCreate([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'item_category_id' => $this->category->id,
            'item_type' => 1,
            'price' => $price,
            'status' => Status::ACTIVE,
        ]);
    }

    private function makeOrder(int $status = OrderStatus::PENDING): Order
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        return Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'status' => $status,
        ]);
    }

    /** @param array<string,mixed> $snapshot */
    private function makeOrderItem(Order $order, Item $item, int $qty, array $snapshot = []): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id,
            'branch_id' => 1,
            'item_id' => $item->id,
            'quantity' => $qty,
            'price' => 10,
            'discount' => 0,
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'total_price' => 10 * $qty,
            'item_variations' => '[]',
            'item_extras' => '[]',
            'composition_snapshot' => $snapshot ?: ['lines' => [], 'extras' => [], 'addons' => []],
            'instruction' => '',
        ]);
    }

    private function onHand(RawMaterial $mat): float
    {
        return (float) RawMaterialStock::where('raw_material_id', $mat->id)->value('on_hand');
    }

    // ══ REPLAY ════════════════════════════════════════════════════════════════

    // ── 1. Rejeu 2× = même stock (idempotent par order_item.id). ──────────────
    public function test_replay_consumption_is_idempotent(): void
    {
        $poulet = $this->material('Poulet');
        $item = $this->makeItem('Cayenne');
        $this->recipe(Item::class, $item->id, $poulet, 200);

        $order = $this->makeOrder(OrderStatus::PENDING);
        $this->makeOrderItem($order, $item, 1);

        $this->artisan('raw-materials:replay-consumption')->assertExitCode(0);
        $this->artisan('raw-materials:replay-consumption')->assertExitCode(0); // 2ᵉ run

        $this->assertEqualsWithDelta(-200.0, $this->onHand($poulet), 0.001); // pas -400
        $this->assertSame(1, RawMaterialMovement::where('raw_material_id', $poulet->id)->count());
    }

    // ── 2. Exclut annulées / refusées / retournées. ──────────────────────────
    public function test_replay_excludes_canceled_rejected_returned_orders(): void
    {
        $poulet = $this->material('Poulet');
        $item = $this->makeItem('Cayenne');
        $this->recipe(Item::class, $item->id, $poulet, 200);

        $this->makeOrderItem($this->makeOrder(OrderStatus::DELIVERED), $item, 1); // vente réelle
        $this->makeOrderItem($this->makeOrder(OrderStatus::CANCELED), $item, 1);
        $this->makeOrderItem($this->makeOrder(OrderStatus::REJECTED), $item, 1);
        $this->makeOrderItem($this->makeOrder(OrderStatus::RETURNED), $item, 1);

        $this->artisan('raw-materials:replay-consumption')->assertExitCode(0);

        // Seule la commande DELIVERED consomme → -200, pas -800.
        $this->assertEqualsWithDelta(-200.0, $this->onHand($poulet), 0.001);
        $this->assertSame(1, RawMaterialMovement::where('raw_material_id', $poulet->id)->count());
    }

    // ── 3. Dry-run n'écrit rien ; le run réel écrit ensuite. ──────────────────
    public function test_dry_run_writes_nothing_then_real_run_writes(): void
    {
        $poulet = $this->material('Poulet');
        $item = $this->makeItem('Cayenne');
        $this->recipe(Item::class, $item->id, $poulet, 200);

        $order = $this->makeOrder(OrderStatus::PENDING);
        $this->makeOrderItem($order, $item, 1);

        $this->artisan('raw-materials:replay-consumption', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame(0, RawMaterialMovement::count(), 'dry-run ne doit rien écrire');
        $this->assertSame(0, RawMaterialStock::count(), 'dry-run ne doit créer aucun stock');

        // Preuve que le dry-run supprimait un vrai travail : le run réel écrit.
        $this->artisan('raw-materials:replay-consumption')->assertExitCode(0);
        $this->assertEqualsWithDelta(-200.0, $this->onHand($poulet), 0.001);
        $this->assertSame(1, RawMaterialMovement::count());
    }

    // ── 4. Fenêtre --from : ignore les commandes hors période. ────────────────
    public function test_replay_respects_from_date_window(): void
    {
        $poulet = $this->material('Poulet');
        $item = $this->makeItem('Cayenne');
        $this->recipe(Item::class, $item->id, $poulet, 200);

        $old = $this->makeOrder(OrderStatus::DELIVERED);
        $this->makeOrderItem($old, $item, 1);
        DB::table('orders')->where('id', $old->id)->update(['created_at' => now()->subDays(10)]);

        $recent = $this->makeOrder(OrderStatus::DELIVERED);
        $this->makeOrderItem($recent, $item, 1);

        $from = now()->subDays(2)->format('Y-m-d');
        $this->artisan('raw-materials:replay-consumption', ['--from' => $from])->assertExitCode(0);

        // Seule la commande récente (dans la fenêtre) est consommée → -200, pas -400.
        $this->assertEqualsWithDelta(-200.0, $this->onHand($poulet), 0.001);
    }

    // ══ FOOD COST ═════════════════════════════════════════════════════════════

    // ── 5. Coût NULL-safe : avg_cost NULL → inconnu flaggé, jamais 0 trompeur. ─
    public function test_cost_for_product_is_null_safe_when_avg_cost_missing(): void
    {
        $poulet = $this->material('Poulet', 'g', null); // prix d'achat non saisi
        $item = $this->makeItem('Cayenne', 10.0);
        $this->recipe(Item::class, $item->id, $poulet, 200);

        $r = app(FoodCostService::class)->costForProduct($item);

        $this->assertTrue($r['has_unknown_cost']);
        $this->assertNull($r['margin'], 'aucune fausse marge sur coût inconnu');
        $this->assertNull($r['margin_pct']);
        // La ligne ne présente pas un coût inconnu comme 0-gratuit.
        $this->assertNull($r['lines'][0]['unit_cost']);
        $this->assertNull($r['lines'][0]['line_cost']);
    }

    // ── 6. Marge correcte quand tous les coûts sont connus. ───────────────────
    public function test_cost_for_product_computes_margin_when_costs_known(): void
    {
        // Poulet 0,004 €/g × 200 = 0,80 € ; Pain 0,30 €/pièce × 1 = 0,30 € → 1,10 €.
        $poulet = $this->material('Poulet', 'g', 0.004);
        $pain = $this->material('Pain', 'piece', 0.30);
        $item = $this->makeItem('Cayenne', 10.0);
        $this->recipe(Item::class, $item->id, $poulet, 200);
        $this->recipe(Item::class, $item->id, $pain, 1);

        $r = app(FoodCostService::class)->costForProduct($item);

        $this->assertFalse($r['has_unknown_cost']);
        $this->assertEqualsWithDelta(1.10, $r['material_cost'], 0.0001);
        $this->assertEqualsWithDelta(8.90, $r['margin'], 0.0001);   // 10 - 1,10
        $this->assertEqualsWithDelta(89.0, $r['margin_pct'], 0.01); // 8,90 / 10 × 100
    }

    // ── 7. Produit sans recette = flaggé, pas de fausse marge 100 %. ──────────
    public function test_cost_for_product_without_recipe_is_flagged_not_free(): void
    {
        $item = $this->makeItem('Boisson Coca', 2.5);

        $r = app(FoodCostService::class)->costForProduct($item);

        $this->assertFalse($r['has_recipe']);
        $this->assertNull($r['margin'], 'un produit sans recette n\'a pas 100 % de marge');
        $this->assertSame([], $r['lines']);
    }

    // ── 8. Rapport food cost généré, non-vide, « en attente prix » attendu. ───
    public function test_food_cost_report_is_generated_non_empty_with_pending_flag(): void
    {
        $poulet = $this->material('Poulet', 'g', null); // avg_cost NULL → en attente
        $item = $this->makeItem('Cayenne', 10.0);
        $this->recipe(Item::class, $item->id, $poulet, 200);

        $this->artisan('raw-materials:food-cost')->assertExitCode(0);

        $path = base_path(RawMaterialFoodCostCommand::REPORT_PATH);
        $this->assertTrue(File::exists($path), 'Le rapport food cost doit être écrit.');

        $content = File::get($path);
        $this->assertNotEmpty($content);
        $this->assertStringContainsString('Cayenne', $content);
        $this->assertStringContainsString('en attente prix', $content); // matière avg_cost NULL
    }
}
