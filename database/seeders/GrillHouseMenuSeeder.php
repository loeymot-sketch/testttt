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
 * USE INSTEAD: MenuSeeder (database/seeders/MenuSeeder.php)
 *              or artisan commands:
 *              - php artisan menu:create
 *              - php artisan menu:reset
 *              - php artisan menu:verify
 *
 * The MenuSeeder is now the SINGLE SOURCE OF TRUTH for French menu items.
 * It sources data from config/menu.php which is the centralized configuration.
 *
 * This file is kept for reference only and will be removed in a future update.
 * Running this seeder will now throw an exception to prevent duplicate data.
 *
 * ============================================================================
 *
 * OLD DESCRIPTION (kept for reference):
 * Seeder officiel "Le Grill House"
 * Intègre la logique métier avancée :
 * - STO (Salade Tomate Oignon) via ItemAttribute (Radio) -> Kiosk CategoryHelper (AttributeSlotType.garnish)
 * - Choix Viandes (1 à 3) via ItemAttribute (Radio) -> Kiosk (AttributeSlotType.meat)
 * - Sauce 1 Gratuite via ItemAttribute (Radio) -> Kiosk (AttributeSlotType.sauce)
 * - Suppléments Sauces & Ingrédients via ItemExtra (Checkboxes continues)
 * - Menu (Formule +3€) via ItemAddon -> Kiosk (Addons tunnel)
 */
class GrillHouseMenuSeeder extends Seeder
{
    /**
     * DEPRECATED: Do not run this seeder directly.
     *
     * @return void
     * @throws \Exception
     */
    public function run()
    {
        throw new \Exception(
            "CRITICAL ERROR: GrillHouseMenuSeeder is DEPRECATED and BLOCKED.\n" .
            "This seeder has been replaced by MenuSeeder which uses config/menu.php\n" .
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
