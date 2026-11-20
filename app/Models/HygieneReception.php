<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;

/** [CAYENNE HYGIÈNE 2026-10-17] Une réception de marchandises au journal. Jamais modifiée ni supprimée : au plus annulée. */
class HygieneReception extends Model
{
    public const STATUS_OK = 'ok';
    public const STATUS_ALERT = 'alert';

    protected $table = 'hygiene_receptions';

    protected $fillable = [
        'branch_id', 'supplier_id', 'supplier_name', 'delivery_note_no', 'delivered_by', 'temperatures', 'checks', 'status', 'decision', 'note',
        'attachment_id', 'attachment_ids', 'received_at', 'timezone', 'user_id', 'operator_name', 'voided_at', 'voided_by', 'void_reason', 'client_ref',
    ];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'supplier_id' => 'integer',
        'temperatures' => 'array',
        'checks' => 'array',
        'attachment_id' => 'integer',
        'attachment_ids' => 'array',
        'received_at' => 'datetime',
        'voided_at' => 'datetime',
        'user_id' => 'integer',
        'voided_by' => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }
}
