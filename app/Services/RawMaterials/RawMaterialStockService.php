<?php

namespace App\Services\RawMaterials;

use App\Models\RawMaterialMovement;
use App\Models\RawMaterialStock;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P1a] Moteur de mutation du stock
 * matière — miroir du pattern StockService (transaction + lockForUpdate +
 * mouvements append-only idempotents).
 *
 * Trois opérations :
 *  - receive() : ENTRÉE de stock (delta positif = +|qty|).
 *  - consume()  : CONSOMMATION (delta négatif = -|qty|). on_hand PEUT passer
 *                 négatif (stock matière signé — pas de garde non-négatif).
 *  - adjust()   : AJUSTEMENT INVENTAIRE vers une CIBLE absolue (« on a compté
 *                 X »). Le mouvement enregistre l'écart signé (cible - courant).
 *                 Choix documenté : sémantique « vers une cible » (comptage
 *                 correcteur mensuel du plan B3/P4) plutôt que delta direct —
 *                 c'est l'usage métier réel et ça rend l'écart auditable.
 *
 * IDEMPOTENCE : si un mouvement portant le même triplet (source_type,
 * source_id, raw_material_id) existe déjà → NO-OP, retourne le stock courant.
 * Ne s'applique QUE si source_type ET source_id sont non-nuls : un mouvement
 * manuel (source nulle) est toujours appliqué (sinon deux entrées manuelles
 * successives se dédupliqueraient à tort).
 *
 * Branch : `branch_id` = 1 par défaut (V1 mono-branche). Hard-scope explicite.
 */
class RawMaterialStockService
{
    private const SCALE = 3;

    /**
     * ENTRÉE de stock : crédite |$qty| (toujours positif).
     */
    public function receive(
        int $rawMaterialId,
        float $qty,
        string $reason,
        ?string $sourceType = null,
        ?int $sourceId = null,
        array $meta = [],
        int $branchId = 1
    ): RawMaterialStock {
        $amount = abs($qty);

        return $this->mutate(
            $rawMaterialId,
            $branchId,
            $reason,
            $sourceType,
            $sourceId,
            $meta,
            fn (float $current): float => $current + $amount
        );
    }

    /**
     * CONSOMMATION : débite |$qty| (delta négatif). on_hand peut devenir négatif.
     */
    public function consume(
        int $rawMaterialId,
        float $qty,
        string $reason,
        ?string $sourceType = null,
        ?int $sourceId = null,
        array $meta = [],
        int $branchId = 1
    ): RawMaterialStock {
        $amount = abs($qty);

        return $this->mutate(
            $rawMaterialId,
            $branchId,
            $reason,
            $sourceType,
            $sourceId,
            $meta,
            fn (float $current): float => $current - $amount
        );
    }

    /**
     * AJUSTEMENT INVENTAIRE : positionne on_hand sur $targetOnHand (cible
     * absolue). Le mouvement enregistre l'écart signé (cible - courant).
     */
    public function adjust(
        int $rawMaterialId,
        float $targetOnHand,
        string $reason,
        ?string $sourceType = null,
        ?int $sourceId = null,
        array $meta = [],
        int $branchId = 1
    ): RawMaterialStock {
        return $this->mutate(
            $rawMaterialId,
            $branchId,
            $reason,
            $sourceType,
            $sourceId,
            $meta,
            fn (float $current): float => $targetOnHand
        );
    }

    /**
     * Cœur transactionnel : verrouille (ou crée) la row stock, applique
     * l'idempotence, calcule la cible via $computeTarget($current), écrit le
     * nouveau on_hand + un mouvement append-only.
     *
     * @param  Closure(float):float  $computeTarget
     */
    private function mutate(
        int $rawMaterialId,
        int $branchId,
        string $reason,
        ?string $sourceType,
        ?int $sourceId,
        array $meta,
        Closure $computeTarget
    ): RawMaterialStock {
        return DB::transaction(function () use (
            $rawMaterialId,
            $branchId,
            $reason,
            $sourceType,
            $sourceId,
            $meta,
            $computeTarget
        ): RawMaterialStock {
            $stock = $this->lockOrCreateStock($rawMaterialId, $branchId);

            if ($this->isDuplicateSource($rawMaterialId, $sourceType, $sourceId)) {
                return $stock;
            }

            $current = (float) $stock->on_hand;
            $target = round($computeTarget($current), self::SCALE);
            $delta = round($target - $current, self::SCALE);

            $stock->forceFill(['on_hand' => $target])->save();

            RawMaterialMovement::create([
                'raw_material_id' => $rawMaterialId,
                'branch_id' => $branchId,
                'delta' => $delta,
                'reason' => $reason,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'meta' => $meta === [] ? null : $meta,
                'created_at' => now(),
            ]);

            return $stock;
        });
    }

    private function lockOrCreateStock(int $rawMaterialId, int $branchId): RawMaterialStock
    {
        $stock = RawMaterialStock::query()
            ->where('raw_material_id', $rawMaterialId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->first();

        if ($stock) {
            return $stock;
        }

        RawMaterialStock::firstOrCreate(
            ['raw_material_id' => $rawMaterialId, 'branch_id' => $branchId],
            ['on_hand' => 0],
        );

        return RawMaterialStock::query()
            ->where('raw_material_id', $rawMaterialId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Idempotence : un mouvement portant le même (source_type, source_id,
     * raw_material_id) existe déjà. Seulement quand la source est pleinement
     * qualifiée (les deux non-nuls) — sinon on n'a pas de clé de rejeu fiable.
     */
    private function isDuplicateSource(int $rawMaterialId, ?string $sourceType, ?int $sourceId): bool
    {
        if ($sourceType === null || $sourceId === null) {
            return false;
        }

        return RawMaterialMovement::query()
            ->where('raw_material_id', $rawMaterialId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->exists();
    }
}
