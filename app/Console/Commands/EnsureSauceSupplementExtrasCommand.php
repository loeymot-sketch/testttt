<?php

namespace App\Console\Commands;

use App\Enums\Status;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * [COMPOSITION-SAUCE 2026-07-16] Garantit un ItemExtra « Sauce supplémentaire » @0,50
 * (group_label='sauce') sur CHAQUE item qui possède un attribut sauce.
 *
 * Pourquoi : la 1ère sauce est gratuite (variation min1/max1) ; chaque sauce EN PLUS est
 * envoyée par les wizards (borne + web) comme N× cet ItemExtra, que PricingService (SSOT
 * frozen) facture génériquement (prix_DB × quantité, même chemin que la viande en plus).
 * SANS cet extra sur un item, la 2e sauce n'a AUCUN véhicule → elle est LARGUÉE à l'envoi :
 * non facturée ET absente du ticket (le +0,50 affiché « s'annule » au paiement).
 *
 * La 1ère migration (766249da5) ne l'a créé que pour les items ayant « Viande supplémentaire »
 * → 20 items à attribut sauce en étaient dépourvus (5 sandwich/tacos/burger + 13 bols). Cette
 * commande couvre TOUS les items à attribut sauce, conformément au mandat owner : « 1ère sauce
 * gratuite partout, chaque sauce en plus +0,50 € ». DATA UNIQUEMENT — aucun fichier frozen touché.
 *
 * SÛRETÉ (group_label='sauce') : auto-exclu de la partition suppléments borne
 * (resources/js/helpers/kioskExtrasPartition.js:98 → kioskIsSauceExtra) ET non-projeté comme
 * step composer (aucun profil wizard ne référence cet extra) → aucune case « Sauce
 * supplémentaire » parasite, aucun double-path. Il n'est routé QUE par la logique sauce-order
 * (kioskPricing.js / KioskWizardComponent normalizedExtras).
 *
 * Idempotent + re-jouable après toute évolution du menu (nouvel item à sauce).
 */
class EnsureSauceSupplementExtrasCommand extends Command
{
    protected $signature = 'menu:ensure-sauce-supplement-extras {--dry-run : Compter sans écrire}';

    protected $description = "Garantit l'ItemExtra 'Sauce supplémentaire' @0,50 sur chaque item à attribut sauce (facturation de la 2e sauce, borne+web). Idempotent.";

    public const EXTRA_NAME = 'Sauce supplémentaire';

    public const GROUP_LABEL = 'sauce';

    public const UNIT_PRICE = 0.50;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $created = self::ensure($dry);
        $this->info(($dry ? '[dry-run] ' : '')."Sauce supplémentaire — {$created} item(s) à attribut sauce complété(s).");

        return self::SUCCESS;
    }

    /**
     * Crée l'extra manquant sur chaque item ACTIF à attribut sauce. Idempotent.
     * Retourne le nombre d'items nouvellement complétés.
     */
    public static function ensure(bool $dryRun = false): int
    {
        $sauceAttrIds = DB::table('item_attributes')
            ->whereRaw('LOWER(name) LIKE ?', ['%sauce%'])
            ->pluck('id');

        if ($sauceAttrIds->isEmpty()) {
            return 0;
        }

        $sauceItemIds = DB::table('item_variations')
            ->whereIn('item_attribute_id', $sauceAttrIds)
            ->where('status', Status::ACTIVE)
            ->distinct()
            ->pluck('item_id');

        $created = 0;
        foreach ($sauceItemIds as $itemId) {
            $exists = DB::table('item_extras')
                ->where('item_id', $itemId)
                ->where('name', self::EXTRA_NAME)
                ->where('group_label', self::GROUP_LABEL)
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

        return $created;
    }
}
