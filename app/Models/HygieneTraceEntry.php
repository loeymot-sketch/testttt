<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;

/** [CAYENNE HYGIÈNE 2026-10-17] Une ligne de traçabilité amont (produit · fournisseur · lot · DLC · BL). Jamais modifiée ni supprimée : au plus annulée. */
class HygieneTraceEntry extends Model
{
    protected $table = 'hygiene_trace_entries';

    protected $fillable = [
        'branch_id', 'product_name', 'dlc_product_id', 'supplier_id', 'supplier_name', 'reception_id', 'delivery_note_no', 'lot', 'dlc_date',
        'dlc_kind', 'status', 'note', 'attachment_id', 'traced_at', 'timezone', 'user_id', 'operator_name', 'voided_at', 'voided_by', 'void_reason', 'client_ref',
    ];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'dlc_product_id' => 'integer',
        'supplier_id' => 'integer',
        'reception_id' => 'integer',
        'dlc_date' => 'date:Y-m-d',
        'attachment_id' => 'integer',
        'traced_at' => 'datetime',
        'voided_at' => 'datetime',
        'user_id' => 'integer',
        'voided_by' => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }
}
