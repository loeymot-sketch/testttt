<?php

namespace App\Models;

use App\Contracts\BroadcastableOrder;
use App\Enums\OrderStatus;
use App\Models\Scopes\BranchScope;
use App\Traits\HasDomainEvents;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model implements BroadcastableOrder
{
    use HasFactory;
    use HasDomainEvents;
    use SoftDeletes;

    protected $table = "orders";
    protected $fillable = [
        'order_serial_no',
        'queue_number',
        'business_date',
        'token',
        'user_id',
        'branch_id',
        'subtotal',
        'discount',
        'delivery_charge',
        'total_tax',
        'total',
        'order_type',
        'order_datetime',
        'delivery_time',
        'preparation_time',
        'is_advance_order',
        'address',
        'payment_method',
        'payment_status',
        'status',
        'dining_table_id',
        'source',
        'pos_payment_method',
        'pos_payment_note',
        'pos_received_amount',
        'loyalty_customer_code',
        'source_surface',
        // [AUDIT-P50-BUG1] Idempotency key must be fillable so POS orders can be deduplicated
        'idempotency_key',
        // [FIX-53-6] loyalty_points_awarded must be fillable for atomic sentinel updates via Eloquent
        'loyalty_points_awarded',
    ];

    protected $casts = [
        'id' => 'integer',
        'order_serial_no' => 'string',
        'business_date' => 'date:Y-m-d',
        'token' => 'string',
        'user_id' => 'integer',
        'branch_id' => 'integer',
        'subtotal' => 'decimal:6',
        'discount' => 'decimal:6',
        'delivery_charge' => 'decimal:6',
        'total_tax' => 'decimal:6',
        'total' => 'decimal:6',
        'order_type' => 'integer',
        'order_datetime' => 'datetime',
        'delivery_time' => 'string',
        'preparation_time' => 'integer',
        'is_advance_order' => 'integer',
        'payment_method' => 'integer',
        'payment_status' => 'integer',
        'status' => 'integer',
        'dining_table_id' => 'integer',
        'source' => 'integer',
        'pos_payment_method' => 'integer',
        'pos_payment_note' => 'string',
        'pos_received_amount' => 'decimal:6'
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::addGlobalScope(new BranchScope());

        // [POS-9-H.3.5 / F-A7]
        // OrderService::destroy() soft-deletes the Order itself but
        // HARD-deletes its related OrderAddress and OrderCoupon (those
        // models don't use the SoftDeletes trait). Re-hydrating the
        // Order via $order->restore() would leave the aggregate in a
        // permanently inconsistent state: missing address line, missing
        // coupon discount, but a Z/X report that still counts its total.
        //
        // Rather than add SoftDeletes to those two child models (which
        // would pollute every query and is a schema change we can't
        // retrofit onto live branches safely), we block restore at the
        // model level. Soft-delete becomes a ONE-WAY audit trail: the
        // row is retained for forensic purposes (NF525) but the
        // aggregate is never resurrected.
        static::restoring(function (self $order) {
            throw new \RuntimeException(
                'Order::restore() is disabled — OrderService::destroy() performs '
                . 'hard deletes on child rows (address, coupon) that cannot be '
                . 'rebuilt. A soft-deleted order is kept for audit only. '
                . 'To reopen an order, create a new one and reference the '
                . 'soft-deleted id in its notes.'
            );
        });
    }

    public function orderItems(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function items(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'order_items')->withTrashed();
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function address(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(OrderAddress::class);
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function deliveryBoy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'delivery_boy_id', 'id');
    }

    public function coupon(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(OrderCoupon::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', OrderStatus::PENDING);
    }

    public function scopeAccept($query)
    {
        return $query->where('status', OrderStatus::ACCEPT);
    }

    public function scopePreparing($query)
    {
        return $query->where('status', OrderStatus::PREPARING);
    }

    public function scopePrepared($query)
    {
        return $query->where('status', OrderStatus::PREPARED);
    }

    public function scopeOutForDelivery($query)
    {
        return $query->where('status', OrderStatus::OUT_FOR_DELIVERY);
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', OrderStatus::DELIVERED);
    }

    public function scopeCanceled($query)
    {
        return $query->where('status', OrderStatus::CANCELED);
    }

    public function scopeReturned($query)
    {
        return $query->where('status', OrderStatus::RETURNED);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', OrderStatus::REJECTED);
    }

    public function transaction(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Transaction::class);
    }

    public function diningTable(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(DiningTable::class);
    }
}
