<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [OWNER 2026-08-03] La migration fix_cayenne_viande_supplement_wiring répare le câblage
 * VPS : item à attribut viande SANS « Viande supplémentaire » (→ +2,50 fantôme en caisse,
 * dépassement désactivé en borne) + extras parasites « Viande en plus » / « Mixte @2,50 ».
 */
class CayenneViandeSupplementWiringMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        $m = require base_path('database/migrations/2026_08_03_210000_fix_cayenne_viande_supplement_wiring.php');
        $m->up();
    }

    private function seedCayenneLike(): int
    {
        $itemId = Item::factory()->create(['name' => 'Cayenne Test', 'price' => 6.90, 'status' => Status::ACTIVE])->id;
        $attrId = DB::table('item_attributes')->insertGetId([
            'name' => 'Viande 1', 'status' => Status::ACTIVE, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['Poulet mariné', 'Viande Hachée', 'Mixte (hachée + poulet)'] as $v) {
            DB::table('item_variations')->insert([
                'item_id' => $itemId, 'item_attribute_id' => $attrId, 'name' => $v, 'price' => 0,
                'status' => Status::ACTIVE, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ([['Viande en plus', 2.50], ['Mixte (hachée + poulet)', 2.50]] as [$n, $p]) {
            DB::table('item_extras')->insert([
                'item_id' => $itemId, 'name' => $n, 'price' => $p, 'status' => Status::ACTIVE,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $itemId;
    }

    public function test_migration_wires_supplement_and_retires_parasite_extras(): void
    {
        $itemId = $this->seedCayenneLike();

        $this->runMigration();

        // L'extra du dépassement unifié existe et est actif @2,50.
        $suppl = DB::table('item_extras')->where('item_id', $itemId)
            ->whereRaw('LOWER(name) LIKE ?', ['%viande suppl%'])->first();
        $this->assertNotNull($suppl, 'Viande supplémentaire posée (le +2,50 caisse scelle, la borne réactive le dépassement)');
        $this->assertSame(Status::ACTIVE, (int) $suppl->status);
        $this->assertEqualsWithDelta(2.50, (float) $suppl->price, 0.001);

        // Les parasites sont désactivés (jamais supprimés — historique préservé).
        $this->assertSame(Status::INACTIVE, (int) DB::table('item_extras')->where('item_id', $itemId)
            ->where('name', 'Viande en plus')->value('status'), 'legacy « Viande en plus » retiré (double exposition)');
        $this->assertSame(Status::INACTIVE, (int) DB::table('item_extras')->where('item_id', $itemId)
            ->where('name', 'like', 'Mixte%')->value('status'), 'extra payant Mixte retiré (Mixte = choix GRATUIT)');

        // Idempotence : re-run = no-op (pas de doublon d'extra).
        $this->runMigration();
        $this->assertSame(1, DB::table('item_extras')->where('item_id', $itemId)
            ->whereRaw('LOWER(name) LIKE ?', ['%viande suppl%'])->count());
    }

    public function test_migration_leaves_fixed_meat_items_untouched(): void
    {
        // Un item SANS attribut viande (vraie viande fixe, ex. Suprême) garde son
        // contournement « Viande en plus » actif — seul chemin de supplément pour lui.
        $itemId = Item::factory()->create(['name' => 'Supreme Test', 'price' => 6.90, 'status' => Status::ACTIVE])->id;
        DB::table('item_extras')->insert([
            'item_id' => $itemId, 'name' => 'Viande en plus', 'price' => 2.50,
            'status' => Status::ACTIVE, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigration();

        $this->assertSame(Status::ACTIVE, (int) DB::table('item_extras')->where('item_id', $itemId)
            ->where('name', 'Viande en plus')->value('status'));
    }
}
