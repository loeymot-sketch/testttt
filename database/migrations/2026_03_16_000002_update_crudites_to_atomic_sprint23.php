<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 23 Fix: Convert compound crudités to atomic ones
 * Old: "Complet (Salade, Tomate, Oignon)", "Sans Oignon", "Sans Tomate", "Sans Salade", "Aucune Crudité"
 * New: "Salade", "Tomate", "Oignon" (each as individual free extras)
 */
return new class extends Migration
{
    // Old compound crudité names to be removed
    protected array $oldCompoundCrudites = [
        'Complet (Salade, Tomate, Oignon)',
        'Sans Oignon',
        'Sans Tomate',
        'Sans Salade',
        'Aucune Crudité',
    ];

    // New atomic crudité names (each will be a free extra)
    protected array $newAtomicCrudites = [
        'Salade',
        'Tomate',
        'Oignon',
    ];

    public function up(): void
    {
        // Step 1: Find items that have old compound crudités
        $itemIdsWithOldCrudites = DB::table('item_extras')
            ->whereIn('name', $this->oldCompoundCrudites)
            ->where('price', 0.00)
            ->distinct()
            ->pluck('item_id')
            ->toArray();

        // Step 2: Delete old compound crudités
        $deletedCount = DB::table('item_extras')
            ->whereIn('name', $this->oldCompoundCrudites)
            ->where('price', 0.00)
            ->delete();

        // Log deleted count if needed

        // Step 3: Add new atomic crudités for items that had the old ones
        $insertedCount = 0;
        foreach ($itemIdsWithOldCrudites as $itemId) {
            foreach ($this->newAtomicCrudites as $cruditeName) {
                // Check if this crudité already exists for this item (to avoid duplicates on re-run)
                $exists = DB::table('item_extras')
                    ->where('item_id', $itemId)
                    ->where('name', $cruditeName)
                    ->where('price', 0.00)
                    ->exists();

                if (!$exists) {
                    DB::table('item_extras')->insert([
                        'item_id'    => $itemId,
                        'name'       => $cruditeName,
                        'price'      => 0.00,
                        'status'     => 1, // ACTIVE
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $insertedCount++;
                }
            }
        }

        // Log inserted count if needed
    }

    public function down(): void
    {
        // Step 1: Find items that have new atomic crudités
        $itemIdsWithNewCrudites = DB::table('item_extras')
            ->whereIn('name', $this->newAtomicCrudites)
            ->where('price', 0.00)
            ->distinct()
            ->pluck('item_id')
            ->toArray();

        // Step 2: Delete new atomic crudités
        $deletedCount = DB::table('item_extras')
            ->whereIn('name', $this->newAtomicCrudites)
            ->where('price', 0.00)
            ->delete();

        // Log rollback deleted count if needed

        // Step 3: Restore old compound crudités
        $insertedCount = 0;
        foreach ($itemIdsWithNewCrudites as $itemId) {
            foreach ($this->oldCompoundCrudites as $cruditeName) {
                DB::table('item_extras')->insert([
                    'item_id'    => $itemId,
                    'name'       => $cruditeName,
                    'price'      => 0.00,
                    'status'     => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $insertedCount++;
            }
        }

        // Log rollback inserted count if needed
    }
};
