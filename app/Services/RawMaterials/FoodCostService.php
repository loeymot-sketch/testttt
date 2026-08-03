<?php

namespace App\Services\RawMaterials;

use App\Models\Item;
use App\Models\RawMaterialRecipeLine;
use Illuminate\Support\Collection;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P2b — B5] Food cost par produit.
 *
 * Pour un {@see Item}, additionne le coût matière de sa recette :
 *   coût = Σ (recipe.qty × raw_material.avg_cost).
 *
 * ─── NULL-safe (crux) ──────────────────────────────────────────────────────
 *  `avg_cost` est NULLABLE : tant que l'owner n'a pas saisi les prix d'achat
 *  (factures P3), une matière n'a pas de coût. On NE traite JAMAIS un coût
 *  inconnu comme 0 (« gratuit ») :
 *   - la ligne concernée porte `unit_cost = null` et `line_cost = null` ;
 *   - `material_cost` = somme des SEULES lignes connues (coût PARTIEL) ;
 *   - `has_unknown_cost = true` signale que le total est incomplet ;
 *   - `margin` et `margin_pct` = null tant qu'un coût manque (pas de fausse marge).
 *
 *  Un produit SANS recette (`has_recipe = false`) n'a pas non plus de marge
 *  calculée (sinon il afficherait 100 % de marge à tort).
 *
 * ─── NF525 / branch ────────────────────────────────────────────────────────
 *  Couche ADDITIVE en LECTURE seule : ne touche ni prix fiscal, ni séquence, ni
 *  chaîne d'audit. Hard-scope branch_id=1 (V1 mono-branche, pattern service conso).
 */
class FoodCostService
{
    /** Branche unique V1 (hard-scope). */
    public const BRANCH_ID = 1;

    /** Décimales de calcul (avg_cost est decimal:4). */
    private const SCALE = 4;

    /**
     * Coût matière + marge d'un produit.
     *
     * @return array{
     *   item_id:int, item_name:string, sale_price:float, material_cost:float,
     *   has_recipe:bool, has_unknown_cost:bool,
     *   lines: array<int, array{material:string, qty:float, unit_cost:?float, line_cost:?float}>,
     *   margin:?float, margin_pct:?float
     * }
     */
    public function costForProduct(Item $item): array
    {
        $lines = $this->recipeLines((int) $item->id);

        $salePrice = (float) $item->price;
        $hasRecipe = $lines->isNotEmpty();
        $hasUnknownCost = false;
        $materialCost = 0.0;
        $out = [];

        foreach ($lines as $line) {
            $material = $line->rawMaterial;
            $name = $material->name ?? '(matière supprimée)';
            $qty = (float) $line->qty;

            // avg_cost NULL (ou matière supprimée) = coût inconnu — jamais 0.
            $unitCost = ($material !== null && $material->avg_cost !== null)
                ? (float) $material->avg_cost
                : null;

            if ($unitCost === null) {
                $hasUnknownCost = true;
                $lineCost = null;
            } else {
                $lineCost = round($qty * $unitCost, self::SCALE);
                $materialCost += $lineCost;
            }

            $out[] = [
                'material' => $name,
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'line_cost' => $lineCost,
            ];
        }

        $materialCost = round($materialCost, self::SCALE);

        // Marge SEULEMENT si la recette existe ET tous les coûts sont connus.
        $costFullyKnown = $hasRecipe && ! $hasUnknownCost;
        $margin = $costFullyKnown ? round($salePrice - $materialCost, self::SCALE) : null;
        $marginPct = ($costFullyKnown && $salePrice > 0.0)
            ? round(($margin / $salePrice) * 100, 2)
            : null;

        return [
            'item_id' => (int) $item->id,
            'item_name' => (string) $item->name,
            'sale_price' => $salePrice,
            'material_cost' => $materialCost, // partiel si has_unknown_cost — lu avec le flag
            'has_recipe' => $hasRecipe,
            'has_unknown_cost' => $hasUnknownCost,
            'lines' => $out,
            'margin' => $margin,
            'margin_pct' => $marginPct,
        ];
    }

    /**
     * Lignes de recette PRODUIT (subject = Item) de la branche 1, avec la matière.
     */
    private function recipeLines(int $itemId): Collection
    {
        if ($itemId <= 0) {
            return new Collection();
        }

        return RawMaterialRecipeLine::query()
            ->where('branch_id', self::BRANCH_ID)
            ->where('subject_type', Item::class)
            ->where('subject_id', $itemId)
            ->with('rawMaterial')
            ->get();
    }
}
