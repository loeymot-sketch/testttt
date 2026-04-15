<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderStatusTransition extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'order_type',
        'from_status',
        'to_status',
        'actor_id',
        'actor_type',
        'reason',
        'correlation_id',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];
}
