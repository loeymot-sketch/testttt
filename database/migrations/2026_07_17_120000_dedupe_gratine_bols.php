<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * [RED F7 2026-07-17] Dédup gratiné sur un même bol : les Bowls legacy (items
 * 42-44/46-48, inactifs) portent « Boule gratinée » ET « Option Gratiné »
 * vivants — un item réactivé afficherait 2 gratinés cumulables à 4 €. On ne
 * garde qu'une ligne par bol (préférence « Option Gratiné »).
 *
 * Logique alignée sur {@see App\Console\Commands\EnforceGratineBolsOnlyCommand}
 * (partie B'). Self-contained, idempotente.
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

        if ($bolCatIds === []) {
            return;
        }

        $dupItems = DB::table('item_extras as e')
            ->join('items as i', 'i.id', '=', 'e.item_id')
            ->whereRaw("LOWER(e.name) LIKE '%gratin%'")
            ->whereNull('e.deleted_at')
            ->whereIn('i.item_category_id', $bolCatIds)
            ->selectRaw('e.item_id, COUNT(*) as n')
            ->groupBy('e.item_id')->havingRaw('COUNT(*) > 1')
            ->pluck('e.item_id');

        foreach ($dupItems as $itemId) {
            $rows = DB::table('item_extras')
                ->where('item_id', $itemId)
                ->whereRaw("LOWER(name) LIKE '%gratin%'")
                ->whereNull('deleted_at')
                ->orderByRaw("CASE WHEN name = ? THEN 0 ELSE 1 END, id", ['Option Gratiné'])
                ->pluck('id')
                ->all();

            $losers = array_slice($rows, 1);
            if ($losers !== []) {
                DB::table('item_extras')->whereIn('id', $losers)->update([
                    'deleted_at' => now(),
                    'status' => Status::INACTIVE,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // No-op : soft-deletes de doublons legacy — l'état cible est ré-assurable
        // par `php artisan menu:enforce-gratine-bols-only`.
    }
};
