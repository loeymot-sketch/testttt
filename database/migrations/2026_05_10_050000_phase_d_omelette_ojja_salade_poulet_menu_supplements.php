<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V3.7 (2026-05-10) Phase D — Wizard simple Omelettes/Ojja/Salades/Poulets.
 *
 * Owner gate : ces 15 items doivent proposer dans leur wizard :
 *  - Page 1 (étape menu) : formule "Avec frites + boisson" oui/non + style
 *    frites (Cheddar/Cheddar+Oignons via Phase C v3.6)
 *  - Page 2 (étape supplements) : 7 supplements payants (cloned from cat 318
 *    Suppléments items, sauf "Sauce supplémentaire" qui ferait doublon avec
 *    la step sauce existante de ces items vars=15).
 *
 * Cibles (15 items dans 4 catégories) :
 *  - 310 Ojja (4) : Bœuf, Poulet, Viande Hachée, Merguez
 *  - 311 Omelettes (3) : Nature, Fromage, Champignons Fromage
 *  - 312 Nos Salades (4) : Chèvre, Royale, Saumon, Tunisienne
 *  - 313 Poulet croustillant (4) : Ailes 6/12 pcs, Filets 6/12 pcs
 *
 * Modifications :
 *  1. items.has_menu = true → active step menu wizard (frites/boisson).
 *  2. item_extras : 7 supplement clones par item (group_label='supplement_clone'
 *     pour distinction avec les frites_style).
 *
 * Synchronisation POS automatique via item_extras + has_menu (POS lit ces
 * mêmes flags du catalog partagé).
 *
 * Idempotent (skip si row existe), transactionnel (atomicité).
 */
return new class extends Migration {
    private const TARGET_CATEGORY_IDS = [310, 311, 312, 313];
    private const SUPPLEMENT_GROUP = 'supplement_clone';

    /**
     * 7 supplements clonés (skip "Sauce supplémentaire" id=415, prix 0.50€,
     * car les items cibles ont déjà step sauce avec leurs propres variations).
     */
    private const SUPPLEMENT_TEMPLATES = [
        ['name' => 'Fromage supplémentaire', 'price' => 1.00],
        ['name' => 'Jambon de dinde',        'price' => 1.00],
        ['name' => 'Boursin',                'price' => 1.00],
        ['name' => 'Fromage à raclette',     'price' => 1.00],
        ['name' => 'Œuf',                    'price' => 1.00],
        ['name' => 'Galette pommes de terre', 'price' => 1.00],
        ['name' => 'Salade verte',           'price' => 2.00],
    ];

    /**
     * 2 frites_style upgrades (alignés Phase B v3.6 cf. migration
     * 2026_05_10_040000) attachés aux 15 items pour afficher l'étape Cheddar/
     * Oignons quand user choisit menuChoice='frites' ou 'full'.
     */
    private const FRITES_STYLE_TEMPLATES = [
        ['name' => 'Cheddar fondu',                   'price' => 1.00],
        ['name' => 'Cheddar + Oignons croustillants', 'price' => 2.00],
    ];
    private const FRITES_STYLE_GROUP = 'frites_style';

    public function up(): void
    {
        DB::transaction(function () {
            $now = now();
            $itemIds = DB::table('items')
                ->whereIn('item_category_id', self::TARGET_CATEGORY_IDS)
                ->where('status', Status::ACTIVE)
                ->pluck('id');

            // 1. Flip has_menu=true sur les CATEGORIES (column lives on
            //    item_categories, item resource computes via category->has_menu).
            DB::table('item_categories')
                ->whereIn('id', self::TARGET_CATEGORY_IDS)
                ->update(['has_menu' => true, 'updated_at' => $now]);

            // 2. Clone 7 supplements + 2 frites_style upgrades par item.
            $rows = [];
            $allTemplates = [
                [self::SUPPLEMENT_GROUP, self::SUPPLEMENT_TEMPLATES],
                [self::FRITES_STYLE_GROUP, self::FRITES_STYLE_TEMPLATES],
            ];
            foreach ($itemIds as $itemId) {
                foreach ($allTemplates as [$group, $templates]) {
                    foreach ($templates as $tpl) {
                        $exists = DB::table('item_extras')
                            ->where('item_id', $itemId)
                            ->where('name', $tpl['name'])
                            ->where('group_label', $group)
                            ->exists();
                        if ($exists) {
                            continue;
                        }
                        $rows[] = [
                            'item_id'      => $itemId,
                            'name'         => $tpl['name'],
                            'price'        => $tpl['price'],
                            'status'       => Status::ACTIVE,
                            'group_label'  => $group,
                            'is_available' => true,
                            'visible_on'   => null,
                            'created_at'   => $now,
                            'updated_at'   => $now,
                        ];
                    }
                }
            }
            if (! empty($rows)) {
                DB::table('item_extras')->insertOrIgnore($rows);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $itemIds = DB::table('items')
                ->whereIn('item_category_id', self::TARGET_CATEGORY_IDS)
                ->where('status', Status::ACTIVE)
                ->pluck('id');

            DB::table('item_categories')
                ->whereIn('id', self::TARGET_CATEGORY_IDS)
                ->update(['has_menu' => false]);

            DB::table('item_extras')
                ->whereIn('item_id', $itemIds)
                ->whereIn('group_label', [self::SUPPLEMENT_GROUP, self::FRITES_STYLE_GROUP])
                ->delete();
        });
    }
};
