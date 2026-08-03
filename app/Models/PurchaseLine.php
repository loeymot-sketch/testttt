<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3a] Ligne d'un document d'achat.
 *
 * `target_type` route l'application en stock (PurchaseService::validateDocument) :
 *  - raw_material : `target_id` = raw_material_id → RawMaterialStockService::receive
 *                   + recalcul avg_cost (moyenne pondérée).
 *  - stock_item   : `target_id` = item_id (boisson revendue à l'unité) →
 *                   incrémente stock_levels (+qty unités).
 *  - charge       : `target_id` = null → aucun stock, comptabilisé seulement.
 *
 * `target_id` est polymorphe (aucune FK). PAS de branch_id : la ligne hérite du
 * document parent → aucune exemption BranchScope requise.
 */
class PurchaseLine extends Model
{
    public const STATUS_PROPOSED = 'proposed';
    public const STATUS_VALIDATED = 'validated';

    public const TARGET_RAW_MATERIAL = 'raw_material';
    public const TARGET_STOCK_ITEM = 'stock_item';
    public const TARGET_CHARGE = 'charge';

    protected $fillable = [
        'purchase_document_id',
        'raw_label',
        'qty',
        'unit',
        'unit_price',
        'tva_rate',
        'target_type',
        'target_id',
        'status',
        'score',
        'matched',
    ];

    protected $casts = [
        'id' => 'integer',
        'purchase_document_id' => 'integer',
        'qty' => 'decimal:3',
        'unit_price' => 'decimal:4',
        'tva_rate' => 'decimal:2',
        'target_id' => 'integer',
        // [P3c] Confiance IA surfacée à l'écran de scan (le classifieur les calcule).
        'score' => 'decimal:3',
        'matched' => 'boolean',
    ];

    public function document()
    {
        return $this->belongsTo(PurchaseDocument::class, 'purchase_document_id');
    }
}
