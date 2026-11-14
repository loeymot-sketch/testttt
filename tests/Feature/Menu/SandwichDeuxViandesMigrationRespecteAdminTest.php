<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [SUPERVISION 2026-11-13] La migration 2026_11_11_220000 (viandes du Sandwich 2 Viandes) rejouait TOUTE la commande
 * menu:owner-2026-11-11 : en production, où la 040000 a déjà tourné le 11/11, le déploiement aurait réimposé le prix, le
 * statut et la disponibilité du Sandwich 2 Viandes et des 5 Tenders, le prix du supplément Façon Cayenne, et désactivé les
 * options ajoutées à la main sur les Tenders — c'est-à-dire écrasé ce que le propriétaire a retouché en admin depuis.
 * Elle ne doit faire QUE son travail : ajouter les viandes du Terminator.
 */
class SandwichDeuxViandesMigrationRespecteAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_migration_ajoute_les_viandes_sans_ecraser_les_retouches_admin(): void
    {
        $cat = ItemCategory::factory()->create(['name' => 'Sandwichs', 'slug' => 'sandwichs']);
        $v1 = ItemAttribute::factory()->create(['name' => 'Viande 1']);
        $v2 = ItemAttribute::factory()->create(['name' => 'Viande 2']);
        foreach (['cayenne' => 'Cayenne', 'sandwich-classique' => 'Sandwich Classique', 'terminator' => 'Terminator'] as $slug => $name) {
            $it = Item::factory()->create(['name' => $name, 'slug' => $slug, 'price' => 7.4, 'item_category_id' => $cat->id, 'status' => Status::ACTIVE, 'is_available' => 1]);
            DB::table('item_variations')->insert(['item_id' => $it->id, 'item_attribute_id' => $v1->id, 'name' => 'Poulet mariné', 'price' => 0, 'status' => Status::ACTIVE, 'visible_on' => null, 'created_at' => now(), 'updated_at' => now()]);
        }
        $terminator = Item::where('slug', 'terminator')->first();
        foreach ([$v1, $v2] as $attr) {
            DB::table('item_variations')->insert(['item_id' => $terminator->id, 'item_attribute_id' => $attr->id, 'name' => 'Viande Hachée', 'price' => 0, 'status' => Status::ACTIVE, 'visible_on' => null, 'created_at' => now(), 'updated_at' => now()]);
        }

        // 1) État de la production : la 040000 a joué la commande le 11/11, AVANT que les viandes du Terminator n'existent.
        $this->artisan('menu:owner-2026-11-11')->assertSuccessful();
        $s2 = Item::where('slug', 'sandwich-2-viandes')->firstOrFail();
        DB::table('item_variations')->where('item_id', $s2->id)->where('name', 'Viande Hachée')->delete();

        // 2) Retouches du propriétaire en admin après le 11/11.
        $s2->forceFill(['price' => 9.50, 'status' => Status::INACTIVE, 'is_available' => 0])->save();
        $tenders = Item::where('slug', 'tenders-5')->firstOrFail();
        $tenders->forceFill(['price' => 5.50, 'is_available' => 0])->save();
        $sauce = DB::table('item_extras')->insertGetId(['item_id' => $tenders->id, 'name' => 'Sauce Algérienne', 'price' => 0.5, 'group_label' => 'sauce', 'status' => Status::ACTIVE, 'visible_on' => null, 'is_available' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('item_extras')->where('item_id', $terminator->id)->where('name', 'Façon Cayenne')->update(['price' => 2.00]);

        // 3) Déploiement.
        $migration = require base_path('database/migrations/2026_11_11_220000_sandwich_2_viandes_viandes_du_terminator.php');
        $migration->up();

        $s2 = $s2->fresh();
        $tenders = $tenders->fresh();
        $this->assertEqualsWithDelta(9.50, (float) $s2->price, 0.001, 'prix du Sandwich 2 Viandes retouché en admin écrasé');
        $this->assertSame(Status::INACTIVE, (int) $s2->status, 'statut du Sandwich 2 Viandes retouché en admin écrasé');
        $this->assertSame(0, (int) $s2->is_available, 'disponibilité du Sandwich 2 Viandes retouchée en admin écrasée');
        $this->assertEqualsWithDelta(5.50, (float) $tenders->price, 0.001, 'prix des Tenders retouché en admin écrasé');
        $this->assertSame(0, (int) $tenders->is_available, 'disponibilité des Tenders retouchée en admin écrasée');
        $this->assertSame(Status::ACTIVE, (int) DB::table('item_extras')->where('id', $sauce)->value('status'), 'option ajoutée en admin aux Tenders désactivée');
        $facon = DB::table('item_extras')->where('item_id', $terminator->id)->where('name', 'Façon Cayenne')->value('price');
        if ($facon !== null) {
            $this->assertEqualsWithDelta(2.00, (float) $facon, 0.001, 'prix du supplément Façon Cayenne retouché en admin écrasé');
        }

        // Et la migration fait bien son travail : les deux viandes du Terminator, en Viande 1 ET en Viande 2.
        foreach ([$v1, $v2] as $attr) {
            $this->assertTrue(
                DB::table('item_variations')->where('item_id', $s2->id)->where('item_attribute_id', $attr->id)
                    ->where('name', 'Viande Hachée')->whereNull('deleted_at')->exists(),
                "Viande Hachée absente en {$attr->name} du Sandwich 2 Viandes"
            );
        }

        // Rejouée (idempotente) : aucun doublon.
        $avant = DB::table('item_variations')->where('item_id', $s2->id)->count();
        $migration->up();
        $this->assertSame($avant, DB::table('item_variations')->where('item_id', $s2->id)->count());
    }
}
