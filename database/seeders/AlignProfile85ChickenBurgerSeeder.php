<?php

namespace Database\Seeders;

use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * [2026-05-18] Aligns published wizard profile #85 (Chicken Burger / item 375)
 * with the steps the POS Vanilla JS wizard actually sends.
 *
 * Diagnostic: PricingService rejects `item_variations[].id=1280` (Poulet mariné,
 * attribute 307) with "Composition : le choix #1280 n'appartient pas au profil
 * publié." because profile 85 only declares 3 steps (sauce/supplements/menu)
 * — no `viande` step (attribute 307), no `crudite` step (extra_group).
 *
 * Idempotent: skips inserts if steps already exist.
 */
class AlignProfile85ChickenBurgerSeeder extends Seeder
{
    public function run(): void
    {
        $profile = ItemWizardProfile::find(85);
        if (! $profile) {
            $this->command->error('Profile 85 not found.');
            return;
        }

        $existingKeys = ItemWizardStep::where('profile_id', 85)->pluck('step_key')->all();

        $rows = [];
        if (! in_array('viande', $existingKeys, true)) {
            $rows[] = [
                'profile_id'              => 85,
                'step_key'                => 'viande',
                'label'                   => 'Viande',
                'source_type'             => 'item_attribute',
                'source_ref'              => 'viande',
                'source_item_attribute_id' => 307,
                'min_select'              => 1,
                'max_select'              => 1,
                'allow_repeat'            => 0,
                'position'                => 3,
                'is_active'               => 1,
                'created_at'              => now(),
                'updated_at'              => now(),
            ];
        }
        if (! in_array('crudite', $existingKeys, true)) {
            // Item 375 has 4 distinct crudités in `item_extras` (group_label='crudite'):
            // Salade, Tomate, Oignon, Cornichon. max_select aligned with reality.
            $rows[] = [
                'profile_id'              => 85,
                'step_key'                => 'crudite',
                'label'                   => 'Crudités',
                'source_type'             => 'extra_group',
                'source_ref'              => 'crudite',
                'source_item_attribute_id' => null,
                'min_select'              => 0,
                'max_select'              => 4,
                'allow_repeat'            => 0,
                'position'                => 4,
                'is_active'               => 1,
                'created_at'              => now(),
                'updated_at'              => now(),
            ];
        }

        if (empty($rows)) {
            $this->command->info('Profile 85 already aligned (viande + crudite present).');
            return;
        }

        DB::table('item_wizard_steps')->insert($rows);
        $this->command->info(sprintf('Inserted %d step(s) into profile 85.', count($rows)));
    }
}
