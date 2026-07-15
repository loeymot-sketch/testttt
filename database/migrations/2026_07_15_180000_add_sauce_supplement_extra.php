<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * [COMPOSITION-SAUCE 2026-07-15] Facturation de la SAUCE EN PLUS (owner : 1ère sauce
 * gratuite partout, chaque sauce en plus +0,50 €, comme la borne supplement_sauce_price=0,50).
 *
 * Aujourd'hui l'attribut #5 « Sauce (1ère Gratuite) » est min1/max1 → une 2e sauce = 422,
 * et AUCUNE ligne « Sauce supplémentaire » n'existe → le backend (PricingService, SSOT frozen)
 * n'a AUCUN chemin pour facturer une sauce en plus. Ce fix = DATA UNIQUEMENT (aucun changement
 * PricingService/frozen) : on crée un ItemExtra « Sauce supplémentaire » @0,50 pour chaque item
 * qui a déjà une « Viande supplémentaire » (= famille sandwich/tacos/burger/galette avec sauce).
 * Les DEUX wizards (web + borne) enverront ensuite les sauces au-delà de la 1ère comme N× cet
 * extra ; PricingService les price génériquement (prix_DB × quantity, MÊME chemin que la viande).
 *
 * group_label='sauce' est OBLIGATOIRE (pas 'supplement') — pivot de sûreté vérifié par le plan
 * adversaire : auto-exclu de la partition suppléments borne (kioskIsSauceExtra) ET non-énuméré
 * par la projection composer → aucune case « Sauce supplémentaire » parasite, aucun double-path.
 *
 * BOLS (41/45) EXCLUS (profil composer, sauce en step dédié) — follow-up gated.
 * Miroir EXACT de l'ItemExtra #392 (status=5, visible_on=null) sauf group_label+price+name.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Items ayant déjà une « Viande supplémentaire » group='supplement' = famille avec sauce,
        // hors composer (bols). Source de vérité du périmètre.
        $itemIds = DB::table('item_extras')
            ->where('name', 'Viande supplémentaire')
            ->where('group_label', 'supplement')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('item_id');

        foreach ($itemIds as $itemId) {
            $exists = DB::table('item_extras')
                ->where('item_id', $itemId)
                ->where('name', 'Sauce supplémentaire')
                ->where('group_label', 'sauce')
                ->exists();

            if (! $exists) {
                DB::table('item_extras')->insert([
                    'item_id' => $itemId,
                    'name' => 'Sauce supplémentaire',
                    'price' => 0.50,
                    'group_label' => 'sauce',
                    'status' => 5, // ACTIVE — miroir #392
                    'visible_on' => null,
                    'is_available' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('item_extras')
            ->where('name', 'Sauce supplémentaire')
            ->where('group_label', 'sauce')
            ->delete();
    }
};
