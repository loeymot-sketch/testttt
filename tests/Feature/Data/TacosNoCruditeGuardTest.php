<?php

namespace Tests\Feature\Data;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\Scopes\BranchScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL-8AXES V3 2026-08-05] Sentinelle : un TACOS n'a JAMAIS de crudités.
 *
 * Règle métier owner (axe 8) : « le tacos est toujours en galette et il n'y a
 * pas de crudités dedans ». Défaut de PROD constaté : Tacos M (26) et Tacos L
 * (97) portaient Salade/Tomate/Oignon/Oignons cuits — dérive de données (le
 * seeder n'appelle jamais seedCruditesAsExtras pour les tacos).
 *
 * Ce test prouve la logique de la migration
 * 2026_08_05_100000_remove_crudites_from_tacos_items sur une fixture qui
 * reproduit la dérive : crudités sur un item Tacos → soft-deleted ; crudités
 * d'un item NON-tacos → intactes ; suppléments payants du tacos → intacts ;
 * ré-exécution → idempotente.
 */
class TacosNoCruditeGuardTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        $migration = require base_path('database/migrations/2026_08_05_100000_remove_crudites_from_tacos_items.php');
        $migration->up();
    }

    private function makeItem(int $categoryId, string $name): Item
    {
        return Item::withoutGlobalScope(BranchScope::class)->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'item_category_id' => $categoryId,
            'price' => 8.5,
            'item_type' => 1,
            'status' => Status::ACTIVE,
        ]);
    }

    private function makeCrudite(Item $item, string $name): ItemExtra
    {
        return ItemExtra::withoutGlobalScopes()->create([
            'item_id' => $item->id,
            'name' => $name,
            'price' => 0,
            'group_label' => 'crudite',
            'status' => Status::ACTIVE,
        ]);
    }

    public function test_migration_removes_crudites_from_tacos_only_and_is_idempotent(): void
    {
        $tacosCat = ItemCategory::withoutGlobalScopes()->create([
            'name' => 'Tacos', 'slug' => 'tacos', 'status' => Status::ACTIVE,
        ]);
        $galetteCat = ItemCategory::withoutGlobalScopes()->create([
            'name' => 'Galette', 'slug' => 'galette', 'status' => Status::ACTIVE,
        ]);

        $tacos = $this->makeItem($tacosCat->id, 'Tacos M');
        $galette = $this->makeItem($galetteCat->id, 'Galette Normale');

        // Reproduit la dérive de prod.
        $this->makeCrudite($tacos, 'Salade');
        $this->makeCrudite($tacos, 'Oignons cuits');
        // Supplément payant du tacos — NE DOIT PAS être touché.
        $cheddar = ItemExtra::withoutGlobalScopes()->create([
            'item_id' => $tacos->id, 'name' => 'Cheddar', 'price' => 1.0,
            'group_label' => 'supplement', 'status' => Status::ACTIVE,
        ]);
        // Crudités légitimes de la galette — NE DOIVENT PAS être touchées.
        $this->makeCrudite($galette, 'Salade');

        $this->runMigration();

        $activeTacosCrudites = ItemExtra::withoutGlobalScopes()
            ->where('item_id', $tacos->id)->where('group_label', 'crudite')
            ->whereNull('deleted_at')->count();
        $this->assertSame(0, $activeTacosCrudites, 'Les crudités du tacos doivent être soft-deleted.');

        // Soft-delete, pas hard-delete (snapshots historiques résolubles).
        $this->assertSame(2, ItemExtra::withoutGlobalScopes()->withTrashed()
            ->where('item_id', $tacos->id)->where('group_label', 'crudite')
            ->whereNotNull('deleted_at')->count());

        $this->assertNull($cheddar->fresh()->deleted_at, 'Le supplément payant du tacos doit rester actif.');
        $this->assertSame(1, ItemExtra::withoutGlobalScopes()
            ->where('item_id', $galette->id)->where('group_label', 'crudite')
            ->whereNull('deleted_at')->count(), 'Les crudités de la galette doivent rester actives.');

        // Idempotence : une 2e exécution ne change rien.
        $before = ItemExtra::withoutGlobalScopes()->withTrashed()->get(['id', 'deleted_at'])->toArray();
        $this->runMigration();
        $this->assertEquals($before, ItemExtra::withoutGlobalScopes()->withTrashed()->get(['id', 'deleted_at'])->toArray());
    }
}
