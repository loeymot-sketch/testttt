<?php

namespace App\Services\Stock;

use App\Enums\Status;
use App\Models\Item;
use App\Models\RawMaterial;
use App\Models\RawMaterialMovement;
use App\Models\RawMaterialStock;
use App\Models\Scopes\BranchScope;
use App\Models\StockLevel;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * [PHASE 3d — VUE CONSO & STOCK UNIFIÉE 2026-07-24] Agrégateur LECTURE SEULE qui
 * réunit dans UN seul tableau les DEUX vérités de stock du restaurant :
 *
 *  1. MATIÈRES PREMIÈRES (raw_materials / raw_material_stocks) — stock théorique
 *     SIGNÉ décrémenté par la conso des ventes scellées ({@see RawMaterialConsumptionService}).
 *     Coût & valeur via `avg_cost` (NULLABLE — jamais traité comme 0 : miroir
 *     de {@see \App\Services\RawMaterials\FoodCostService}).
 *  2. PRODUITS REVENDUS / BOISSONS (items catégorie « Boissons » avec stock_levels)
 *     — stock UNITÉ non-signé, conso = mouvements sortants ({@see StockMovement}).
 *
 *  + une section « 🛒 À acheter » = tout ce qui est en rupture ou sous le seuil,
 *  matières ET boissons fondues ensemble (la « clarté » que l'owner veut voir sur
 *  son téléphone).
 *
 * ─── NF525 / branch ────────────────────────────────────────────────────────────
 *  Couche ADDITIVE, 0 écriture, ne touche NI prix fiscal, NI séquence, NI chaîne
 *  d'audit, NI composition_snapshot. Hard-scope `branch_id` explicite sur CHAQUE
 *  requête (les modèles RawMaterial* n'ont pas de BranchScope global ; StockLevel/
 *  StockMovement en ont un mais l'admin branch_id=0 le rend inerte — le filtre
 *  explicite garantit un périmètre déterministe = branche 1 en V1 mono-poste).
 */
class UnifiedStockViewService
{
    /** Branche unique V1 (hard-scope par défaut). */
    public const BRANCH_ID = 1;

    /** Fenêtre « conso récente » par défaut (jours). */
    public const RECENT_WINDOW_DAYS = 30;

    /** Motif des mouvements de consommation matière (ventes scellées). */
    private const RAW_SALE_REASON = 'sale';

    /**
     * Vue unifiée matières + boissons + « à acheter » + totaux.
     *
     * @return array{
     *   branch_id:int, window_days:int, generated_at:string,
     *   raw_materials: array<int, array<string, mixed>>,
     *   resold_products: array<int, array<string, mixed>>,
     *   to_buy: array<int, array<string, mixed>>,
     *   totals: array{
     *     raw_material_stock_value:float, raw_materials_count:int,
     *     resold_products_count:int, out_count:int, low_count:int,
     *     to_buy_count:int, missing_cost_count:int
     *   }
     * }
     */
    public function overview(int $branchId = self::BRANCH_ID, int $windowDays = self::RECENT_WINDOW_DAYS): array
    {
        $branchId = $branchId > 0 ? $branchId : self::BRANCH_ID;
        $windowDays = max(1, $windowDays);
        $cutoff = now()->subDays($windowDays);

        $rawMaterials = $this->rawMaterialRows($branchId, $cutoff);
        $resoldProducts = $this->resoldProductRows($branchId, $cutoff);

        // « À acheter » = rupture (out) OU sous le seuil (low), matières + boissons.
        $toBuy = [];
        foreach ($rawMaterials as $row) {
            if ($row['status'] !== 'ok') {
                $toBuy[] = $this->toBuyEntry('raw_material', $row);
            }
        }
        foreach ($resoldProducts as $row) {
            if ($row['status'] !== 'ok') {
                $toBuy[] = $this->toBuyEntry('resold_product', $row);
            }
        }
        // Ruptures d'abord, puis par nom — l'owner voit le plus urgent en haut.
        usort($toBuy, static function (array $a, array $b): int {
            $rank = static fn (string $s): int => $s === 'out' ? 0 : 1;

            return [$rank($a['status']), $a['name']] <=> [$rank($b['status']), $b['name']];
        });

        return [
            'branch_id' => $branchId,
            'window_days' => $windowDays,
            'generated_at' => now()->toIso8601String(),
            'raw_materials' => $rawMaterials,
            'resold_products' => $resoldProducts,
            'to_buy' => array_values($toBuy),
            'totals' => $this->totals($rawMaterials, $resoldProducts, $toBuy),
        ];
    }

