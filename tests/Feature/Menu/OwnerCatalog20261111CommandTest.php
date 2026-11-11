<?php

namespace Tests\Feature\Menu;

use App\Console\Commands\SyncOwnerCatalog20261111Command as Sync;
use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Support\Menu\IncludedMeatCount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [OWNER CATALOGUE 2026-11-11] 5 Tenders 5 €, Sandwich 2 Viandes 9 €, supplément Façon Cayenne
 * 1,50 €, libellés « frais » / « base fromage et crème fraîche ».
 *
 * Protège, dans l'ordre : les prix et les viandes comprises (rien de facturé deux fois), l'absence
 * d'option sur les tenders (« il n'y a pas de sauce, il n'y a rien »), le Cayenne jamais vendu
 * « façon Cayenne », le Sandwich Classique inchangé, le respect d'une retouche faite en admin et
 * la ré-exécution sans doublon (la commande tourne aussi en production).
 */
class OwnerCatalog20261111CommandTest extends TestCase
{
    use RefreshDatabase;

    private ItemCategory $sandwichs;

    private ItemAttribute $viande1;

    private ItemAttribute $viande2;

    private ItemAttribute $sauce;

    private Item $classique;

    private Item $cayenne;

    private Item $terminator;

    private const MEATS = ['Poulet mariné', 'Viande Hachée', 'Cordon Bleu', 'Tenders'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandwichs = ItemCategory::factory()->create(['name' => 'Sandwichs', 'slug' => 'sandwichs']);
        $this->viande1 = ItemAttribute::factory()->create(['name' => 'Viande 1', 'min_select' => 1, 'max_select' => 1]);
        $this->viande2 = ItemAttribute::factory()->create(['name' => 'Viande 2', 'min_select' => 0, 'max_select' => 1]);
        $this->sauce = ItemAttribute::factory()->create(['name' => 'Sauce (1ère Gratuite)', 'min_select' => 1, 'max_select' => 1]);

        $this->classique = $this->sandwich('Sandwich Classique', 'sandwich-classique', 7.40,
            'Poulet mariné, crudités et sauce au choix, comme le Cayenne — sans le mélange fromager.');
        $this->cayenne = $this->sandwich('Cayenne', 'cayenne', 7.40,
            'Poulet mariné, cheddar, oignons rouges, tomates, salade, mélange maison de fromage et crème fraîche.');
        $this->terminator = $this->sandwich('Terminator', 'terminator', 9.00, '2 viandes au choix, 2 cheddars.');

        foreach (self::MEATS as $meat) {
            $this->variation($this->classique->id, $this->viande1->id, $meat);
        }
        foreach (['Mayonnaise', 'Ketchup'] as $s) {
            $this->variation($this->classique->id, $this->sauce->id, $s);
        }
        $this->extra($this->classique->id, 'Salade', 0.0, 'crudite');
        $this->extra($this->classique->id, 'Cheddar', 0.90, 'supplement');
        $this->extra($this->classique->id, 'Viande supplémentaire', 2.50, 'supplement');
        $this->extra($this->classique->id, 'Sauce supplémentaire', 0.50, 'sauce');

        $formule = Item::factory()->create(['name' => 'Menu (Frites + Boisson)', 'status' => Status::ACTIVE]);
        DB::table('item_addons')->insert([
            'item_id' => $this->classique->id, 'addon_item_id' => $formule->id,
            'addon_item_variation' => null, 'role' => 'menu_component',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Item::factory()->create([
            'name' => 'Cheese Burger', 'slug' => 'cheese-burger', 'status' => Status::ACTIVE,
            'description' => 'Steak, cheddar, salade, tomate, oignon, sauce.',
        ]);
    }

    private function sandwich(string $name, string $slug, float $price, string $description): Item
    {
        return Item::factory()->create([
            'name' => $name, 'slug' => $slug, 'price' => $price, 'description' => $description,
            'item_category_id' => $this->sandwichs->id, 'status' => Status::ACTIVE, 'is_available' => 1,
        ]);
    }

    private function variation(int $itemId, int $attrId, string $name): void
    {
        DB::table('item_variations')->insert([
            'item_id' => $itemId, 'item_attribute_id' => $attrId, 'name' => $name, 'price' => 0,
            'status' => Status::ACTIVE, 'visible_on' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function extra(int $itemId, string $name, float $price, string $group): void
    {
        DB::table('item_extras')->insert([
            'item_id' => $itemId, 'name' => $name, 'price' => $price, 'group_label' => $group,
            'status' => Status::ACTIVE, 'visible_on' => null, 'is_available' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_cinq_tenders_cinq_euros_sans_aucune_option_dans_sa_categorie(): void
    {
        $this->artisan('menu:owner-2026-11-11')->assertSuccessful();

        $tenders = Item::where('slug', Sync::TENDERS_SLUG)->firstOrFail();
        $this->assertSame('5 Tenders', $tenders->name);
        $this->assertEqualsWithDelta(5.00, (float) $tenders->price, 0.0001);
        $this->assertSame(Status::ACTIVE, (int) $tenders->status);
        $this->assertSame('Tenders', ItemCategory::find($tenders->item_category_id)->name);

        $this->assertSame(0, DB::table('item_variations')->where('item_id', $tenders->id)->where('status', Status::ACTIVE)->count());
        $this->assertSame(0, DB::table('item_extras')->where('item_id', $tenders->id)->where('status', Status::ACTIVE)->count());
        $this->assertSame(0, DB::table('item_addons')->where('item_id', $tenders->id)->whereNull('deleted_at')->count());
    }

    public function test_sandwich_deux_viandes_neuf_euros_deux_viandes_comprises_classique_inchange(): void
    {
        $this->artisan('menu:owner-2026-11-11')->assertSuccessful();

        $s2 = Item::where('slug', Sync::SANDWICH2_SLUG)->firstOrFail();
        $this->assertSame('Sandwich 2 Viandes', $s2->name);
        $this->assertEqualsWithDelta(9.00, (float) $s2->price, 0.0001);
        $this->assertSame($this->sandwichs->id, (int) $s2->item_category_id);

        foreach ([$this->viande1->id, $this->viande2->id] as $attr) {
            $meats = DB::table('item_variations')->where('item_id', $s2->id)->where('item_attribute_id', $attr)->get();
            $this->assertEqualsCanonicalizing(self::MEATS, $meats->pluck('name')->all());
            // Les viandes sont DANS les 9,00 € : aucune variation viande ne se facture en plus.
            $this->assertSame(0.0, (float) $meats->sum('price'));
        }
        $this->assertSame(2, IncludedMeatCount::resolve($s2->name, 'Sandwichs', ['Viande 1', 'Viande 2']));

        // Sauces, suppléments (dont la 3e viande payante) et formule menu clonés.
        $this->assertSame(2, DB::table('item_variations')->where('item_id', $s2->id)->where('item_attribute_id', $this->sauce->id)->count());
        $this->assertEqualsWithDelta(2.50, (float) DB::table('item_extras')->where('item_id', $s2->id)->where('name', 'Viande supplémentaire')->value('price'), 0.0001);
        $this->assertSame(1, DB::table('item_addons')->where('item_id', $s2->id)->count());

        // Le Sandwich Classique garde son prix et sa viande unique.
        $this->assertEqualsWithDelta(7.40, (float) $this->classique->fresh()->price, 0.0001);
        $this->assertSame(0, DB::table('item_variations')->where('item_id', $this->classique->id)->where('item_attribute_id', $this->viande2->id)->count());
    }

    public function test_facon_cayenne_un_euro_cinquante_sur_les_sandwichs_sauf_le_cayenne(): void
    {
        $this->artisan('menu:owner-2026-11-11')->assertSuccessful();

        foreach ([$this->classique, $this->terminator, Item::where('slug', Sync::SANDWICH2_SLUG)->first()] as $item) {
            $extra = DB::table('item_extras')->where('item_id', $item->id)->where('name', 'Façon Cayenne')->first();
            $this->assertNotNull($extra, $item->name.' doit proposer Façon Cayenne');
            $this->assertEqualsWithDelta(1.50, (float) $extra->price, 0.0001);
            $this->assertSame('supplement', $extra->group_label);
            if (Schema::hasColumn('item_extras', 'description')) {
                $this->assertSame(Sync::FACON_CAYENNE_DESCRIPTION, $extra->description);
            }
        }
        $this->assertFalse(DB::table('item_extras')->where('item_id', $this->cayenne->id)->where('name', 'Façon Cayenne')->exists());
    }

    public function test_libelles_frais_et_base_fromage_mais_retouche_admin_respectee(): void
    {
        $this->terminator->forceFill(['description' => 'Texte écrit à la main en admin.'])->save();
        $this->classique->forceFill(['description' => 'Retouche du gérant.'])->save();

        $this->artisan('menu:owner-2026-11-11')->assertSuccessful();

        $this->assertSame(Sync::DESCRIPTIONS['cayenne'][1], $this->cayenne->fresh()->description);
        $this->assertStringContainsString('base fromage et crème fraîche', $this->cayenne->fresh()->description);
        $this->assertSame(Sync::DESCRIPTIONS['cheese-burger'][1], Item::where('slug', 'cheese-burger')->value('description'));
        $this->assertSame('Retouche du gérant.', $this->classique->fresh()->description);
        $this->assertSame('Texte écrit à la main en admin.', $this->terminator->fresh()->description);
    }

    public function test_reexecution_sans_doublon(): void
    {
        $this->artisan('menu:owner-2026-11-11')->assertSuccessful();
        $snapshot = [
            DB::table('items')->count(),
            DB::table('item_categories')->count(),
            DB::table('item_variations')->count(),
            DB::table('item_extras')->count(),
            DB::table('item_addons')->count(),
        ];

        $this->artisan('menu:owner-2026-11-11')->assertSuccessful();
        $this->artisan('menu:owner-2026-11-11')->assertSuccessful();

        $this->assertSame($snapshot, [
            DB::table('items')->count(),
            DB::table('item_categories')->count(),
            DB::table('item_variations')->count(),
            DB::table('item_extras')->count(),
            DB::table('item_addons')->count(),
        ]);
    }

    public function test_dry_run_n_ecrit_rien(): void
    {
        $before = [DB::table('items')->count(), DB::table('item_extras')->count(), DB::table('item_categories')->count()];

        $this->artisan('menu:owner-2026-11-11', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, [DB::table('items')->count(), DB::table('item_extras')->count(), DB::table('item_categories')->count()]);
    }
}
