<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderQuote extends Model
{
    use HasFactory;

    protected $fillable = [
        'quote_token',
        'branch_id',
        'actor_id',
        'customer_id',
        'surface',
        'intent_hash',
        'hmac_signature',
        'canonical_payload',
        'subtotal',
        'discount',
        'total_tax',
        'delivery_charge',
        'total_ttc',
        'currency',
        'expires_at',
        'consumed_at',
        'consumed_by_user_id',
        'consumed_order_id',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'actor_id' => 'integer',
        'customer_id' => 'integer',
        'canonical_payload' => 'array',
        'subtotal' => 'decimal:6',
        'discount' => 'decimal:6',
        'total_tax' => 'decimal:6',
        'delivery_charge' => 'decimal:6',
        'total_ttc' => 'decimal:6',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'consumed_by_user_id' => 'integer',
        'consumed_order_id' => 'integer',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte(now());
    }
}
