<?php

namespace App\Services\Purchasing;

use App\Models\Item;
use App\Models\PurchaseDocument;
use App\Models\PurchaseLine;
use App\Models\RawMaterial;
use App\Models\RawMaterialStock;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\RawMaterials\RawMaterialStockService;
use Illuminate\Support\Facades\DB;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3a] Application en stock d'un
 * document d'achat validé. Domaine NEUF, ADDITIF, HORS NF525 — n'écrit JAMAIS
 * la chaîne fiscale (audit_logs / z_reports / fiscal_sequence intouchés).
 *
 * `validateDocument()` parcourt les lignes VALIDÉES (`status = validated` :
 * l'owner a confirmé la cible proposée par l'IA en P3b) et, selon `target_type` :
 *  - raw_material : crédite le stock matière via RawMaterialStockService::receive
 *                   (idempotent par source `purchase_line`/line.id) PUIS recalcule
 *                   `avg_cost` en MOYENNE PONDÉRÉE
 *                   (ancien_stock×ancien_coût + qty×prix) / (ancien_stock + qty).
 *  - stock_item   : incrémente stock_levels de l'item (+qty unités entières) +
 *                   mouvement `manual_in` (pattern StockService existant).
 *  - charge       : aucun mouvement de stock (comptabilisé seulement).
 *
 * IDEMPOTENCE (« re-valider = no-op ») : gate au niveau document — un document
 * déjà `validated` retourne immédiatement (protège le recalcul d'avg_cost, qui
 * n'est PAS couvert par l'idempotence-mouvement). La transaction verrouille +
 * re-teste le statut (garde double-validation concurrente). Défense en
 * profondeur : receive() dédup par mouvement, stock_levels par idempotency_key.
 *
 * Branch : hard-scope explicite (V1 mono-branche = 1). `branch_id` du document
 * fait foi ; défaut 1.
 */
class PurchaseService
{
    /** Précision du coût moyen (aligne raw_materials.avg_cost decimal:4). */
    private const COST_SCALE = 4;

    public function __construct(
        private RawMaterialStockService $rawMaterialStock,
    ) {
    }

    /**
     * Applique en stock un document d'achat. Idempotent (re-valider = no-op).
     *
     * @return array{document_id:int, status:string, applied:array<string,int>}
     */
    public function validateDocument(PurchaseDocument $document): array
    {
        // Gate idempotence : un document déjà validé ne se ré-applique pas.
        if ($document->status === PurchaseDocument::STATUS_VALIDATED) {
            return $this->noopResult($document);
        }

        return DB::transaction(function () use ($document): array {
            // Verrouille + re-teste le statut (double-validation concurrente).
            $locked = PurchaseDocument::query()
                ->whereKey($document->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status === PurchaseDocument::STATUS_VALIDATED) {
                return $this->noopResult($locked ?? $document);
            }

            $branchId = (int) ($locked->branch_id ?: 1);
            $applied = [
                'raw_material' => 0,
                'stock_item' => 0,
                'charge' => 0,
                'skipped_proposed' => 0,
            ];

            $lines = $locked->lines()->orderBy('id')->get();

            foreach ($lines as $line) {
                // Seules les lignes VALIDÉES par l'owner sont appliquées.
                if ($line->status !== PurchaseLine::STATUS_VALIDATED) {
                    $applied['skipped_proposed']++;
                    continue;
                }

                switch ($line->target_type) {
                    case PurchaseLine::TARGET_RAW_MATERIAL:
                        $this->applyRawMaterialLine($line, $branchId);
                        $applied['raw_material']++;
                        break;

                    case PurchaseLine::TARGET_STOCK_ITEM:
                        $this->applyStockItemLine($line, $branchId);
                        $applied['stock_item']++;
                        break;

                    case PurchaseLine::TARGET_CHARGE:
                        // Charge : comptabilisée seulement, aucun mouvement de stock.
                        $applied['charge']++;
                        break;
                }
            }

            $locked->forceFill(['status' => PurchaseDocument::STATUS_VALIDATED])->save();

            return [
                'document_id' => (int) $locked->id,
                'status' => 'validated',
                'applied' => $applied,
            ];
        });
    }

