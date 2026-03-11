<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * ============================================================================
 * ⚠️  DEPRECATED - DO NOT USE - BLOCKED
 * ============================================================================
 *
 * This seeder is DEPRECATED and BLOCKED from execution.
 *
 * REASON: This seeder creates addons based on hardcoded IDs that conflict
 * with the French menu structure managed by MenuSeeder.
 *
 * USE INSTEAD: MenuSeeder (database/seeders/MenuSeeder.php)
 *              or artisan commands:
 *              - php artisan menu:create
 *              - php artisan menu:reset
 *              - php artisan menu:verify
 *
 * The MenuSeeder is now the SINGLE SOURCE OF TRUTH for French menu items.
 * It sources data from config/menu.php which is the centralized configuration.
 *
 * ============================================================================
 */
class ItemAddonTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     * @throws \Exception
     */
    public function run()
    {
        throw new \Exception(
            "CRITICAL ERROR: ItemAddonTableSeeder is DEPRECATED and BLOCKED.\n" .
            "This seeder creates addons with hardcoded IDs that conflict with French menu.\n" .
            "\n" .
            "USE INSTEAD:\n" .
            "  - php artisan menu:create  (create French menu)\n" .
            "  - php artisan menu:reset   (reset French menu)\n" .
            "  - php artisan menu:verify  (verify French integrity)\n" .
            "\n" .
            "The MenuSeeder is the ONLY authorized seeder for menu items."
        );
    }
}
