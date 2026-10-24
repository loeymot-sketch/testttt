<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\RawMaterial;
use App\Models\RawMaterialRecipeLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [OWNER 2026-10-21] Le Cayenne ne contient PAS de jambon. La fiche (borne, caisse) le disait encore, et la recette de stock,
 * déduite de cette description, décomptait une tranche de jambon par sandwich vendu.
 *
 * @group menu
 */
class CayenneDescriptionSansJambonTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_21_100000_cayenne_description_sans_jambon.php');
    }

    private function cayenne(string $description): Item
    {
        $cat = ItemCategory::factory()->create(['name' => 'Sandwichs']);

        return Item::factory()->create(['name' => 'Cayenne', 'item_category_id' => $cat->id, 'description' => $description, 'status' => Status::ACTIVE]);
    }

    public function test_the_description_loses_the_ham_and_the_stock_recipe_stops_consuming_it(): void
    {
        $branch = Branch::factory()->create();
        $item = $this->cayenne('Poulet mariné, cheddar, jambon, oignons rouges, tomates, salade, sauce fromagère maison.');
        $jambon = RawMaterial::query()->create(['branch_id' => $branch->id, 'name' => 'Jambon', 'unit' => 'pièce', 'is_active' => true]);
        $cheddar = RawMaterial::query()->create(['branch_id' => $branch->id, 'name' => 'Cheddar', 'unit' => 'pièce', 'is_active' => true]);
        foreach ([$jambon, $cheddar] as $rm) {
            RawMaterialRecipeLine::query()->create(['branch_id' => $branch->id, 'subject_type' => Item::class, 'subject_id' => $item->id, 'raw_material_id' => $rm->id, 'qty' => 1]);
        }

        $this->migration()->up();

        $fresh = DB::table('items')->where('id', $item->id)->value('description');
        $this->assertStringNotContainsStringIgnoringCase('jambon', $fresh);
        $this->assertStringContainsString('fromage et crème fraîche', $fresh);
        $this->assertSame(0, DB::table('raw_material_recipe_lines')->where('subject_id', $item->id)->where('raw_material_id', $jambon->id)->count());
        $this->assertSame(1, DB::table('raw_material_recipe_lines')->where('subject_id', $item->id)->where('raw_material_id', $cheddar->id)->count(), 'les autres matières ne bougent pas');
    }

    public function test_an_owner_edited_description_is_never_overwritten_and_the_migration_is_idempotent(): void
    {
        $item = $this->cayenne('Ma recette à moi, sans rien de tout ça.');

        $this->migration()->up();
        $this->assertSame('Ma recette à moi, sans rien de tout ça.', DB::table('items')->where('id', $item->id)->value('description'));

        $item->update(['description' => 'Poulet mariné, jambon.']);
        $this->migration()->up();
        $this->migration()->up();
        $this->assertStringNotContainsStringIgnoringCase('jambon', DB::table('items')->where('id', $item->id)->value('description'));
    }

    public function test_other_items_mentioning_ham_are_left_alone(): void
    {
        $cat = ItemCategory::factory()->create(['name' => 'Sandwichs']);
        $terminator = Item::factory()->create(['name' => 'Terminator', 'item_category_id' => $cat->id, 'description' => 'Deux viandes, un œuf et du jambon de dinde.', 'status' => Status::ACTIVE]);

        $this->migration()->up();

        $this->assertStringContainsString('jambon de dinde', DB::table('items')->where('id', $terminator->id)->value('description'));
    }
}
