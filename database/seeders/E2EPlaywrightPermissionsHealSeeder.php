<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * Répare des bases locales « tronquées » (ex. seulement ingredients_manage) où
 * RolePermissionTableSeeder n’a rien d’utile à donner à Admin → 403 sur
 * /api/admin/setting/item-category et Catalog Studio vide en E2E.
 *
 * Déclenché uniquement quand le nombre de permissions Spatie est très bas.
 * Hors production.
 */
class E2EPlaywrightPermissionsHealSeeder extends Seeder
{
    /** Au-dessous : on considère la table permissions non fiable pour l’admin SPA. */
    private const HEAL_THRESHOLD = 20;

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        if (Permission::query()->count() >= self::HEAL_THRESHOLD) {
            return;
        }

        $tableNames = config('permission.table_names');

        DB::transaction(function () use ($tableNames): void {
            DB::table($tableNames['role_has_permissions'])->delete();
            DB::table($tableNames['model_has_permissions'])->delete();
            DB::table($tableNames['permissions'])->delete();
        });

        $this->call(PermissionTableSeeder::class);
        $this->call(RolePermissionTableSeeder::class);
        $this->call(IngredientPermissionSeeder::class);
    }
}
