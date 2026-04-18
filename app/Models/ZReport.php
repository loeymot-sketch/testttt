<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * [POS-9.4.6 / POS-GA-F-01] Fiscal Z report (daily close / NF525).
 */
class ZReport extends Model
{
    use HasFactory;

    public const STATUS_OPEN   = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'branch_id',
        'sequence_no',
        'opened_at',
        'closed_at',
        'opened_by',
        'closed_by',
        'total_ht',
        'total_ttc',
        'total_tva',
        'total_by_method',
        'total_by_tax_rate',
        'order_count',
        'cancel_count',
        'refund_count',
        'prev_hash',
        'signature',
        'status',
        'archived_at',
    ];

    protected $casts = [
        'branch_id'         => 'integer',
        'sequence_no'       => 'integer',
        'opened_at'         => 'datetime',
        'closed_at'         => 'datetime',
        'opened_by'         => 'integer',
        'closed_by'         => 'integer',
        'total_ht'          => 'float',
        'total_ttc'         => 'float',
        'total_tva'         => 'float',
        'total_by_method'   => 'array',
        'total_by_tax_rate' => 'array',
        'order_count'       => 'integer',
        'cancel_count'      => 'integer',
        'refund_count'      => 'integer',
        'archived_at'       => 'datetime',
    ];
}
