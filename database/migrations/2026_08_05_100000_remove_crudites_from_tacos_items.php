<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * [GOAL-8AXES V3 T-8.1.1 2026-08-05] Règle métier owner : un TACOS n'a JAMAIS
 * de crudités (« le tacos est toujours en galette et il n'y a pas de crudités
 * dedans »).
 *
 * Défaut constaté en base de production (dérive de données, PAS un bug seeder :
 * MenuResetLeCayenneCommand n'appelle jamais seedCruditesAsExtras pour les
 * tacos) : Tacos M (26) et Tacos L (97) portaient Salade/Tomate/Oignon/
 * Oignons cuits en group_label='crudite', incohérents avec Big Tacos et
 * Tacos Signature XL qui n'en avaient pas.
 *
 * Idempotente : soft-delete (deleted_at) des extras crudite ACTIFS des items
 * dont la catégorie contient « acos » (Tacos, Tacos Signature). Soft-delete et
 * non hard-delete : les composition_snapshot historiques restent résolubles
 * (extraDisplayName lit withTrashed) et un éventuel rollback métier restaure.
 *
 * Sentinelle : tests/Feature/Data/TacosNoCruditeGuardTest.php
 */
return new class extends Migration
{
    public function up(): void
    {
        $tacosCategoryIds = DB::table('item_categories')
            ->where('name', 'like', '%acos%')
            ->pluck('id');

        if ($tacosCategoryIds->isEmpty()) {
            return; // base sans catégorie Tacos (env de test vierge) — rien à faire
        }

        $tacosItemIds = DB::table('items')
            ->whereIn('item_category_id', $tacosCategoryIds)
            ->pluck('id');

        DB::table('item_extras')
            ->whereIn('item_id', $tacosItemIds)
            ->where('group_label', 'crudite')
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Volontairement vide : restaurer des crudités sur les tacos serait
        // recréer le défaut métier. Rollback = restauration manuelle ciblée.
    }
};
