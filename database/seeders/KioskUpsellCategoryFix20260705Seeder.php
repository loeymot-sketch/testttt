<?php

namespace Database\Seeders;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use Illuminate\Database\Seeder;

/**
 * [A1 2026-07-05] Owner mandate — la borne ne doit proposer en upsell QUE
 * desserts / boissons / menu enfant, JAMAIS des sandwichs/burgers/tacos/bols
 * (plats principaux).
 *
 * L'upsell borne est 100% data-driven (Frontend\ItemController::kioskUpsell) :
 *   Priorité 1 : items is_upsell=YES ET category.kiosk_upsell_include=true
 *   Priorité 2 (fallback) : is_featured=YES ET category.kiosk_upsell_include=true
 *
 * Le bug : TOUTES les catégories avaient kiosk_upsell_include=1, donc le
 * fallback is_featured laissait remonter des sandwichs/burgers. Fix = restreindre
 * le pool aux 3 catégories voulues (par NOM, robuste aux IDs), et flaguer leurs
 * items ACTIFS is_upsell pour garantir un pool suffisant (limite borne = 6).
 *
 * Idempotent : peut être rejoué sans effet de bord.
 */
class KioskUpsellCategoryFix20260705Seeder extends Seeder
{
    /** Catégories autorisées dans le pool upsell borne (noms normalisés lowercase). */
    private const ALLOWED_CATEGORY_NAMES = [
        'desserts',
        'boissons',
        'menu enfant',
    ];

    public function run(): void
    {
        $allowedIds = [];

        foreach (ItemCategory::all() as $cat) {
            $normalized = mb_strtolower(trim((string) $cat->name));
            $isAllowed = in_array($normalized, self::ALLOWED_CATEGORY_NAMES, true);

            if ((bool) $cat->kiosk_upsell_include !== $isAllowed) {
                $cat->kiosk_upsell_include = $isAllowed;
                $cat->save();
            }

            if ($isAllowed) {
                $allowedIds[] = $cat->id;
            }
        }

        // Garantir un pool suffisant : flaguer is_upsell tous les items actifs des
        // catégories autorisées (desserts/boissons/menu enfant).
        $flagged = 0;
        if (! empty($allowedIds)) {
            $flagged = Item::whereIn('item_category_id', $allowedIds)
                ->where('status', Status::ACTIVE)
                ->where('is_upsell', '!=', Ask::YES)
                ->update(['is_upsell' => Ask::YES]);
        }

        $this->command?->info(
            'A1 kiosk-upsell: '.count($allowedIds).' catégories autorisées ['
            .implode(',', $allowedIds).'], '.$flagged.' items nouvellement flaggés is_upsell.'
        );
    }
}
