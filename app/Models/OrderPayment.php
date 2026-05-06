<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F-SPLIT-PAYMENT-001 — Tranche de paiement multi-mode (split payment).
 *
 * Une commande POS peut avoir 1..N rows {@see OrderPayment} qui détaillent
 * les modes de paiement combinés (cash + card + ticket-restaurant…). Le
 * cumul des `amount` doit ≥ `Order::$total` (validé par
 * {@see \App\Services\Payments\SplitPaymentService}).
 *
 * Le helper {@see \App\Http\Resources\OrderDetailsResource::buildPaymentsBreakdown()}
 * lit déjà `$order->payments` quand la relation existe — l'attribut
 * accesseur `getPaymentMethodAttribute()` mime le contrat legacy
 * `payment_method` pour que la sérialisation receipt fonctionne sans patch.
 */
class OrderPayment extends Model
{
    use HasFactory;

    protected $table = 'order_payments';

    protected $fillable = [
        'order_id',
        'branch_id',
        'mode',
        'amount',
        'tendered',
        'change_amount',
        'reference',
        'paid_at',
    ];

    protected $casts = [
        'mode'           => 'int',
        'branch_id'      => 'int',
        'amount'         => 'decimal:2',
        'tendered'       => 'decimal:2',
        'change_amount'  => 'decimal:2',
        'paid_at'        => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Receipt-friendly accessor. Aligne le shape sur le contrat existant
     * `OrderDetailsResource::buildPaymentsBreakdown()` qui lit `$p->method`
     * ou `$p->payment_method`.
     */
    public function getPaymentMethodAttribute(): int
    {
        return (int) ($this->attributes['mode'] ?? 0);
    }

    public function getMethodAttribute(): int
    {
        return (int) ($this->attributes['mode'] ?? 0);
    }
}
