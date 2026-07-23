<?php

namespace Tests\Feature\RawMaterials;

use App\Console\Commands\RawMaterialFicheCommand;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RawMaterial;
use App\Models\RawMaterialRecipeLine;
use App\Models\RawMaterialStock;
use App\Models\User;
use App\Services\RawMaterials\RawMaterialConsumptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P1b] Commande `raw-materials:fiche`.
 *
 * Source = DB LOCALE via Eloquent : on sème un catalogue réaliste (catégories +
 * items ACTIFS, noms/descriptions miroir de nos vrais produits) et on prouve :
 * commande OK, fiche écrite non-vide (>20 produits couverts), lignes de recette
 * upsertées avec le bon prefill, baseline auto-semée, double run idempotent.
 *
 * NF525 : domaine additif — aucune assertion fiscale (ne touche pas la chaîne).
 */
class RawMaterialFicheCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
        // Fiche propre entre les tests (chemin partagé dans le repo).
        $path = base_path(RawMaterialFicheCommand::FICHE_PATH);
        if (File::exists($path)) {
            File::delete($path);
        }
    }

    // ── 1. Commande OK (via artisan) + fiche écrite et non-vide. ───────────────
    public function test_command_runs_via_artisan_and_writes_a_non_empty_fiche(): void
    {
        $this->artisan('raw-materials:fiche')->assertExitCode(0);

        $path = base_path(RawMaterialFicheCommand::FICHE_PATH);
        $this->assertTrue(File::exists($path), 'La fiche doit être écrite.');

        $content = File::get($path);
        $this->assertNotEmpty($content);
        $this->assertStringContainsString('# Fiche paramètres', $content);
        $this->assertStringContainsString('À CONFIRMER', $content);
        $this->assertStringContainsString('Sandwichs', $content);
        // Rappel gravé owner présent.
        $this->assertStringContainsString('75 g', $content);
    }

    // ── 2. Fiche couvre > 20 produits. ────────────────────────────────────────
    public function test_fiche_covers_more_than_twenty_products(): void
    {
        $r = RawMaterialFicheCommand::generate();

        $this->assertGreaterThan(
            20,
            $r['products'] + $r['unitaire'],
            'La fiche doit couvrir plus de 20 produits (pré-remplis + à l\'unité).'
        );

        // Contrôle croisé sur le fichier : chaque produit du catalogue y figure.
        $content = File::get(base_path(RawMaterialFicheCommand::FICHE_PATH));
        $found = Item::query()->where('status', Status::ACTIVE)->pluck('name')
            ->filter(fn ($n) => str_contains($content, $n))->count();
        $this->assertGreaterThan(20, $found, 'Plus de 20 produits nommés dans la fiche.');
    }

    // ── 3. Lignes de recette upsertées avec le prefill attendu. ───────────────
    public function test_recipe_lines_are_upserted_with_expected_prefill(): void
    {
        RawMaterialFicheCommand::generate();

        $this->assertGreaterThan(0, RawMaterialRecipeLine::count(), 'Des lignes doivent être créées.');

        // Cayenne (Sandwich, "Poulet mariné, cheddar, jambon") → pain + sauce + poulet + cheddar + jambon + crudités.
        $cayenne = $this->materialsFor('Cayenne');
        $this->assertContains('Pain', $cayenne);
        $this->assertContains('Sauce maison', $cayenne);
        $this->assertContains('Poulet', $cayenne);
        $this->assertContains('Cheddar', $cayenne);
        $this->assertContains('Jambon', $cayenne);
        $this->assertContains('Salade', $cayenne);

        // Tacos → galette (pas pain) + pas de frites en P1.
        $tacos = $this->materialsFor('Tacos L');
        $this->assertContains('Galette', $tacos);
        $this->assertNotContains('Pain', $tacos);

        // Frites → portion frites + sauce (pot).
        $frites = $this->materialsFor('Grande Frites');
        $this->assertContains('Portion frites', $frites);
        $this->assertContains('Sauce maison', $frites);

        // Boisson → AUCUNE ligne matière (comptée à l'unité).
        $coca = Item::where('name', 'Coca-Cola 33cl')->first();
        $this->assertSame(
            0,
            RawMaterialRecipeLine::where('subject_type', Item::class)->where('subject_id', $coca->id)->count()
        );
    }

    // ── 4. Double run idempotent (même nombre de lignes). ─────────────────────
    public function test_double_run_is_idempotent_same_line_count(): void
    {
        RawMaterialFicheCommand::generate();
        $first = RawMaterialRecipeLine::count();

        RawMaterialFicheCommand::generate();
        $second = RawMaterialRecipeLine::count();

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first, $second, 'Le double run ne doit pas dupliquer les lignes.');
    }

    // ── 5. Baseline matières auto-semée si absente. ───────────────────────────
    public function test_baseline_is_seeded_when_no_raw_materials_exist(): void
    {
        $this->assertSame(0, RawMaterial::count());

        RawMaterialFicheCommand::generate();

        // 12 baseline + Œuf (référencée par 29 extras réels — B-2) = 13.
        $this->assertSame(13, RawMaterial::where('branch_id', 1)->count());
        $this->assertGreaterThan(0, RawMaterialRecipeLine::count());
    }

    // ══ B-2 — Extras : les suppléments payants connus décrémentent le stock ════

    // ── 6. La fiche crée des lignes de recette EXTRAS (mappées par subject_group). ─
    public function test_fiche_creates_extra_group_recipe_lines(): void
    {
        RawMaterialFicheCommand::generate();

        // Cheddar → 1 Cheddar pièce.
        $cheddar = RawMaterialRecipeLine::where('subject_group', 'cheddar')->with('rawMaterial')->first();
        $this->assertNotNull($cheddar, 'Ligne extra "cheddar" attendue.');
        $this->assertSame('Cheddar', $cheddar->rawMaterial->name);
        $this->assertEqualsWithDelta(1.0, (float) $cheddar->qty, 0.001);
        // subject_type marqueur dédié (jamais ItemExtra::class → pas de collision id réel).
        $this->assertSame('extra_group', $cheddar->subject_type);

        // Sauce supplémentaire → Sauce maison 25 g.
        $sauce = RawMaterialRecipeLine::where('subject_group', 'sauce supplémentaire')->with('rawMaterial')->first();
        $this->assertNotNull($sauce);
        $this->assertSame('Sauce maison', $sauce->rawMaterial->name);
        $this->assertEqualsWithDelta(25.0, (float) $sauce->qty, 0.001);

        // Viande supplémentaire → Viande hachée 75 g (mix moyen assumé).
        $viande = RawMaterialRecipeLine::where('subject_group', 'viande supplémentaire')->with('rawMaterial')->first();
        $this->assertNotNull($viande);
        $this->assertSame('Viande hachée', $viande->rawMaterial->name);
        $this->assertEqualsWithDelta(75.0, (float) $viande->qty, 0.001);

        // Œuf (ligature) → matière Œuf.
        $oeuf = RawMaterialRecipeLine::where('subject_group', 'œuf')->with('rawMaterial')->first();
        $this->assertNotNull($oeuf, 'Ligne extra "œuf" attendue.');
        $this->assertSame('Œuf', $oeuf->rawMaterial->name);

        // Section Extras rendue dans la fiche.
        $content = File::get(base_path(RawMaterialFicheCommand::FICHE_PATH));
        $this->assertStringContainsString('Extras', $content);
    }

    // ── 7. Commande avec extra Cheddar → décompte 1 Cheddar (plus dans skipped). ─
    public function test_order_with_cheddar_extra_decrements_one_cheddar(): void
    {
        RawMaterialFicheCommand::generate(); // matières (dont Œuf) + lignes extras

        $coca = Item::where('name', 'Coca-Cola 33cl')->firstOrFail(); // aucune recette produit
        $order = $this->makeOrder();
        $this->makeOrderItem($order, $coca, 1, [
            'lines' => [],
            'extras' => [['extra_id' => 12345, 'extra_name' => 'Cheddar', 'quantity' => 1]],
            'addons' => [],
        ]);

        $summary = app(RawMaterialConsumptionService::class)->consumeForOrder($order);

        $cheddar = RawMaterial::where('name', 'Cheddar')->firstOrFail();
        $this->assertEqualsWithDelta(
            -1.0,
            (float) RawMaterialStock::where('raw_material_id', $cheddar->id)->value('on_hand'),
            0.001
        );
        $this->assertSame([], $summary['skipped']); // l'extra a été RÉSOLU (plus skippé)
        $this->assertCount(1, $summary['consumed']);
    }

    // ── 8. La baseline sème la matière Œuf (référencée par 29 extras réels). ───
    public function test_baseline_seeds_oeuf_material(): void
    {
        RawMaterialFicheCommand::generate();

        $oeuf = RawMaterial::where('branch_id', 1)->where('name', 'Œuf')->first();
        $this->assertNotNull($oeuf, 'La matière Œuf doit être semée.');
        $this->assertSame('piece', $oeuf->unit);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeOrder(): Order
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        return Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
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

    /** Noms de matières attachées à un produit (par nom). */
    private function materialsFor(string $itemName): array
    {
        $item = Item::where('name', $itemName)->firstOrFail();

        return RawMaterialRecipeLine::query()
            ->where('subject_type', Item::class)
            ->where('subject_id', $item->id)
            ->with('rawMaterial:id,name')
            ->get()
            ->map(fn ($l) => $l->rawMaterial?->name)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Catalogue réaliste (miroir de nos vrais produits, source DB uniquement).
     * 27 items actifs : bread/frites (recette) + unitaire (boissons/desserts/kids/bols).
     */
    private function seedCatalog(): void
    {
        $catalog = [
            'Sandwichs' => [
                ['Cayenne', 'Poulet mariné, cheddar, jambon, oignons rouges, sauce.'],
                ['Suprême', 'Steak haché, cordon bleu, cheddar, oignons rouges, sauce.'],
                ['Méga', '2 viandes au choix, cheddar, œuf, oignons rouges.'],
            ],
            'Galette' => [
                ['Galette Normale', 'Galette dorée et croustillante, garnie à votre choix.'],
                ['Galette Cayenne', 'Galette croustillante, sauce Cayenne maison.'],
            ],
            'Burgers' => [
                ['Chicken Burger', 'Salade, tomate, oignon, sauce.'],
                ['Cheese Burger', 'Steak, cheddar, salade, tomate, oignon, sauce.'],
                ['Double Cheese', '2 steaks, 2 cheddars, salade, tomate, oignon, sauce.'],
                ['Fish Burger', 'Poisson pané, cheddar, salade, tomate, oignon, sauce.'],
            ],
            'Tacos' => [
                ['Tacos M', 'Galette de blé, 1 viande au choix, frites maison, sauce.'],
                ['Tacos L', 'Galette de blé, 2 viandes au choix, frites maison, sauce.'],
            ],
            'Frites' => [
                ['Petite Frites', 'Frites maison croustillantes.'],
                ['Grande Frites', 'Grandes frites maison croustillantes.'],
                ['Petite Frites Cheddar fondu', 'Petite Frites Cheddar fondu maison.'],
                ['Grande Frites Cheddar fondu', 'Grande Frites Cheddar fondu maison.'],
            ],
            'Boissons' => [
                ['Coca-Cola 33cl', 'Coca-Cola original'],
                ['Fanta Orange 33cl', 'Fanta Orange'],
                ['Sprite 33cl', 'Sprite'],
                ['Oasis Tropical 33cl', 'Oasis Tropical'],
                ['Eau Plate 50cl', 'Eau minérale'],
            ],
            'Desserts' => [
                ['Glace', 'Glace artisanale.'],
                ['Tiramisu', 'Tiramisu maison.'],
                ['Tarte Daim', 'Tarte gourmande au Daim.'],
            ],
            'Menu enfant' => [
                ['Menu Enfant Nuggets', '6 nuggets, frites et Capri-Sun.'],
                ['Menu Enfant Chicken Burger', 'Chicken burger, frites et Capri-Sun.'],
            ],
            'Bols' => [
                ['Bol Frites', 'Frites maison, viande au choix, sauce et suppléments.'],
                ['Bol Riz', 'Riz, viande au choix, sauce et suppléments.'],
            ],
        ];

        $order = 0;
        foreach ($catalog as $catName => $items) {
            $category = ItemCategory::create([
                'name' => $catName,
                'slug' => Str::slug($catName).'-'.Str::random(4),
                'status' => Status::ACTIVE,
            ]);

            foreach ($items as [$name, $desc]) {
                Item::create([
                    'name' => $name,
                    'slug' => Str::slug($name).'-'.Str::random(4),
                    'item_category_id' => $category->id,
                    'item_type' => 1,
                    'price' => 10.00,
                    'description' => $desc,
                    'status' => Status::ACTIVE,
                    'order' => ++$order,
                ]);
            }
        }
    }
}
