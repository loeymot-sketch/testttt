<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosParkedOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'user_id',
        'label',
        'payload_json',
        'preview_total',
        'items_count',
        'idempotency_token',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'user_id' => 'integer',
        'payload_json' => 'array',
        'preview_total' => 'decimal:2',
        'items_count' => 'integer',
    ];
}
