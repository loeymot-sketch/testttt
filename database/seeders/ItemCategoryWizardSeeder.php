<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * [PLAN_11 ARCH-01] Seeder pour configurer les catégories avec wizard_template
 */
class ItemCategoryWizardSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $configs = [
            'Nos Tacos'                  => ['wizard_template' => 'tacos',     'has_menu' => true,  'default_menu_kiosk' => true,  'sauce_included_menu' => true],
            'Nos Sandwichs'              => ['wizard_template' => 'sandwich',  'has_menu' => true,  'default_menu_kiosk' => false, 'sauce_included_menu' => true],
            'Nos Burgers'                => ['wizard_template' => 'burger',    'has_menu' => true,  'default_menu_kiosk' => false, 'sauce_included_menu' => true],
            'Nos Assiettes'              => ['wizard_template' => 'assiette',  'has_menu' => false, 'default_menu_kiosk' => false, 'sauce_included_menu' => false],
            'Ojja'                       => ['wizard_template' => 'simple',    'has_menu' => false, 'default_menu_kiosk' => false, 'sauce_included_menu' => false],
            'Omelettes'                  => ['wizard_template' => 'omelette',  'has_menu' => false, 'default_menu_kiosk' => false, 'sauce_included_menu' => false],
            'Nos Salades'                => ['wizard_template' => 'salade',    'has_menu' => false, 'default_menu_kiosk' => false, 'sauce_included_menu' => false],
            'Poulet croustillant'        => ['wizard_template' => 'snacking',  'has_menu' => false, 'default_menu_kiosk' => false, 'sauce_included_menu' => false],
            'Frites & Accompagnements'   => ['wizard_template' => 'simple',    'has_menu' => false, 'default_menu_kiosk' => false, 'sauce_included_menu' => false],
            'Nos Desserts'               => ['wizard_template' => 'simple',    'has_menu' => false, 'default_menu_kiosk' => false, 'sauce_included_menu' => false],
            'Nos Boissons'               => ['wizard_template' => 'simple',    'has_menu' => false, 'default_menu_kiosk' => false, 'sauce_included_menu' => false],
            'Menus'                      => ['wizard_template' => 'simple',    'has_menu' => false, 'default_menu_kiosk' => false, 'sauce_included_menu' => false],
            'Snacking'                   => ['wizard_template' => 'snacking',  'has_menu' => false, 'default_menu_kiosk' => false, 'sauce_included_menu' => false],
            'Petit déjeuner'             => ['wizard_template' => 'simple',    'has_menu' => false, 'default_menu_kiosk' => false, 'sauce_included_menu' => false],
            'Menu enfant'                => ['wizard_template' => 'simple',    'has_menu' => true,  'default_menu_kiosk' => false, 'sauce_included_menu' => true],
            'Chicha'                     => ['wizard_template' => 'simple',    'has_menu' => false, 'default_menu_kiosk' => false, 'sauce_included_menu' => false],
            'Café'                       => ['wizard_template' => 'simple',    'has_menu' => false, 'default_menu_kiosk' => false, 'sauce_included_menu' => false],
        ];

        foreach ($configs as $name => $config) {
            DB::table('item_categories')
                ->where('name', $name)
                ->update(array_merge(['updated_at' => now()], $config));
        }

        $this->command->info('ItemCategory wizard configuration applied.');
    }
}
