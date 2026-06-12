<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * [2026-05-18] Aligns published wizard profiles for the Frites items
 * 361 (Frites Seules), 402 (Frites Moyenne), 403 (Frites Grande).
 *
 * Owner spec (POS + Kiosk): when a customer picks fries alone, the wizard
 * opens with 3 steps:
 *   1. "Type de frites" — Normale (no extra) / Cheddar fondu (+€) /
 *      Cheddar + Oignons croustillants (+€). Source: extra_group
 *      'frites_style' (already seeded in `item_extras`).
 *   2. "Sauce (1ère gratuite)" — single free sauce. Source: item_attribute
 *      311 (Sauce 1ère Gratuite). This seeder mirrors the 18 sauce
 *      variations attached to item 375 (Chicken Burger) onto each Frites
 *      item, all at price=0.
 *   3. "Sauce supplémentaire" — optional paid sauces. Source: extra_group
 *      'sauce_supp'. Pre-existing "Sauce X" rows for items 402/403 (15
 *      sauces at 0.50€) are tagged with the new group_label; item 361
 *      gets the same set of paid sauces seeded fresh.
 *
 * Idempotent: each section checks for existing rows before inserting.
 */
class AlignFritesWizardProfilesSeeder extends Seeder
{
    private const FRITES_ITEM_IDS = [361, 402, 403];
    private const SAUCE_ATTRIBUTE_ID = 311;
    private const PAID_SAUCE_PRICE = 0.50;

    /** Canonical 18 sauce names, mirrored from item 375. */
    private const SAUCE_NAMES = [
        'Ketchup', 'Mayonnaise', 'Algérienne', 'Curry', 'Andalouse',
        'Burger', 'Samouraï', 'Barbecue', 'Cocktail', 'Américaine',
        'Hannibal', 'Harissa', 'Blanche', 'Poivre', 'Sans Sauce',
        'Sauce fromagère maison', 'Spicy', 'Ail',
    ];

    public function run(): void
    {
        // [LOT A guard / ultra-audit 2026-06-10] These hardcoded ids (361/402/
        // 403) belong to a PREVIOUS catalog generation — none exist in the
        // live Le Cayenne DB (verified 2026-06-11), and attribute 311 is gone
        // too. Running the seeder as-is would insert orphan variations/extras
        // against nonexistent items. Refuse instead of polluting; the live
        // replacement is CaisseBillableUpgradesSeeder (name-resolved).
        $existing = \App\Models\Item::query()
            ->whereIn('id', self::FRITES_ITEM_IDS)
            ->whereNull('deleted_at')
            ->count();
        if ($existing === 0) {
            $this->command->warn(
                'AlignFritesWizardProfilesSeeder: target items '
                . implode(', ', self::FRITES_ITEM_IDS)
                . ' do not exist in this catalog — seeder is calibrated for an older DB. '
                . 'NOTHING seeded. Use CaisseBillableUpgradesSeeder instead.'
            );
            return;
        }

        foreach (self::FRITES_ITEM_IDS as $itemId) {
            $this->seedSauceVariations($itemId);
            $this->seedPaidSauceExtras($itemId);
            $this->seedWizardProfile($itemId);
        }

        $this->command->info('Frites wizard profiles aligned for items ' . implode(', ', self::FRITES_ITEM_IDS) . '.');
    }

    private function seedSauceVariations(int $itemId): void
    {
        $existing = ItemVariation::where('item_id', $itemId)
            ->where('item_attribute_id', self::SAUCE_ATTRIBUTE_ID)
            ->pluck('name')
            ->all();

        $rows = [];
        foreach (self::SAUCE_NAMES as $name) {
            if (in_array($name, $existing, true)) {
                continue;
            }
            $rows[] = [
                'item_id'           => $itemId,
                'item_attribute_id' => self::SAUCE_ATTRIBUTE_ID,
                'name'              => $name,
                'price'             => 0.000,
                'created_at'        => now(),
                'updated_at'        => now(),
            ];
        }
        if (! empty($rows)) {
            DB::table('item_variations')->insert($rows);
            $this->command->info(sprintf('  item %d: +%d sauce variations (attribute %d, price=0).', $itemId, count($rows), self::SAUCE_ATTRIBUTE_ID));
        }
    }

