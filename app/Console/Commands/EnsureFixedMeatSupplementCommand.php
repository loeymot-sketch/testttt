<?php

namespace App\Console\Commands;

use App\Enums\Status;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * [OWNER VIANDE-EN-PLUS 2026-07-31] Garantit un supplément « Viande en plus » @2,50 sur les
 * sandwichs à VIANDE FIXE (Cayenne, Suprême — catégorie Sandwichs SANS attribut viande).
 *
 * Pourquoi : les sandwichs à CHOIX de viande (Méga/Terminator, tacos, bols) gèrent déjà la viande
 * en plus par le DÉPASSEMENT du choix (EnsureViandeSupplementExtrasCommand → « Viande supplémentaire »).
 * Mais les sandwichs à viande FIXE n'ont AUCUN chemin pour ajouter de la viande — d'où la plainte
 * owner « même sur le Cayenne je ne trouve pas de supplément de viande ». On leur pose donc un
 * supplément normal.
 *
 * PIÈGE ÉVITÉ (nom) : surtout PAS « Viande supplémentaire » — la borne la FILTRE des tuiles
 * suppléments (`kioskExtrasPartition` / KioskStepViande, regex `/viande\s*suppl/i`) car elle est
 * réservée au routage par dépassement de choix. « Viande en plus » ne matche pas ce filtre → elle
 * s'affiche comme une tuile supplément NORMALE sur borne + web + caisse (group 'supplement').
 *
 * DATA UNIQUEMENT, idempotent, re-jouable. visible_on=null (partout).
 */
class EnsureFixedMeatSupplementCommand extends Command
{
    protected $signature = 'menu:ensure-fixed-meat-supplement {--dry-run : Compter sans écrire}';

    protected $description = "Garantit « Viande en plus » @2,50 sur les sandwichs à viande fixe (Cayenne/Suprême). Idempotent.";

    public const EXTRA_NAME = 'Viande en plus';

    public const UNIT_PRICE = 2.50;

    public const SANDWICH_CATEGORY_ID = 1; // catégorie Sandwichs (Cayenne/Suprême/Méga/Terminator)

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $created = self::ensure($dry);
        $this->info(($dry ? '[dry-run] ' : '')."Viande en plus — {$created} sandwich(s) à viande fixe complété(s).");

        return self::SUCCESS;
    }

    public static function ensure(bool $dryRun = false): int
    {
        $viandeAttrIds = DB::table('item_attributes')
            ->whereRaw('LOWER(name) LIKE ?', ['%viande%'])
            ->pluck('id');

        $sandwichItemIds = DB::table('items')
            ->where('item_category_id', self::SANDWICH_CATEGORY_ID)
            ->where('status', Status::ACTIVE)
            ->whereNull('deleted_at')
            ->pluck('id');

        $created = 0;
        foreach ($sandwichItemIds as $itemId) {
            // A un CHOIX de viande (attribut viande) ? → déjà couvert par le dépassement, on skip.
            $hasViandeChoice = $viandeAttrIds->isNotEmpty() && DB::table('item_variations')
                ->where('item_id', $itemId)
                ->whereIn('item_attribute_id', $viandeAttrIds)
                ->whereNull('deleted_at')
                ->exists();
            if ($hasViandeChoice) {
                continue;
            }

            // Déjà « Viande en plus » (le nom qui S'AFFICHE) ? → idempotent, rien à faire.
            $hasVisible = DB::table('item_extras')
                ->where('item_id', $itemId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(self::EXTRA_NAME)])
                ->whereNull('deleted_at')
                ->exists();
            if ($hasVisible) {
                continue;
            }

            // A « Viande supplémentaire » (INVISIBLE sur la borne : filtrée par /viande\s*suppl/i,
            // réservée au dépassement de choix — inutile ici, viande fixe) ? → on la RENOMME en
            // « Viande en plus » (s'affiche comme supplément normal). Sûr : aucun dépassement sur un
            // sandwich à viande fixe, donc rien ne route par ce nom. Sinon on l'ajoute.
            $hidden = DB::table('item_extras')
                ->where('item_id', $itemId)
                ->whereRaw('LOWER(name) LIKE ?', ['%viande suppl%'])
                ->where('group_label', 'supplement')
                ->whereNull('deleted_at')
                ->first();

            $created++;
            if ($dryRun) {
                continue;
            }

            if ($hidden) {
                DB::table('item_extras')->where('id', $hidden->id)->update([
                    'name'       => self::EXTRA_NAME,
                    'price'      => self::UNIT_PRICE,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('item_extras')->insert([
                    'item_id'     => $itemId,
                    'name'        => self::EXTRA_NAME,
                    'price'       => self::UNIT_PRICE,
                    'group_label' => 'supplement',
                    'status'      => Status::ACTIVE,
                    'visible_on'  => null, // partout (caisse + web + borne)
                    'is_available' => 1,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }

        return $created;
    }
}
