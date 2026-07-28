<?php

namespace App\Console\Commands;

use App\Enums\Status;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * [VIANDE-SUPPL UNIFIÉ 2026-07-28] Garantit un ItemExtra « Viande supplémentaire » @2,50
 * sur CHAQUE item composable qui possède un attribut viande (« Viande 1/2/… »).
 *
 * Pourquoi (miroir exact de EnsureSauceSupplementExtrasCommand) : les viandes INCLUSES
 * sont gratuites jusqu'au quota (maxViandes) ; chaque viande EN PLUS est envoyée par les
 * wizards (borne + caisse) comme N× cet ItemExtra, que PricingService (SSOT frozen) facture
 * génériquement (prix_DB × quantité). Le nom réel de la viande dépassée est porté par
 * l'instruction « Viandes en plus : N× <nom> » (buildCartItem) puis résolu sur le ticket
 * cuisine — le supplément est donc NOMMÉ, jamais générique.
 *
 * SANS cet extra sur un item, la borne désactive le dépassement :
 * `KioskStepViandeComponent.viandeSupplementsEnabled` = (prix > 0) → false → plafond dur
 * maxViandes, AUCUN supplément possible, silencieusement (aucune erreur affichée). C'est
 * exactement la plainte owner « la borne ne propose pas de supplément de viande » : au
 * 2026-07-28, 5 composables majeurs (Sandwich Classique #25, Big Tacos #27, Big Cayenne #36,
 * Big Classique #37, Big Chicken #39) portaient un attribut viande SANS cet extra → supplément
 * impossible. Le seeder d'origine (OwnerMenuUpdate20260623Seeder) ne le posait que sur une
 * liste d'ID hardcodée ; cette commande couvre TOUS les items à attribut viande et se rejoue.
 * DATA UNIQUEMENT — aucun fichier frozen touché.
 *
 * SÛRETÉ (name « Viande supplémentaire ») : auto-exclu des tuiles viande
 * (KioskStepViandeComponent.viandeList → filtre `/viande\s*suppl/i`) ET de la partition
 * suppléments borne (kioskExtrasPartition.js → même regex) → aucune fausse tuile, aucun
 * double-path. Il n'est routé QUE par la logique de dépassement (viandeAllocation / buildCartItem).
 *
 * group_label : 'supplement_bol' si l'item porte déjà des suppléments bol, sinon 'supplement'
 * (parité avec les 16 items existants). Idempotent + re-jouable.
 */
class EnsureViandeSupplementExtrasCommand extends Command
{
    protected $signature = 'menu:ensure-viande-supplement-extras {--dry-run : Compter sans écrire}';

    protected $description = "Garantit l'ItemExtra 'Viande supplémentaire' @2,50 sur chaque item à attribut viande (facturation de la viande en plus, borne+caisse). Idempotent.";

    public const EXTRA_NAME = 'Viande supplémentaire';

    public const GROUP_LABEL = 'supplement';

    public const GROUP_LABEL_BOL = 'supplement_bol';

    public const UNIT_PRICE = 2.50;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $created = self::ensure($dry);
        $this->info(($dry ? '[dry-run] ' : '')."Viande supplémentaire — {$created} item(s) à attribut viande complété(s).");

        return self::SUCCESS;
    }

    /**
     * Crée l'extra manquant sur chaque item ACTIF à attribut viande. Idempotent.
     * Retourne le nombre d'items nouvellement complétés.
     */
    public static function ensure(bool $dryRun = false): int
    {
        $viandeAttrIds = DB::table('item_attributes')
            ->whereRaw('LOWER(name) LIKE ?', ['%viande%'])
            ->pluck('id');

        if ($viandeAttrIds->isEmpty()) {
            return 0;
        }

        $viandeItemIds = DB::table('item_variations')
            ->whereIn('item_attribute_id', $viandeAttrIds)
            ->where('status', Status::ACTIVE)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('item_id');

        $created = 0;
        foreach ($viandeItemIds as $itemId) {
            $exists = DB::table('item_extras')
                ->where('item_id', $itemId)
                ->whereRaw('LOWER(name) LIKE ?', ['%viande suppl%'])
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                continue;
            }

            $created++;
            if ($dryRun) {
                continue;
            }

            // group_label = celui déjà utilisé par les suppléments de CET item (bol vs standard).
            $isBol = DB::table('item_extras')
                ->where('item_id', $itemId)
                ->where('group_label', self::GROUP_LABEL_BOL)
                ->whereNull('deleted_at')
                ->exists();

            DB::table('item_extras')->insert([
                'item_id' => $itemId,
                'name' => self::EXTRA_NAME,
                'price' => self::UNIT_PRICE,
                'group_label' => $isBol ? self::GROUP_LABEL_BOL : self::GROUP_LABEL,
                'status' => Status::ACTIVE,
                'visible_on' => null,
                'is_available' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $created;
    }
}
