<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * [GOAL owner 2026-07-24] Retirer la viande « Viande Hachée » (non utilisée) de tous les
 * wizards (caisse + borne). Les viandes proposées viennent des variations DB des attributs
 * « Viande N » ; on SOFT-DELETE les variations « Viande Hachée » → disparaissent de toutes
 * les surfaces (scope SoftDeletes). « Merguez » n'existe QUE dans le fallback frozen
 * pos-wizard.js (aucune variation DB) → retirée côté constante (commit frozen sous LOCK).
 *
 * NF525 : les commandes existantes gardent leur composition_snapshot immuable (copie scellée
 * du nom) — le soft-delete de la variation catalogue ne les affecte pas. Réversible (restore).
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('item_variations')
            ->where('name', 'Viande Hachée')
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('item_variations')
                ->whereIn('id', $ids)
                ->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('item_variations')
            ->where('name', 'Viande Hachée')
            ->whereNotNull('deleted_at')
            ->update(['deleted_at' => null, 'updated_at' => now()]);
    }
};
