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
            ['code' => 'crustaces',    'name_key' => 'allergens.crustaces',    'icon' => '🦐', 'sort' => 2],
            ['code' => 'oeufs',        'name_key' => 'allergens.oeufs',        'icon' => '🥚', 'sort' => 3],
            ['code' => 'poisson',      'name_key' => 'allergens.poisson',      'icon' => '🐟', 'sort' => 4],
            ['code' => 'arachides',    'name_key' => 'allergens.arachides',    'icon' => '🥜', 'sort' => 5],
            ['code' => 'soja',         'name_key' => 'allergens.soja',         'icon' => '🌱', 'sort' => 6],
            ['code' => 'lait',         'name_key' => 'allergens.lait',         'icon' => '🥛', 'sort' => 7],
            ['code' => 'fruits_a_coque', 'name_key' => 'allergens.fruits_a_coque', 'icon' => '🌰', 'sort' => 8],
            ['code' => 'celeri',       'name_key' => 'allergens.celeri',       'icon' => '🥬', 'sort' => 9],
            ['code' => 'moutarde',     'name_key' => 'allergens.moutarde',     'icon' => '🫘', 'sort' => 10],
            ['code' => 'sesame',       'name_key' => 'allergens.sesame',       'icon' => '🌻', 'sort' => 11],
            ['code' => 'sulfites',     'name_key' => 'allergens.sulfites',     'icon' => '🍷', 'sort' => 12],
            ['code' => 'lupin',        'name_key' => 'allergens.lupin',        'icon' => '🌼', 'sort' => 13],
            ['code' => 'mollusques',   'name_key' => 'allergens.mollusques',   'icon' => '🐚', 'sort' => 14],
        ];

        foreach ($allergens as $row) {
            Allergen::updateOrCreate(
                ['code' => $row['code']],
                $row,
            );
        }
    }
}
