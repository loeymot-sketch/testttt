<?php

namespace Tests\Feature\Composition;

use App\Console\Commands\EnsureSauceSupplementExtrasCommand;
use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [COMPOSITION-SAUCE 2026-07-16] Verrou : chaque item à attribut sauce DOIT porter l'ItemExtra
 * « Sauce supplémentaire » @0,50 (group='sauce'), sinon la 2e sauce est larguée à l'envoi
 * (non facturée + absente du ticket sur borne/web). Régression du trou laissé par 766249da5.
 */
class SauceSupplementCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function makeSauceItem(): Item
    {
        $cat = ItemCategory::factory()->create();
        $tax = Tax::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $cat->id, 'tax_id' => $tax->id, 'status' => Status::ACTIVE]);

        $sauceAttr = ItemAttribute::factory()->create([
            'name' => 'Sauce (1ère Gratuite)',
            'min_select' => 1,
            'max_select' => 1,
            'status' => Status::ACTIVE,
        ]);

        DB::table('item_variations')->insert([
            'item_id' => $item->id,
            'item_attribute_id' => $sauceAttr->id,
            'name' => 'Ketchup',
            'price' => 0,
            'status' => Status::ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $item;
    }

    public function test_creates_sauce_supplement_extra_for_a_sauce_item(): void
    {
        $item = $this->makeSauceItem();

        $created = EnsureSauceSupplementExtrasCommand::ensure();

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('item_extras', [
            'item_id' => $item->id,
            'name' => 'Sauce supplémentaire',
            'group_label' => 'sauce',
            'status' => Status::ACTIVE,
        ]);
        $price = (float) DB::table('item_extras')
            ->where('item_id', $item->id)->where('name', 'Sauce supplémentaire')->value('price');
        $this->assertEqualsWithDelta(0.50, $price, 0.001);
    }

    public function test_does_not_touch_items_without_a_sauce_attribute(): void
    {
        $cat = ItemCategory::factory()->create();
        $tax = Tax::factory()->create();
        $plain = Item::factory()->create(['item_category_id' => $cat->id, 'tax_id' => $tax->id, 'status' => Status::ACTIVE]);

        // Attribut non-sauce → aucun extra sauce
        $painAttr = ItemAttribute::factory()->create(['name' => 'Pain', 'status' => Status::ACTIVE]);
        DB::table('item_variations')->insert([
            'item_id' => $plain->id, 'item_attribute_id' => $painAttr->id,
            'name' => 'Faluche', 'price' => 0, 'status' => Status::ACTIVE,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        EnsureSauceSupplementExtrasCommand::ensure();

        $this->assertDatabaseMissing('item_extras', [
            'item_id' => $plain->id,
            'name' => 'Sauce supplémentaire',
        ]);
    }

    public function test_is_idempotent_no_duplicate_on_rerun(): void
    {
        $item = $this->makeSauceItem();

        $this->assertSame(1, EnsureSauceSupplementExtrasCommand::ensure());
        $this->assertSame(0, EnsureSauceSupplementExtrasCommand::ensure(), 'second run must create nothing');

        $count = (int) DB::table('item_extras')
            ->where('item_id', $item->id)->where('name', 'Sauce supplémentaire')->count();
        $this->assertSame(1, $count, 'no duplicate Sauce supplémentaire');
    }

    public function test_dry_run_counts_without_writing(): void
    {
        $item = $this->makeSauceItem();

        $count = EnsureSauceSupplementExtrasCommand::ensure(true);

        $this->assertSame(1, $count);
        $this->assertDatabaseMissing('item_extras', [
            'item_id' => $item->id,
            'name' => 'Sauce supplémentaire',
        ]);
    }
}
