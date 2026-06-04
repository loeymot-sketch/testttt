<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Kiosk validation panier heal 2026-05-21 — idempotent.
 *
 * Items 1/2/3 ("Menu (Frites + Boisson)", "Frites Seules", "Boisson Seule")
 * sont des items addon INTERNES (category_id=NULL, utilisés uniquement
 * comme cibles de pivots `item_addons` pour le combo Menu sur sandwich).
 * Ils étaient configurés `channels=["admin"]` ce qui faisait que
 * PricingService::assertOptionsOrderable rejetait la commande kiosk avec
 * "Addon ID 25 indisponible sur kiosk. Commande rejetée." (ItemAddon
 * pivot id=25 = Sandwich Cayenne → Menu combo via addon_item_id=1).
 *
 * Le wizard kiosk propose l'option "Menu" (Frites+Boisson) à l'utilisateur,
 * mais la validation backend rejette parce que l'item-cible 1 a
 * channels=["admin"]. Mismatch UI/backend.
 *
 * Fix : aligner channels=NULL (= visible everywhere selon Item::isVisibleOn
 * ligne 85, legacy default). Les items 1/2/3 n'apparaissent PAS dans le
 * catalogue grid (category_id=NULL) — channels NULL n'a aucun effet sur
 * l'affichage catalog, seulement sur la validation des addons.
 *
 * Idempotent : update seulement si channels = '["admin"]' actuellement.
 * Pattern mirror AlignFritesCategoryChannelsSeeder (PG4-P0-001).
 */
class AlignAddonItemsChannelsSeeder extends Seeder
{
    public function run(): void
    {
        $addonItemIds = [1, 2, 3];

        // `channels` is a MySQL JSON column — use JSON_CONTAINS for predicate
        // and JSON_LENGTH=1 to ensure ONLY ["admin"] matches (not e.g.
        // ["admin","kiosk"] which would be a legitimate multi-surface
        // config that we must NOT clobber). Idempotent: rows already NULL
        // or non-admin-only stay untouched.
        $updated = DB::table('items')
            ->whereIn('id', $addonItemIds)
            ->whereRaw('JSON_CONTAINS(channels, \'"admin"\')')
            ->whereRaw('JSON_LENGTH(channels) = 1')
            ->update(['channels' => null]);

        $this->command->info(sprintf(
            'AlignAddonItemsChannelsSeeder: %d row(s) updated for items %s channels ["admin"]→NULL',
            $updated,
            implode(',', $addonItemIds)
        ));
    }
}
