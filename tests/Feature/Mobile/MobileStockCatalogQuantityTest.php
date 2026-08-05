<?php

namespace Tests\Feature\Mobile;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\StockLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [F4 2026-07-24] /m (stock mobile PIN-gated) doit afficher la quantité théorique
 * (stock_levels.on_hand) À CÔTÉ du produit quand elle existe en base, pour que
 * l'owner voie « 12 en stock » sans deviner. Source : reports/goal-global-validation-2026-07-24/
 * ACCES-cuisine-mobile-findings.md (F4).
 *
 * Règles : on EXPOSE ce qui est déjà en base (aucun recalcul recette/matière),
 * NULL-safe (rien si aucun row), et l'isolation mono-branche est préservée
 * (withoutGlobalScope(BranchScope) + where branch_id explicite sur cette surface
 * sans utilisateur authentifié). HORS NF525 (stock uniquement).
 */
class MobileStockCatalogQuantityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ItemCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        config(['mobile_stock.pin' => '2580']);

        $this->branch = Branch::factory()->create();
        $this->category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
    }

    private function unlock(): void
    {
        $this->postJson('/m/api/pin', ['pin' => '2580'])->assertOk();
    }

    private function makeItem(string $name): Item
    {
        return Item::factory()->create([
            'item_category_id' => $this->category->id,
            'status' => Status::ACTIVE,
            'is_available' => true,
            'name' => $name,
        ]);
    }

    /**
     * @param  array<int, array{items: array<int, array<string, mixed>>}>  $categories
     * @return array<string, mixed>
     */
    private function findItem(array $categories, string $name): array
    {
        foreach ($categories as $cat) {
            foreach ($cat['items'] as $it) {
                if (($it['name'] ?? null) === $name) {
                    return $it;
                }
            }
        }
        $this->fail("Item {$name} introuvable dans le catalog.");
    }

    /** (1) Un item avec un row stock_levels (stockable=Item) expose on_hand = la quantité en base. */
    public function test_catalog_exposes_on_hand_when_stock_level_row_exists(): void
    {
        $item = $this->makeItem('Tacos');
        StockLevel::query()->create([
            'branch_id' => $this->branch->id,
            'stockable_type' => Item::class,
            'stockable_id' => $item->id,
            'on_hand' => 12,
            'reserved' => 0,
        ]);
        $this->unlock();

        $res = $this->getJson('/m/api/catalog')->assertOk();
        $tile = $this->findItem($res->json('categories'), 'Tacos');

        $this->assertArrayHasKey('on_hand', $tile);
        $this->assertSame(12, $tile['on_hand']);
    }

    /** (2) NULL-safe : un item SANS row stock_levels expose on_hand = null (rien affiché). */
    public function test_catalog_on_hand_is_null_when_no_stock_row(): void
    {
        $this->makeItem('Panini');
        $this->unlock();

        $res = $this->getJson('/m/api/catalog')->assertOk();
        $tile = $this->findItem($res->json('categories'), 'Panini');

        $this->assertArrayHasKey('on_hand', $tile);
        $this->assertNull($tile['on_hand']);
    }

    /** (3) Isolation : le stock d'une AUTRE branche ne fuit pas dans le catalog mono-branche. */
    public function test_catalog_on_hand_does_not_leak_other_branch(): void
    {
        $item = $this->makeItem('Burger');
        $other = Branch::factory()->create();
        StockLevel::query()->create([
            'branch_id' => $other->id,
            'stockable_type' => Item::class,
            'stockable_id' => $item->id,
            'on_hand' => 99,
            'reserved' => 0,
        ]);
        $this->unlock();

        $res = $this->getJson('/m/api/catalog')->assertOk();
        $tile = $this->findItem($res->json('categories'), 'Burger');

        $this->assertNull($tile['on_hand'], 'Le stock d’une autre branche ne doit pas apparaître.');
    }
}