    /**
     * Matière première : crédite le stock (idempotent par ligne) puis recalcule
     * le coût moyen pondéré. Lit l'état AVANT réception (le stock d'avant et le
     * coût d'avant nourrissent la pondération).
     */
    private function applyRawMaterialLine(PurchaseLine $line, int $branchId): void
    {
        $rawMaterialId = (int) $line->target_id;
        if ($rawMaterialId <= 0) {
            return;
        }

        $qty = (float) $line->qty;
        $unitPrice = $line->unit_price === null ? null : (float) $line->unit_price;

        // État AVANT réception (nécessaire à la pondération).
        $oldStock = (float) (RawMaterialStock::query()
            ->where('raw_material_id', $rawMaterialId)
            ->where('branch_id', $branchId)
            ->value('on_hand') ?? 0.0);

        $oldAvg = RawMaterial::query()
            ->whereKey($rawMaterialId)
            ->value('avg_cost');
        $oldAvg = $oldAvg === null ? null : (float) $oldAvg;

        // Entrée de stock — idempotente par (source_type, source_id, raw_material_id).
        $this->rawMaterialStock->receive(
            $rawMaterialId,
            $qty,
            'purchase',
            'purchase_line',
            (int) $line->id,
            ['purchase_document_id' => (int) $line->purchase_document_id],
            $branchId,
        );

        // Coût moyen pondéré — seulement si un prix unitaire est connu (sinon on
        // ne peut pas revaloriser : on NE touche PAS avg_cost, il reste NULL/inchangé).
        if ($unitPrice !== null) {
            $newAvg = $this->weightedAverageCost($oldStock, $oldAvg, $qty, $unitPrice);
            RawMaterial::query()
                ->whereKey($rawMaterialId)
                ->update(['avg_cost' => $newAvg]);
        }
    }

    /**
     * Moyenne pondérée : (ancien_stock×ancien_coût + qty×prix) / (ancien_stock+qty).
     * Repli sur le prix unitaire quand il n'y a pas de valorisation antérieure
     * exploitable (premier achat, ancien coût inconnu, ou dénominateur ≤ 0 —
     * on_hand est signé et peut être négatif via la conso théorique).
     */
    private function weightedAverageCost(float $oldStock, ?float $oldAvg, float $qty, float $unitPrice): float
    {
        $denominator = $oldStock + $qty;

        if ($oldAvg === null || $oldStock <= 0 || $denominator <= 0) {
            return round($unitPrice, self::COST_SCALE);
        }

        $value = ($oldStock * $oldAvg) + ($qty * $unitPrice);

        return round($value / $denominator, self::COST_SCALE);
    }

    /**
     * Item revendu à l'unité (boisson) : incrémente stock_levels (+qty unités) +
     * mouvement `manual_in`. Miroir du pattern StockService (lock + forceFill +
     * mouvement append-only). Idempotence défensive par idempotency_key.
     */
    private function applyStockItemLine(PurchaseLine $line, int $branchId): void
    {
        $itemId = (int) $line->target_id;
        if ($itemId <= 0) {
            return;
        }

        // Unités entières (stock_levels.on_hand est INTEGER, CHECK>=0).
        $qty = (int) round((float) $line->qty);
        if ($qty <= 0) {
            return;
        }

        $idempotencyKey = 'purchase_line:'.(int) $line->id;

        // Défense en profondeur (le gate document est le mécanisme primaire).
        if (StockMovement::query()->where('idempotency_key', $idempotencyKey)->exists()) {
            return;
        }

        $level = StockLevel::query()
            ->where('branch_id', $branchId)
            ->where('stockable_type', Item::class)
            ->where('stockable_id', $itemId)
            ->lockForUpdate()
            ->first();

        if (! $level) {
            $created = StockLevel::query()->create([
                'branch_id' => $branchId,
                'stockable_type' => Item::class,
                'stockable_id' => $itemId,
                'on_hand' => 0,
            ]);

            $level = StockLevel::query()
                ->whereKey($created->id)
                ->lockForUpdate()
                ->first();
        }

        $level->forceFill(['on_hand' => (int) $level->on_hand + $qty])->save();

        StockMovement::query()->create([
            'stock_level_id' => (int) $level->id,
            'branch_id' => $branchId,
            'delta' => $qty,
            'reason' => 'manual_in',
            'reference_type' => PurchaseLine::class,
            'reference_id' => (int) $line->id,
            'idempotency_key' => $idempotencyKey,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array{document_id:int, status:string, applied:array<string,int>}
     */
    private function noopResult(PurchaseDocument $document): array
    {
        return [
            'document_id' => (int) $document->id,
            'status' => 'noop',
            'applied' => [
                'raw_material' => 0,
                'stock_item' => 0,
                'charge' => 0,
                'skipped_proposed' => 0,
            ],
        ];
    }
}