    private function seedPaidSauceExtras(int $itemId): void
    {
        // 1) Tag any pre-existing "Sauce X" extras (NULL group_label) with the
        //    new 'sauce_supp' label so the wizard step picks them up.
        $taggedCount = DB::table('item_extras')
            ->where('item_id', $itemId)
            ->whereNull('group_label')
            ->where('name', 'like', 'Sauce %')
            ->where('name', 'not like', 'Sauce supplémentaire:%')
            ->update(['group_label' => 'sauce_supp', 'updated_at' => now()]);

        // 2) Ensure each canonical sauce exists as a paid extra. Skip those
        //    already labelled or pre-existing under another label.
        $existing = DB::table('item_extras')
            ->where('item_id', $itemId)
            ->where('group_label', 'sauce_supp')
            ->pluck('name')
            ->all();

        $rows = [];
        foreach (self::SAUCE_NAMES as $name) {
            if ($name === 'Sans Sauce') {
                // No paid version of "no sauce"; offered only as the free choice.
                continue;
            }
            $extraName = 'Sauce ' . $name;
            if (in_array($extraName, $existing, true) || in_array($name, $existing, true)) {
                continue;
            }
            $rows[] = [
                'item_id'      => $itemId,
                'name'         => $extraName,
                'group_label'  => 'sauce_supp',
                'price'        => self::PAID_SAUCE_PRICE,
                'is_available' => 1,
                'status'       => Status::ACTIVE,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }
        if (! empty($rows)) {
            DB::table('item_extras')->insert($rows);
        }
        if ($taggedCount > 0 || ! empty($rows)) {
            $this->command->info(sprintf('  item %d: paid sauces — %d retagged, %d created.', $itemId, $taggedCount, count($rows)));
        }
    }

    private function seedWizardProfile(int $itemId): void
    {
        $profile = ItemWizardProfile::firstOrCreate(
            ['item_id' => $itemId, 'is_published' => true],
            [
                'template'     => 'custom',
                'version'      => 1,
                'is_published' => true,
                'published_at' => now(),
            ],
        );

        $existingSteps = ItemWizardStep::where('profile_id', $profile->id)->pluck('step_key')->all();

        $stepsToAdd = [
            [
                'profile_id'              => $profile->id,
                'step_key'                => 'frites_style',
                'label'                   => 'Type de frites',
                'source_type'             => 'extra_group',
                'source_ref'              => 'frites_style',
                'source_item_attribute_id' => null,
                'min_select'              => 0,
                'max_select'              => 1,
                'allow_repeat'            => 0,
                'position'                => 0,
                'is_active'               => 1,
                'created_at'              => now(),
                'updated_at'              => now(),
            ],
            [
                'profile_id'              => $profile->id,
                'step_key'                => 'sauce',
                'label'                   => 'Sauce (1ère gratuite)',
                'source_type'             => 'item_attribute',
                'source_ref'              => 'sauce (1ère gratuite)',
                'source_item_attribute_id' => self::SAUCE_ATTRIBUTE_ID,
                'min_select'              => 1,
                'max_select'              => 1,
                'allow_repeat'            => 0,
                'position'                => 1,
                'is_active'               => 1,
                'created_at'              => now(),
                'updated_at'              => now(),
            ],
            [
                'profile_id'              => $profile->id,
                'step_key'                => 'sauce_supp',
                'label'                   => 'Sauce supplémentaire',
                'source_type'             => 'extra_group',
                'source_ref'              => 'sauce_supp',
                'source_item_attribute_id' => null,
                'min_select'              => 0,
                'max_select'              => 5,
                'allow_repeat'            => 0,
                'position'                => 2,
                'is_active'               => 1,
                'created_at'              => now(),
                'updated_at'              => now(),
            ],
        ];

        $rows = array_filter($stepsToAdd, fn (array $s): bool => ! in_array($s['step_key'], $existingSteps, true));
        if (! empty($rows)) {
            DB::table('item_wizard_steps')->insert(array_values($rows));
            $this->command->info(sprintf('  item %d: profile %d aligned, +%d step(s).', $itemId, $profile->id, count($rows)));
        }
    }
}
