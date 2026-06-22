<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiningTableAuditLog extends Model
{
    protected $table = 'dining_table_audit_logs';

    public $timestamps = false;

    protected $fillable = [
        'branch_id',
        'user_id',
        'action',
        'source_table_id',
        'target_table_id',
        'order_id',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'user_id' => 'integer',
        'source_table_id' => 'integer',
        'target_table_id' => 'integer',
        'order_id' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];
}
