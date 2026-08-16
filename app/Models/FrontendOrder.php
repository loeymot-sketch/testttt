<?php

namespace App\Models;

use App\Contracts\BroadcastableOrder;
use App\Enums\OrderStatus;
use App\Models\Scopes\BranchScope;
use App\Traits\HasDomainEvents;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FrontendOrder extends Model implements BroadcastableOrder
{
    use HasFactory;
    use HasDomainEvents;
    use SoftDeletes;

    protected $table = "orders";

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);

        // [kds/sprint-2 B-4] Mirror of Order::creating hook. Both models target
        // the `orders` table; whichever instance is used to create a row must
        // resolve source_surface='delivery' for order_type=DELIVERY when no
        // surface is explicitly set. Idempotent: skipped if any value is
        // already present (kiosk + pos writers fill it explicitly upstream).
        static::creating(function (self $order) {
            if (empty($order->source_surface) && (int) $order->order_type === \App\Enums\OrderType::DELIVERY) {
                $order->source_surface = 'delivery';
            }
        });

        // [T-C SUIVI-CLIENT 2026-08-16] Mirror of Order::creating tracking_token hook
        // (see Order.php). CRITICAL: FrontendOrderService::myOrderStore() — the actual
        // kiosk/web order-creation path — writes via FrontendOrder::create(), NOT
        // Order::create(). Eloquent model events are per-class, not per-table: without
        // this mirror, every kiosk/web order (i.e. exactly the orders the customer
        // tracking feature exists for) would get tracking_token=NULL despite the
        // hook existing on Order. Only POS/caisse (OrderService, Order::create) hit
        // the original hook — the one surface that never needs a customer link.
        static::creating(function (self $order) {
            if (empty($order->tracking_token)) {
                $order->tracking_token = \Illuminate\Support\Str::random(48);
            }
        });
    }
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
        'total',
        'total_tax',
        'order_type',
        'order_datetime',
        'delivery_time',
        'preparation_time',
        'is_advance_order',
        'scheduled_at',
        'address',
        'payment_method',
        'payment_status',
        'pos_payment_method',
        'pos_payment_note',
        'pos_received_amount',
        'status',
        'dining_table_id',
        'source',
        'idempotency_key',
        'loyalty_points_awarded',
        // [AUDIT-P50-BUG3] source_surface must be fillable for analytics/tracing (kiosk, web, mobile)
        'source_surface',
        'loyalty_customer_code',
        'transaction_id',
        'card_type',
        // [iter14 SPECIALIST-3 / FISCAL-ORPHAN-RETRY] flag set when fiscal_seq
        // alloc fails inside finalizePaidKioskOrder so a retry cron can pick
        // the order up without losing its PAID+PENDING state.
        'fiscal_alloc_error_at',
    ];

    protected $casts = [
        'id'               => 'integer',
        'order_serial_no'  => 'string',
        'business_date'    => 'date:Y-m-d',
        'token'            => 'string',
        'user_id'          => 'integer',
        'branch_id'        => 'integer',
        'subtotal'         => 'decimal:6',
        'discount'         => 'decimal:6',
        'delivery_charge'  => 'decimal:6',
        'total'            => 'decimal:6',
        'order_type'       => 'integer',
        'order_datetime'   => 'datetime',
        'delivery_time'    => 'string',
        'preparation_time' => 'integer',
        'is_advance_order' => 'integer',
        'scheduled_at'     => 'datetime',
        'payment_method'   => 'integer',
        'payment_status'   => 'integer',
        'pos_payment_method' => 'integer',
        'pos_payment_note' => 'string',
        'pos_received_amount' => 'decimal:6',
        'status'           => 'integer',
        'dining_table_id'  => 'integer',
        'source'           => 'integer',
        // [iter14 SPECIALIST-3 / FISCAL-ORPHAN-RETRY]
        'fiscal_alloc_error_at' => 'datetime',
    ];

    public function orderItems(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'id');
    }

    public function items(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'order_items');
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function address(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(OrderAddress::class, 'order_id', 'id');
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
        return $this->hasOne(OrderCoupon::class, 'order_id', 'id');
    }

    public function scopePending($query)
    {
        return $query->where('status', OrderStatus::PENDING);
    }

    public function scopePreparing($query)
    {
        return $query->where('status', OrderStatus::PREPARING);
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
        return $this->hasOne(Transaction::class, 'order_id', 'id');
    }

    public function diningTable(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FrontendDiningTable::class);
    }
}