    /**
     * Lignes MATIÈRES PREMIÈRES actives de la branche, avec on_hand théorique,
     * conso récente (Σ mouvements 'sale'), avg_cost NULL-safe et valeur de stock.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rawMaterialRows(int $branchId, \DateTimeInterface $cutoff): array
    {
        $materials = RawMaterial::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        if ($materials->isEmpty()) {
            return [];
        }

        $onHand = RawMaterialStock::query()
            ->where('branch_id', $branchId)
            ->pluck('on_hand', 'raw_material_id');

        // Conso récente = Σ des deltas 'sale' (négatifs) sur la fenêtre, par matière.
        $consumption = RawMaterialMovement::query()
            ->where('branch_id', $branchId)
            ->where('reason', self::RAW_SALE_REASON)
            ->where('created_at', '>=', $cutoff)
            ->groupBy('raw_material_id')
            ->select('raw_material_id', DB::raw('SUM(delta) as total'))
            ->pluck('total', 'raw_material_id');

        $rows = [];
        foreach ($materials as $material) {
            $id = (int) $material->id;
            $stock = round((float) ($onHand[$id] ?? 0), 3);
            $threshold = $material->threshold_low !== null ? (float) $material->threshold_low : null;
            $avgCost = $material->avg_cost !== null ? (float) $material->avg_cost : null;
            $recent = round(abs((float) ($consumption[$id] ?? 0)), 3);

            // Valeur ligne = on_hand × avg_cost (null si coût inconnu — jamais 0 forcé).
            $stockValue = $avgCost !== null ? round($stock * $avgCost, 2) : null;

            $rows[] = [
                'id' => $id,
                'name' => (string) $material->name,
                'unit' => (string) $material->unit,
                'on_hand' => $stock,
                'threshold_low' => $threshold,
                'recent_consumption' => $recent,
                'avg_cost' => $avgCost,
                'has_cost' => $avgCost !== null,
                'stock_value' => $stockValue,
                'status' => $this->status($stock, $threshold),
            ];
        }

        return $rows;
    }

    /**
     * Lignes PRODUITS REVENDUS / BOISSONS : items catégorie « Boissons » actifs
     * possédant un stock_level sur la branche. on_hand UNITÉ + conso récente
     * (Σ mouvements sortants). Miroir de la source « boissons » du classifieur
     * d'achats (slug 'boisson%' OU nom 'Boisson%').
     *
     * @return array<int, array<string, mixed>>
     */
    private function resoldProductRows(int $branchId, \DateTimeInterface $cutoff): array
    {
        $drinkItems = Item::query()
            ->where('status', Status::ACTIVE)
            ->whereHas('category', function ($query): void {
                $query->where('slug', 'like', 'boisson%')->orWhere('name', 'like', 'Boisson%');
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($drinkItems->isEmpty()) {
            return [];
        }

        $itemIds = $drinkItems->pluck('id')->map(fn ($id) => (int) $id)->all();

        // stock_levels des boissons (hard-scope branch explicite — BranchScope inerte
        // sous admin branch_id=0, on force le périmètre déterministe).
        $levels = StockLevel::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('stockable_type', Item::class)
            ->whereIn('stockable_id', $itemIds)
            ->get()
            ->keyBy('stockable_id');

        if ($levels->isEmpty()) {
            return [];
        }

        $levelIds = $levels->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Conso récente = Σ des deltas SORTANTS (delta < 0) sur la fenêtre, par stock_level.
        $consumption = StockMovement::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->whereIn('stock_level_id', $levelIds)
            ->where('delta', '<', 0)
            ->where('created_at', '>=', $cutoff)
            ->groupBy('stock_level_id')
            ->select('stock_level_id', DB::raw('SUM(delta) as total'))
            ->pluck('total', 'stock_level_id');

        $rows = [];
        foreach ($drinkItems as $item) {
            $itemId = (int) $item->id;
            $level = $levels->get($itemId);
            if ($level === null) {
                continue; // boisson sans stock_level = non suivie à l'unité, hors rayon.
            }

            $stock = (int) $level->on_hand;
            $threshold = $level->threshold_low !== null ? (int) $level->threshold_low : null;
            $recent = (int) round(abs((float) ($consumption[(int) $level->id] ?? 0)));

            $rows[] = [
                'id' => $itemId,
                'name' => (string) $item->name,
                'unit' => 'u',
                'on_hand' => $stock,
                'threshold_low' => $threshold,
                'recent_consumption' => $recent,
                'status' => $this->status((float) $stock, $threshold !== null ? (float) $threshold : null),
            ];
        }

        return $rows;
    }

    /**
     * Statut d'un article : 'out' (≤ 0 = rupture), 'low' (≤ seuil > 0), sinon 'ok'.
     * Seuil NULL ou 0 → jamais 'low' (pas de seuil défini).
     */
    private function status(float $onHand, ?float $threshold): string
    {
        if ($onHand <= 0.0) {
            return 'out';
        }

        if ($threshold !== null && $threshold > 0.0 && $onHand <= $threshold) {
            return 'low';
        }

        return 'ok';
    }

    /**
     * Normalise une ligne matière/boisson en entrée « à acheter » compacte.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function toBuyEntry(string $kind, array $row): array
    {
        return [
            'kind' => $kind,
            'id' => $row['id'],
            'name' => $row['name'],
            'unit' => $row['unit'] ?? 'u',
            'on_hand' => $row['on_hand'],
            'threshold_low' => $row['threshold_low'],
            'status' => $row['status'],
        ];
    }

    /**
     * Totaux : valeur de stock matières (coûts CONNUS, on_hand positif uniquement),
     * comptes, ruptures, sous-seuil, à-acheter, matières sans coût (bandeau UI).
     *
     * @param  array<int, array<string, mixed>>  $rawMaterials
     * @param  array<int, array<string, mixed>>  $resoldProducts
     * @param  array<int, array<string, mixed>>  $toBuy
     * @return array<string, mixed>
     */
    private function totals(array $rawMaterials, array $resoldProducts, array $toBuy): array
    {
        $stockValue = 0.0;
        $missingCost = 0;
        foreach ($rawMaterials as $row) {
            if (! $row['has_cost']) {
                $missingCost++;

                continue;
            }
            // Valeur = coût connu × stock POSITIF (une dérive théorique négative ne
            // soustrait pas de la valeur inventaire).
            if ($row['on_hand'] > 0 && $row['stock_value'] !== null) {
                $stockValue += (float) $row['stock_value'];
            }
        }

        $all = array_merge($rawMaterials, $resoldProducts);
        $outCount = 0;
        $lowCount = 0;
        foreach ($all as $row) {
            if ($row['status'] === 'out') {
                $outCount++;
            } elseif ($row['status'] === 'low') {
                $lowCount++;
            }
        }

        return [
            'raw_material_stock_value' => round($stockValue, 2),
            'raw_materials_count' => count($rawMaterials),
            'resold_products_count' => count($resoldProducts),
            'out_count' => $outCount,
            'low_count' => $lowCount,
            'to_buy_count' => count($toBuy),
            'missing_cost_count' => $missingCost,
        ];
    }
}
