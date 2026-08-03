<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * [COMPOSITION-SAUCE 2026-07-16] Étend « Sauce supplémentaire » @0,50 (group_label='sauce')
 * à TOUS les items à attribut sauce.
 *
 * La migration 766249da5 ne couvrait que les items ayant « Viande supplémentaire » → 20 items
 * à attribut sauce restaient SANS véhicule pour la 2e sauce (5 sandwich/tacos/burger + 13 bols).
 * Résultat : sur la borne / le web, choisir une 2e sauce → elle est larguée à l'envoi (non
 * facturée, absente du ticket ; le +0,50 affiché « s'annule » au paiement). Mandat owner :
 * « 1ère sauce gratuite partout, chaque sauce en plus +0,50 € ».
 *
 * DATA UNIQUEMENT (aucun PricingService/frozen). Logique alignée sur
 * {@see App\Console\Commands\EnsureSauceSupplementExtrasCommand} (idempotent, re-jouable).
 * Migration self-contained (replay-safe même si la commande évolue).
 */
return new class extends Migration
{
    public function up(): void
    {
        $sauceAttrIds = DB::table('item_attributes')
            ->whereRaw('LOWER(name) LIKE ?', ['%sauce%'])
            ->pluck('id');

        if ($sauceAttrIds->isEmpty()) {
            return;
        }

        $sauceItemIds = DB::table('item_variations')
            ->whereIn('item_attribute_id', $sauceAttrIds)
            ->where('status', Status::ACTIVE)
            ->distinct()
            ->pluck('item_id');

        foreach ($sauceItemIds as $itemId) {
            $exists = DB::table('item_extras')
                ->where('item_id', $itemId)
                ->where('name', 'Sauce supplémentaire')
                ->where('group_label', 'sauce')
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('item_extras')->insert([
                'item_id' => $itemId,
                'name' => 'Sauce supplémentaire',
                'price' => 0.50,
                'group_label' => 'sauce',
                'status' => Status::ACTIVE,
                'visible_on' => null,
                'is_available' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // No-op réversible : ces extras 'sauce' sont inertes s'ils ne sont pas routés, et on ne
        // peut pas distinguer sûrement ceux ajoutés ici des 14 d'origine (766249da5) sans risquer
        // de casser la facturation existante. La couverture est ré-assurée à tout moment par
        // `php artisan menu:ensure-sauce-supplement-extras` (idempotent).
    }
};
