<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * [GOAL owner 2026-07-27] RESTAURER la viande « Viande Hachée » sur borne + caisse.
 * Renverse 2026_07_24_100000_deactivate_viande_hachee_variations : l'owner signale
 * « il manquait des viandes comme viande hachée » — la décision du 24/07 (retrait)
 * est annulée par cette directive plus récente. Les viandes des wizards viennent des
 * variations DB des attributs « Viande N » : un-delete → réapparaît sur toutes les
 * surfaces (borne + caisse). Idempotent. NF525 : catalogue seulement, aucune commande
 * scellée touchée (composition_snapshot immuable).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('item_variations')
            ->where('name', 'Viande Hachée')
            ->whereNotNull('deleted_at')
            ->update(['deleted_at' => null, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('item_variations')
            ->where('name', 'Viande Hachée')
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
};
