<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * [GOAL-8AXES V3 T-8.3/T-8.4 2026-08-05] Demande owner (axe 8) :
 *  - « Aucune crudité » : un seul geste pour dire « pas de crudités » (au lieu
 *    de manipuler chaque crudité une par une) — caisse + borne + web.
 *  - « Poivrons cuits » 0,90 € payant, « à côté des crudités » ;
 *    « Maïs » et « Olives » payants aussi.
 *    G-7 : l'owner n'a chiffré QUE les poivrons ; « maïs et olive aussi
 *    payante » est lu comme le même groupe 0,90 € (groupe web « suppléments
 *    payants +0,90 € »). Changeable ici en une ligne si l'owner corrige.
 *
 * Périmètre : tous les items portant AU MOINS UNE crudité active (les tacos
 * n'en font plus partie — migration 2026_08_05_100000). group_label='crudite'
 * pour apparaître dans la section crudités des trois surfaces.
 *
 * Facturation (risque n°1 « affiché mais non facturé », précédent sauce
 * frites 2026-07-29) : PricingService lit ItemExtra.price → 0,90 € scellé.
 * Ticket cuisine : extra payant → supplementLines l'émet (« + Poivrons
 * cuits ») ; « Aucune crudité » (0 €, sans symbole) est émis aussi — signal
 * explicite au cuisinier. Sentinelles :
 *  - tests/Feature/Pricing/NewSupplementsBilledTest.php
 *  - tests/Feature/Data/TacosNoCruditeGuardTest.php (les tacos restent exclus)
 *
 * Idempotente : updateOrCreate par (item_id, name, group_label) + restore des
 * soft-deleted (leçon 2026-07-15 : firstOrCreate ne répare pas une ligne
 * trashed/désalignée).
 */
return new class extends Migration
{
    /**
     * ⚠️ « Aucune crudité » N'EST PAS un extra data : les DEUX wizards frozen
     * pré-cochent tout extra gratuit (KioskWizardComponent:1724 price===0 ;
     * pos-wizard.js:821 cruditeDefaultIncluded) — un marqueur gratuit sortirait
     * coché sur CHAQUE commande. Le « sans crudités » est un GESTE UI :
     * bouton borne (KioskStepGarnitures, non-frozen) + patch pos-wizard sous
     * LOCK (plans/LOCK_POSWIZARD_SANS_CRUDITES_2026-08-05.md).
     */
    private const NEW_EXTRAS = [
        ['name' => 'Poivrons cuits', 'price' => 0.90],
        ['name' => 'Maïs', 'price' => 0.90],
        ['name' => 'Olives', 'price' => 0.90],
    ];

    public function up(): void
    {
        $now = now();

        $itemIds = DB::table('item_extras')
            ->where('group_label', 'crudite')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('item_id');

        foreach ($itemIds as $itemId) {
            foreach (self::NEW_EXTRAS as $extra) {
                $existing = DB::table('item_extras')
                    ->where('item_id', $itemId)
                    ->where('name', $extra['name'])
                    ->where('group_label', 'crudite')
                    ->first();

                if ($existing) {
                    DB::table('item_extras')->where('id', $existing->id)->update([
                        'price' => $extra['price'],
                        'status' => 5, // Status::ACTIVE
                        'deleted_at' => null,
                        'updated_at' => $now,
                    ]);

                    continue;
                }

                DB::table('item_extras')->insert([
                    'item_id' => $itemId,
                    'name' => $extra['name'],
                    'price' => $extra['price'],
                    'group_label' => 'crudite',
                    'status' => 5,
                    'is_available' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('item_extras')
            ->whereIn('name', array_column(self::NEW_EXTRAS, 'name'))
            ->where('group_label', 'crudite')
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);
    }
};
