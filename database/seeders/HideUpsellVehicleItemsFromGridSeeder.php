<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Models\ItemCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * [HEAL dispute-r1 ADV-F-P1-2 2026-06-12] — idempotent.
 *
 * Les 3 SKU techniques d'upsell « Menu (Frites + Boisson) » / « Frites
 * Seules » / « Boisson Seule » sont des items addon INTERNES (cibles de
 * pivots `item_addons`, jamais des produits de rayon). Dérive DATA constatée
 * au dispute round-1 : ils portent `item_category_id = <Sandwich Cayenne>` +
 * `is_featured = ACTIVE` → ils OUVRENT le rayon Sandwich sur la borne
 * (2 tuiles image-cassée badgées « Nouveau », desc EN « Upsell item »)
 * AVANT les vrais sandwichs.
 *
 * `items.item_category_id` est NOT NULL (migration 2022_11_17_110514 —
 * le commentaire « category_id=NULL » d'AlignAddonItemsChannelsSeeder est
 * périmé) → la sortie de grille passe par une CATÉGORIE INTERNE dédiée,
 * `channels=["admin"]` :
 *  - KioskMenuService filtre les catégories par `isVisibleOn('kiosk')`
 *    (KioskMenuService:71) → la catégorie interne et ses items ne rentrent
 *    JAMAIS dans le payload grille borne (idem toute surface channel-aware).
 *  - L'orderabilité par ID via upsell/combo est INTACTE : la validation
 *    PricingService::assertOptionsOrderable (:537) vérifie les `channels` de
 *    l'ITEM addon (laissés NULL = everywhere), jamais sa catégorie.
 *
 * Résolution PAR SLUG (leçon W4 : jamais d'id hardcodé). Idempotent :
 * firstOrCreate sur la catégorie + update borné aux rows pas encore
 * rattachées à la catégorie interne.
 */
class HideUpsellVehicleItemsFromGridSeeder extends Seeder
{
    public const UPSELL_VEHICLE_SLUGS = [
        'menu-frites-boisson',
        'frites-seules',
        'boisson-seule',
    ];

    public const INTERNAL_CATEGORY_SLUG = 'technique-interne-upsell';

    public function run(): void
    {
        $internal = ItemCategory::query()->firstOrCreate(
            ['slug' => self::INTERNAL_CATEGORY_SLUG],
            [
                'name' => 'Technique (interne — upsell)',
                'description' => 'Conteneur interne des items-véhicules upsell/combo. Jamais affiché client.',
                'status' => Status::ACTIVE,
                'channels' => ['admin'],
                'sort' => 9999,
            ]
        );

        $updated = DB::table('items')
            ->whereIn('slug', self::UPSELL_VEHICLE_SLUGS)
            ->where('item_category_id', '!=', $internal->id)
            ->update([
                'item_category_id' => $internal->id,
                'is_featured' => Status::INACTIVE,
                'updated_at' => now(),
            ]);

        $this->command?->info(sprintf(
            'HideUpsellVehicleItemsFromGridSeeder: %d row(s) moved to internal category #%d (admin-only) for slugs %s',
            $updated,
            $internal->id,
            implode(',', self::UPSELL_VEHICLE_SLUGS)
        ));
    }
}
