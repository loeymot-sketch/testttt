<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderCoupon extends Model
{
    use HasFactory;

    protected $table = "order_coupons";
    protected $fillable = ['order_id', 'coupon_id', 'user_id', 'discount'];
    protected $casts = [
        'id'        => 'integer',
        'order_id'  => 'integer',
        'coupon_id' => 'integer',
        'user_id'   => 'integer',
        'discount'  => 'decimal:6',
    ];

    /**
     * [P1-D 2026-08-04] La commande porteuse — sert à exclure les commandes ANNULÉES du
     * comptage d'usage coupon (une tentative abandonnée ne doit pas brûler le coupon).
     */
    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
