<?php

namespace Database\Seeders;

use App\Models\Allergen;
use Illuminate\Database\Seeder;

/**
 * Kiosk Design V1 — Phase 1.3
 *
 * Seeder des 14 allergènes de l'Annexe II du Règlement UE 1169/2011 (FIC).
 * Idempotent : `updateOrCreate` sur `code`, relance multiple sans doublon.
 *
 * Les `name_key` pointent vers des clés i18n `allergens.*` — traductions
 * FR/EN/AR à ajouter en Phase 4 dans `resources/js/languages/*.json`.
 */
class AllergensSeeder extends Seeder
{
    public function run(): void
    {
        $allergens = [
            ['code' => 'gluten',       'name_key' => 'allergens.gluten',       'icon' => '🌾', 'sort' => 1],
            ['code' => 'crustaceans',  'name_key' => 'allergens.crustaceans',  'icon' => '🦐', 'sort' => 2],
            ['code' => 'eggs',         'name_key' => 'allergens.eggs',         'icon' => '🥚', 'sort' => 3],
            ['code' => 'fish',         'name_key' => 'allergens.fish',         'icon' => '🐟', 'sort' => 4],
            ['code' => 'peanuts',      'name_key' => 'allergens.peanuts',      'icon' => '🥜', 'sort' => 5],
            ['code' => 'soy',          'name_key' => 'allergens.soy',          'icon' => '🌱', 'sort' => 6],
            ['code' => 'milk',         'name_key' => 'allergens.milk',         'icon' => '🥛', 'sort' => 7],
            ['code' => 'tree_nuts',    'name_key' => 'allergens.tree_nuts',    'icon' => '🌰', 'sort' => 8],
            ['code' => 'celery',       'name_key' => 'allergens.celery',       'icon' => '🥬', 'sort' => 9],
            ['code' => 'mustard',      'name_key' => 'allergens.mustard',      'icon' => '🫘', 'sort' => 10],
            ['code' => 'sesame',       'name_key' => 'allergens.sesame',       'icon' => '🌻', 'sort' => 11],
            ['code' => 'sulphites',    'name_key' => 'allergens.sulphites',    'icon' => '🍷', 'sort' => 12],
            ['code' => 'lupin',        'name_key' => 'allergens.lupin',        'icon' => '🌼', 'sort' => 13],
            ['code' => 'molluscs',     'name_key' => 'allergens.molluscs',     'icon' => '🐚', 'sort' => 14],
        ];

        foreach ($allergens as $row) {
            Allergen::updateOrCreate(
                ['code' => $row['code']],
                $row,
            );
        }
    }
}
