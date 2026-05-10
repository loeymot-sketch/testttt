<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V3.6 (2026-05-10) — Frites Style Upgrade extras (owner gate).
 *
 * Owner request : ajouter 3 niveaux progressifs de "style" pour les frites
 * dans le wizard kiosk + POS (synchronisation totale via DB partagée) :
 *   - Niveau 0 : Frites Nature (default, pas de surcoût, pas de row DB)
 *   - Niveau 1 : Cheddar fondu (+1.00 €)
 *   - Niveau 2 : Cheddar + Oignons croustillants (+2.00 €)
 *
 * Ces 2 nouveaux niveaux sont stockés comme `item_extras` rattachés aux items
 * frites/menu disponibles (4 items target : Menu Frites+Boisson, Frites Seules,
 * Frites Moyenne, Frites Grande) avec `group_label = 'frites_style'` pour
 * indiquer leur exclusivité mutuelle (radio-like).
 *
 * Le wizard kiosk lit ces extras via le step "Frites style" (3 cards) lorsque
 * `menuChoice === 'frites'` ou `'full'`. La synchronisation POS est naturelle :
 * mêmes extras visibles dans le catalog, mêmes prix appliqués via PricingService.
 */
return new class extends Migration {
    private const TARGET_ITEM_IDS = [360, 361, 402, 403];
    private const GROUP = 'frites_style';

    private const UPGRADES = [
        ['name' => 'Cheddar fondu',                 'price' => 1.00],
        ['name' => 'Cheddar + Oignons croustillants', 'price' => 2.00],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::TARGET_ITEM_IDS as $itemId) {
            // Idempotence : si une row identique existe déjà, on skip.
            foreach (self::UPGRADES as $upgrade) {
                $exists = DB::table('item_extras')
                    ->where('item_id', $itemId)
                    ->where('name', $upgrade['name'])
                    ->where('group_label', self::GROUP)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('item_extras')->insert([
                    'item_id'        => $itemId,
                    'name'           => $upgrade['name'],
                    'price'          => $upgrade['price'],
                    'status'         => 5, // ACTIVE
                    'group_label'    => self::GROUP,
                    'is_available'   => true,
                    'visible_on'     => null,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('item_extras')
            ->whereIn('item_id', self::TARGET_ITEM_IDS)
            ->where('group_label', self::GROUP)
            ->delete();
    }
};
