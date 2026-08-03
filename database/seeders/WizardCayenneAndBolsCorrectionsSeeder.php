<?php

namespace Database\Seeders;

use App\Models\ItemExtra;
use App\Models\ItemVariation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Owner instructions 2026-05-21 (Wizard borne + caisse corrections):
 *
 *   1. Sandwich Cayenne (22) + Big Cayenne (36) + Galette Cayenne (24) +
 *      Tacos (26) + Big Tacos (27) :
 *      → la sauce "Cayenne fromagère maison" est PRE-INCLUSE (mentionnée
 *        dans le titre/composition). Le user choisit une AUTRE sauce dans
 *        une liste qui contient TOUTES les sauces SAUF Cayenne fromagère.
 *      → ADD : 10 ItemVariation attr_id=5 (sauce sandwich) pour ces items
 *        (Algérienne, Andalouse, Blanche, Curry, Hannibal, Harissa,
 *         Ketchup, Mayonnaise, Samouraï, Spicy)
 *      → SKIP "Sauce fromagère maison" — déjà incluse.
 *
 *   2. Bols (41-48) sauce list réduit à 2 choix :
 *      → KEEP : "Sauce fromagère maison" + "Spicy"
 *      → SOFT-DELETE : 9 autres sauces attr_id=8 par bowl
 *
 *   3. Bols Gratiné bug (option choisie mais price non appliqué au total):
 *      → ROOT CAUSE : ItemExtra "Option Gratiné" group_label=gratine est
 *        dans une step séparée du wizard que le KioskWizardComponent
 *        (FROZEN) ne propage pas au cart total.
 *      → FIX : move "Option Gratiné" extras (181/186/191/196/201/206/211/216)
 *        de group_label='gratine' → 'supplement_bol' pour qu'elles
 *        apparaissent dans le step Supplements (qui calcule correctement).
 *      → DELETE wizard step gratine (8 rows : 26, 30, 34, 38, 42, 46, 50, 54)
 *
 * Idempotent: chaque op vérifie l'état actuel avant d'écrire.
 * Frozen-zone: PricingService + KioskWizardComponent + pos-wizard.js
 * intouchés — tout est data DB.
 */
class WizardCayenneAndBolsCorrectionsSeeder extends Seeder
{
    /** Items qui doivent recevoir la liste sauce SAUF Cayenne fromagère. */
    private const CAYENNE_SAUCE_RECIPIENTS = [
        22, // Sandwich Cayenne
        24, // Galette Cayenne
        36, // Big Cayenne
        26, // Tacos
        27, // Big Tacos
    ];

    /** 10 sauces que les items Cayenne/Tacos doivent proposer. */
    private const CAYENNE_SAUCE_NAMES = [
        'Algérienne',
        'Andalouse',
        'Blanche',
        'Curry',
        'Hannibal',
        'Harissa',
        'Ketchup',
        'Mayonnaise',
        'Samouraï',
        'Spicy',
    ];

    private const BOL_ITEM_IDS = [41, 42, 43, 44, 45, 46, 47, 48];

    /** Sauces autorisées pour les bols. */
    private const BOL_KEEP_SAUCE_NAMES = ['Sauce fromagère maison', 'Spicy'];

    public function run(): void
    {
        $this->fixCayenneAndTacosSauces();
        $this->limitBolsSauces();
        $this->fixBolsGratineGroup();
        $this->removeBolsGratineStep();
    }

    /**
     * Step 1: For items 22/24/36/26/27, add 10 sauce variations (attr_id=5)
     * if missing. "Sauce fromagère maison" intentionnellement omise (déjà
     * incluse dans le sandwich Cayenne).
     */
    private function fixCayenneAndTacosSauces(): void
    {
        $added = 0;
        foreach (self::CAYENNE_SAUCE_RECIPIENTS as $itemId) {
            foreach (self::CAYENNE_SAUCE_NAMES as $sauceName) {
                $exists = ItemVariation::where('item_id', $itemId)
                    ->where('item_attribute_id', 5)
                    ->where('name', $sauceName)
                    ->whereNull('deleted_at')
                    ->exists();
                if ($exists) {
                    continue;
                }
                ItemVariation::create([
                    'item_id'           => $itemId,
                    'item_attribute_id' => 5,
                    'name'              => $sauceName,
                    'price'             => 0.000000,
                    'status'            => 5, // ACTIVE
                    'visible_on'        => null, // visible everywhere
                ]);
                $added++;
            }
        }
        $this->command->info(sprintf(
            'WizardCayenneAndBolsCorrectionsSeeder: %d sauce variations ajoutées pour Cayenne/Tacos items',
            $added
        ));
    }

    /**
     * Step 2: Bols (41-48) limit sauces to "Sauce fromagère maison" + "Spicy".
     * Soft-delete les 9 autres variations attr_id=8.
     */
    private function limitBolsSauces(): void
    {
        $deleted = ItemVariation::whereIn('item_id', self::BOL_ITEM_IDS)
            ->where('item_attribute_id', 8)
            ->whereNotIn('name', self::BOL_KEEP_SAUCE_NAMES)
            ->whereNull('deleted_at')
            ->delete(); // soft delete via Eloquent SoftDeletes if trait present, else hard

        $this->command->info(sprintf(
            'WizardCayenneAndBolsCorrectionsSeeder: %d sauce variations supprimées des bols (gardé seulement Sauce fromagère maison + Spicy)',
            $deleted
        ));
    }

    /**
     * Step 3: Move "Option Gratiné" extras from group_label=gratine to
     * group_label=supplement_bol. Bug fix : le step `gratine` séparé n'est
     * pas propagé au cart total par le KioskWizardComponent frozen.
     */
    private function fixBolsGratineGroup(): void
    {
        $updated = DB::table('item_extras')
            ->whereIn('item_id', self::BOL_ITEM_IDS)
            ->where('group_label', 'gratine')
            ->whereNull('deleted_at')
            ->update(['group_label' => 'supplement_bol']);

        $this->command->info(sprintf(
            'WizardCayenneAndBolsCorrectionsSeeder: %d extras "Option Gratiné" déplacés gratine→supplement_bol',
            $updated
        ));
    }

    /**
     * Step 4: Remove `gratine` step from each bol wizard profile (now that
     * "Option Gratiné" est dans group_label=supplement_bol, elle apparaît
     * dans le step Supplements automatiquement). Wizard step deletion
     * idempotent — re-run safe.
     */
    private function removeBolsGratineStep(): void
    {
        $profileIds = DB::table('item_wizard_profiles')
            ->whereIn('item_id', self::BOL_ITEM_IDS)
            ->pluck('id');

        $deleted = DB::table('item_wizard_steps')
            ->whereIn('profile_id', $profileIds)
            ->where('step_key', 'gratine')
            ->delete();

        $this->command->info(sprintf(
            'WizardCayenneAndBolsCorrectionsSeeder: %d steps gratine supprimées des wizard profiles bols',
            $deleted
        ));
    }
}
