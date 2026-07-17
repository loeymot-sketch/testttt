<?php

namespace App\Console\Commands;

use App\Enums\Status;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * [OWNER 2026-07-17] « Le supplément gratiné est seulement fait pour les bols,
 * et il est à 2 € pas 1 €. »
 *
 * État constaté (dev 2026-07-17) : « Boule gratinée » @1 € (group 'supplement') vivait
 * encore sur Galette Normale (#23), Galette Cayenne (#24) et Sandwich Classique (#25),
 * tandis que le Bol Frites (#41) avait PERDU ses deux rows gratiné lors du dedup du
 * 2026-06-24 (Bol Riz #45 a gardé « Option Gratiné » @2 €).
 *
 * Cette commande fait respecter le mandat :
 *  A. soft-delete tout extra gratiné vivant d'un item HORS catégorie bols ;
 *  B. normalise prix/groupe (2,00 € / supplement_bol) des gratinés vivants des bols ;
 *  C. garantit « Option Gratiné » @2,00 sur chaque bol ACTIF qui n'en a plus.
 *
 * DATA UNIQUEMENT — aucun fichier frozen. Idempotente + re-jouable (nouveau bol → C).
 */
class EnforceGratineBolsOnlyCommand extends Command
{
    protected $signature = 'menu:enforce-gratine-bols-only {--dry-run : Compter sans écrire}';

    protected $description = "Gratiné réservé aux bols @2,00 € (owner 2026-07-17) : retire hors-bols, normalise 1€→2€, complète les bols actifs. Idempotent.";

    public const EXTRA_NAME = 'Option Gratiné';

    public const GROUP_LABEL = 'supplement_bol';

    public const UNIT_PRICE = 2.00;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        [$removed, $normalized, $created] = self::enforce($dry);
        $this->info(($dry ? '[dry-run] ' : '')
            ."Gratiné bols-only — {$removed} retiré(s) hors bols, {$normalized} normalisé(s) @2,00, {$created} bol(s) complété(s).");

        return self::SUCCESS;
    }

    /**
     * @return array{0:int,1:int,2:int} [retirés hors-bols, normalisés, créés sur bols]
     */
    public static function enforce(bool $dryRun = false): array
    {
        $bolCatIds = DB::table('item_categories')
            ->where(function ($q) {
                $q->whereRaw("LOWER(slug) LIKE 'bol%'")
                    ->orWhereRaw("LOWER(name) LIKE 'bol%'");
            })
            ->pluck('id')
            ->all();

        // A) Gratiné vivant sur un item hors bols → soft-delete.
        //    (bolCatIds vide ⇒ whereNotIn no-op ⇒ tout gratiné est hors-bols : voulu.)
        $removeIds = DB::table('item_extras as e')
            ->join('items as i', 'i.id', '=', 'e.item_id')
            ->whereRaw("LOWER(e.name) LIKE '%gratin%'")
            ->whereNull('e.deleted_at')
            ->whereNotIn('i.item_category_id', $bolCatIds)
            ->pluck('e.id')
            ->all();

        if (! $dryRun && $removeIds !== []) {
            DB::table('item_extras')->whereIn('id', $removeIds)->update([
                'deleted_at' => now(),
                'status' => Status::INACTIVE,
                'updated_at' => now(),
            ]);
        }

        // B) Gratiné vivant sur un bol mais mal pricé/groupé → 2,00 € / supplement_bol.
        $normalizeIds = $bolCatIds === [] ? [] : DB::table('item_extras as e')
            ->join('items as i', 'i.id', '=', 'e.item_id')
            ->whereRaw("LOWER(e.name) LIKE '%gratin%'")
            ->whereNull('e.deleted_at')
            ->whereIn('i.item_category_id', $bolCatIds)
            ->where(function ($q) {
                $q->where('e.price', '<>', self::UNIT_PRICE)
                    ->orWhere('e.group_label', '<>', self::GROUP_LABEL)
                    ->orWhereNull('e.group_label');
            })
            ->pluck('e.id')
            ->all();

        if (! $dryRun && $normalizeIds !== []) {
            DB::table('item_extras')->whereIn('id', $normalizeIds)->update([
                'price' => self::UNIT_PRICE,
                'group_label' => self::GROUP_LABEL,
                'updated_at' => now(),
            ]);
        }

        // C) Bol ACTIF sans aucun gratiné vivant → créer « Option Gratiné » @2,00.
        $created = 0;
        if ($bolCatIds !== []) {
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

                $created++;
                if ($dryRun) {
                    continue;
                }

                DB::table('item_extras')->insert([
                    'item_id' => $itemId,
                    'name' => self::EXTRA_NAME,
                    'price' => self::UNIT_PRICE,
                    'group_label' => self::GROUP_LABEL,
                    'status' => Status::ACTIVE,
                    'visible_on' => null,
                    'is_available' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return [count($removeIds), count($normalizeIds), $created];
    }
}
