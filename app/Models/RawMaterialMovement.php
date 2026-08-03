<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P1a] Mouvement de stock matière —
 * ledger APPEND-ONLY (plan amendement #3). `delta` SIGNÉ (+ entrée, -
 * consommation, ± ajustement). Idempotence portée côté service par le triplet
 * (source_type, source_id, raw_material_id).
 *
 * Miroir de StockMovement : append-only garde (update/delete interdits),
 * created_at seul (UPDATED_AT = null). Pas de BranchScope global : hard-scope
 * explicite par les appelants.
 */
class RawMaterialMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'raw_material_id',
        'branch_id',
        'delta',
        'reason',
        'source_type',
        'source_id',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'raw_material_id' => 'integer',
        'branch_id' => 'integer',
        'delta' => 'decimal:3',
        'source_id' => 'integer',
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('raw_material_movements is append-only.');
        });

        static::deleting(function (): void {
            throw new \LogicException('raw_material_movements is append-only.');
        });
    }

    public function rawMaterial()
    {
        return $this->belongsTo(RawMaterial::class);
    }
}
