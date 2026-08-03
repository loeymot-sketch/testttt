<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * E2E POS Round 1 PG4-P0-001 heal — idempotent.
 *
 * Category 315 "Frites & Accompagnements" had `channels="[]"` (empty array),
 * causing ItemController::itemDetails to abort 404 for all 5 items
 * (Frites Seules id=361, Boisson Seule id=362, +3 others).
 * Grid query rendered tiles but click silently failed with red toast
 * "Une erreur est survenue. Réessayez." and cart stayed at 0,00€.
 *
 * Fix: align channels=NULL (legacy default = visible everywhere per
 * ItemCategory::isVisibleOn line 57). Matches the 12 working categories.
 *
 * Idempotent: only updates if currently "[]" or empty JSON.
 */
class AlignFritesCategoryChannelsSeeder extends Seeder
{
    public function run(): void
    {
        $updated = DB::table('item_categories')
            ->where('id', 315)
            ->whereIn('channels', ['[]', ''])
            ->update(['channels' => null]);

        $this->command->info(sprintf(
            'AlignFritesCategoryChannelsSeeder: %d row(s) updated for category 315 channels []→null',
            $updated
        ));
    }
}
