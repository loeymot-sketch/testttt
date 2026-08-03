<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Models\ItemVariation;
use Illuminate\Console\Command;

/**
 * [OWNER SAUCES 2026-07-31] Garantit les 2 sauces ajoutées par l'owner — « Poivre » et « Burger » —
 * comme choix de sauce (ItemVariation sous l'attribut Sauce, prix 0 = incluses) sur CHAQUE item qui
 * propose déjà un choix de sauce. Miroir exact du pattern EnsureViandeSupplementExtrasCommand :
 * DATA UNIQUEMENT, idempotent, re-jouable — n'ajoute que ce qui manque, ne touche jamais aux 12
 * sauces existantes ni à aucun fichier frozen. La borne + la caisse lisent ces variations depuis la
 * DB (data-driven) → les 2 sauces apparaissent automatiquement. Le miroir web standalone (data/menu.js)
 * est mis à jour séparément.
 */
class EnsureNewSaucesCommand extends Command
{
    protected $signature = 'menu:ensure-new-sauces {--dry-run : Compter sans écrire}';

    protected $description = "Garantit les sauces 'Poivre' + 'Burger' (choix, prix 0) sur chaque item déjà saucé. Idempotent.";

    /** Attribut « Sauce » — ATTR_SAUCE du seeder OwnerMenuUpdate20260623Seeder. */
    public const ATTR_SAUCE = 5;

    public const NEW_SAUCES = ['Poivre', 'Burger'];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $added = self::ensure($dry);
        $this->info(($dry ? '[dry-run] ' : '')."Sauces Poivre/Burger — {$added} variation(s) ajoutée(s).");

        return self::SUCCESS;
    }

    /**
     * Ajoute les variations sauce manquantes sur chaque item ACTIF déjà saucé. Idempotent.
     * Retourne le nombre de variations nouvellement créées.
     */
    public static function ensure(bool $dryRun = false): int
    {
        $saucedItemIds = ItemVariation::withoutGlobalScopes()
            ->where('item_attribute_id', self::ATTR_SAUCE)
            ->where('status', Status::ACTIVE)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('item_id');

        $added = 0;
        foreach ($saucedItemIds as $itemId) {
            foreach (self::NEW_SAUCES as $name) {
                $exists = ItemVariation::withoutGlobalScopes()
                    ->where('item_id', $itemId)
                    ->where('item_attribute_id', self::ATTR_SAUCE)
                    ->where('name', $name)
                    ->whereNull('deleted_at')
                    ->exists();

                if ($exists) {
                    continue;
                }

                $added++;
                if ($dryRun) {
                    continue;
                }

                ItemVariation::withoutGlobalScopes()->create([
                    'item_id'           => $itemId,
                    'item_attribute_id' => self::ATTR_SAUCE,
                    'name'              => $name,
                    'price'             => 0.0,
                    'status'            => Status::ACTIVE,
                    'visible_on'        => null,
                ]);
            }
        }

        return $added;
    }
}
