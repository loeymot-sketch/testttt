<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * [OWNER 2026-07-17] « Le supplément gratiné est seulement fait pour les bols,
 * et il est à 2 € pas 1 €. »
 *
 * A. Soft-delete les gratinés vivants HORS catégorie bols (constaté : Galette Normale,
 *    Galette Cayenne, Sandwich Classique @1 €).
 * B. Normalise prix/groupe des gratinés vivants des bols (2,00 € / supplement_bol).
 * C. Garantit « Option Gratiné » @2,00 sur chaque bol ACTIF sans gratiné (constaté :
 *    Bol Frites #41 orphelin depuis le dedup 2026-06-24 ; Bol Riz #45 déjà couvert).
 *
 * DATA UNIQUEMENT (aucun frozen). Logique alignée sur
 * {@see App\Console\Commands\EnforceGratineBolsOnlyCommand} (idempotente, re-jouable).
 * Migration self-contained (replay-safe même si la commande évolue).
 */
return new class extends Migration
{
    public function up(): void
    {
        $bolCatIds = DB::table('item_categories')
            ->where(function ($q) {
                $q->whereRaw("LOWER(slug) LIKE 'bol%'")
                    ->orWhereRaw("LOWER(name) LIKE 'bol%'");
            })
            ->pluck('id')
            ->all();

        // A) hors bols → soft-delete
        $removeIds = DB::table('item_extras as e')
            ->join('items as i', 'i.id', '=', 'e.item_id')
            ->whereRaw("LOWER(e.name) LIKE '%gratin%'")
            ->whereNull('e.deleted_at')
            ->whereNotIn('i.item_category_id', $bolCatIds)
            ->pluck('e.id')
            ->all();

        if ($removeIds !== []) {
            DB::table('item_extras')->whereIn('id', $removeIds)->update([
                'deleted_at' => now(),
                'status' => Status::INACTIVE,
                'updated_at' => now(),
            ]);
        }

        if ($bolCatIds === []) {
            return;
        }

        // B) bols mal pricés/groupés → 2,00 € / supplement_bol
        $normalizeIds = DB::table('item_extras as e')
            ->join('items as i', 'i.id', '=', 'e.item_id')
            ->whereRaw("LOWER(e.name) LIKE '%gratin%'")
            ->whereNull('e.deleted_at')
            ->whereIn('i.item_category_id', $bolCatIds)
            ->where(function ($q) {
                $q->where('e.price', '<>', 2.00)
                    ->orWhere('e.group_label', '<>', 'supplement_bol')
                    ->orWhereNull('e.group_label');
            })
            ->pluck('e.id')
            ->all();

        if ($normalizeIds !== []) {
            DB::table('item_extras')->whereIn('id', $normalizeIds)->update([
                'price' => 2.00,
                'group_label' => 'supplement_bol',
                'updated_at' => now(),
            ]);
        }

        // C) bol ACTIF sans gratiné vivant → Option Gratiné @2,00
        $bolItemIds = DB::table('items')
            ->whereIn('item_category_id', $bolCatIds)
            ->where('status', Status::ACTIVE)
            ->whereNull('deleted_at')
            ->pluck('id');

        foreach ($bolItemIds as $itemId) {
            $exists = DB::table('item_extras')
                ->where('item_id', $itemId)
                ->whereRaw("LOWER(name) LIKE '%gratin%'")
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('item_extras')->insert([
                'item_id' => $itemId,
                'name' => 'Option Gratiné',
                'price' => 2.00,
                'group_label' => 'supplement_bol',
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
        // No-op réversible : on ne peut pas distinguer sûrement les soft-deletes de cette
        // migration des dedups antérieurs sans risquer de ressusciter des doublons 1 €.
        // L'état cible est ré-assuré à tout moment par `php artisan menu:enforce-gratine-bols-only`.
    }
};
