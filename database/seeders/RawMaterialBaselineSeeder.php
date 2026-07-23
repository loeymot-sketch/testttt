<?php

namespace Database\Seeders;

use App\Models\RawMaterial;
use Illuminate\Database\Seeder;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P1a] Baseline des matières premières
 * Le Cayenne (déploiement progressif — plan RÉPONSES OWNER #6 : d'abord les
 * principaux traçables).
 *
 * Idempotent : updateOrCreate sur (branch_id, name) — relance multiple sans
 * doublon (11 rows). PAS de canette : les boissons vendues à l'unité restent
 * comptées par l'existant (stock_levels item) — plan amendement #2, une seule
 * vérité par objet physique.
 *
 * `piece_weight_g` = 75 g pour la viande hachée (steak façonné maison — plan
 * RÉPONSES OWNER #1). Les autres poids/pièce seront affinés à la fiche (P1b).
 */
class RawMaterialBaselineSeeder extends Seeder
{
    public function run(): void
    {
        $branchId = 1;

        // [name, unit, piece_weight_g|null]
        $materials = [
            ['Viande hachée',  'g',       75],
            ['Poulet',         'g',       null],
            ['Cheddar',        'piece',   null],
            ['Jambon',         'tranche', null],
            ['Pain',           'piece',   null],
            ['Galette',        'piece',   null],
            ['Sauce maison',   'g',       null],
            ['Portion frites', 'piece',   null],
            ['Salade',         'g',       null],
            ['Tomate',         'g',       null],
            ['Oignon',         'g',       null],
        ];

        foreach ($materials as [$name, $unit, $pieceWeight]) {
            RawMaterial::updateOrCreate(
                ['branch_id' => $branchId, 'name' => $name],
                [
                    'unit' => $unit,
                    'piece_weight_g' => $pieceWeight,
                    'is_active' => true,
                ],
            );
        }
    }
}
