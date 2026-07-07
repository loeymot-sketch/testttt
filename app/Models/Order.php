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
    use HasDomainEvents;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'orders';

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
        'pos_customer_name',
        // [C4-CAISSE-TELEPHONE 2026-07-07] Téléphone du client sur une commande téléphone (différée).
        'pos_customer_phone',
        'pos_received_amount',
        'loyalty_customer_code',
        'source_surface',
        // [AUDIT-P50-BUG1] Idempotency key must be fillable so POS orders can be deduplicated
        'idempotency_key',
        // [P11-FZH / F-VERIFY-08-02] parent_order_id pour refund-with-counter-entry mirror orders
        'parent_order_id',
        // [FIX-53-6] loyalty_points_awarded must be fillable for atomic sentinel updates via Eloquent
        'loyalty_points_awarded',
        // [iter14 SPECIALIST-3 / FISCAL-ORPHAN-RETRY] flag set when fiscal_seq
        // alloc fails inside finalizePaidKioskOrder so a retry cron can pick
        // the order up without losing its PAID+PENDING state.
        'fiscal_alloc_error_at',
        // [LOCK_ZREPORT_FISCAL_C33_DELIVERY_VAT — P1 2026-07-07] Instant the NF525
        // fiscal_sequence_no was allocated. For DEFERRED orders (encaissement
        // after creation) this differs from created_at and is the correct
        // Z-membership anchor (ZReportService::aggregate keys the window on
        // COALESCE(fiscal_dated_at, created_at)).
        'fiscal_dated_at',
        // [H.1 P1 AMBER 2026-05-24 / H2-HEAL-02] NF525 6-year traceability:
        // cashier attribution on POS-created orders. orders.user_id stores the
        // CUSTOMER (Walking Customer id=2 for anonymous POS sales), not the
        // operator. creator_id was previously NULL on every POS-created order
        // — making it impossible to answer "which cashier opened order X?"
        // from any persisted column or audit row. Now populated from
        // Auth::id() at Order::create() time inside OrderService::posOrderStore.
        'creator_id',
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
        'pos_received_amount' => 'decimal:6',
        // [iter14 SPECIALIST-3 / FISCAL-ORPHAN-RETRY]
        'fiscal_alloc_error_at' => 'datetime',
        // [LOCK_ZREPORT_FISCAL_C33_DELIVERY_VAT — P1 2026-07-07] fiscal allocation instant
        'fiscal_dated_at' => 'datetime',
        // [H.1 P1 AMBER 2026-05-24 / H2-HEAL-02] cashier attribution
        'creator_id' => 'integer',
        // [KITCHEN-TIMING 2026-07-03] horodatages du temps réel de préparation cuisine
        'accepted_at' => 'datetime',
        'preparing_at' => 'datetime',
        'prepared_at' => 'datetime',
    ];

    /**
     * [GOAL-2026-05-29 FISCAL-P1] NF525 HT base for the Z-report.
     *
     * There is NO stored `total_ht` column, so
     * ZReportService::applyOrderToTotals fell back to `subtotal` — which in
     * tax-inclusive mode is a TTC figure — making the signed + 6-year-archived
     * Z carry a wrong decomposition (total_ht + total_tva != total_ttc). We
     * derive HT from the SAME amount the Z uses for TTC (`total`) minus the
     * TVA, so the legal identity TTC = HT + TVA holds BY CONSTRUCTION in every
     * pricing mode and naturally accounts for discount/delivery. Read-only
     * virtual attribute (NOT in $appends) — only ZReportService consumes it;
     * total_ttc/total_tva were already correct, only the HT label was wrong.
     */
    public function getTotalHtAttribute(): float
    {
        return round(
            (float) ($this->attributes['total'] ?? 0) - (float) ($this->attributes['total_tax'] ?? 0),
            2
        );
    }

    protected static function boot(): void
    {
        parent::boot();
        static::addGlobalScope(new BranchScope);

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
                .'hard deletes on child rows (address, coupon) that cannot be '
                .'rebuilt. A soft-deleted order is kept for audit only. '
                .'To reopen an order, create a new one and reference the '
                .'soft-deleted id in its notes.'
            );
        });

        // [kds/sprint-2 B-4] Auto-derive source_surface='delivery' when the
        // order is order_type=DELIVERY and no source_surface is set yet.
        // V1 has no aggregator-webhook ingestion path — the column is filled
        // by whichever future path creates the delivery order (admin
        // dashboard, manual entry, direct platform integration). This hook
        // guarantees the KDS sees source_surface='delivery' on those rows
        // and renders the LIVRAISON chip without per-writer plumbing.
        static::creating(function (self $order) {
            if (empty($order->source_surface) && (int) $order->order_type === \App\Enums\OrderType::DELIVERY) {
                $order->source_surface = 'delivery';
            }
        });

        // [ULTRA-AUDIT 2026-07-04 — P2 timing cuisine CENTRALISÉ] Horodatage du temps réel de préparation
        // posé au niveau MODÈLE (même philosophie que le hook source_surface ci-dessus : « sans per-writer
        // plumbing ») pour qu'AUCUN chemin ne l'oublie. BUG trouvé par l'audit intersections : le stamp
        // vivait dans les 2 changeStatus (POS+KDS) MAIS les flux DOMINANTS créent la commande directement
        // à ACCEPT/PREPARING (auto-prepare borne Plan B, POS direct, counter-collect) SANS passer par
        // changeStatus → accepted_at jamais posé → actual_prep_seconds NULL sur ~100 % du volume (0/3092
        // en base). Ici on horodate sur CHAQUE save quand le statut est dans le flux cuisine, en cascade
        // first-write-wins (atteindre un statut plus avancé implique les précédents). Remplace/centralise
        // les stamps explicites des 2 changeStatus (retirés).
        static::saving(function (self $order) {
            $s = (int) $order->status;
            $inKitchenFlow = [
                \App\Enums\OrderStatus::ACCEPT,
                \App\Enums\OrderStatus::PREPARING,
                \App\Enums\OrderStatus::PREPARED,
                \App\Enums\OrderStatus::OUT_FOR_DELIVERY,
                \App\Enums\OrderStatus::DELIVERED,
            ];
            if (! in_array($s, $inKitchenFlow, true)) {
                return; // PENDING / CANCELED / REJECTED / RETURNED : pas d'horodatage cuisine.
            }
            if ($order->accepted_at === null) {
                $order->accepted_at = now();
            }
            if ($order->preparing_at === null && in_array($s, [
                \App\Enums\OrderStatus::PREPARING, \App\Enums\OrderStatus::PREPARED,
                \App\Enums\OrderStatus::OUT_FOR_DELIVERY, \App\Enums\OrderStatus::DELIVERED,
            ], true)) {
                $order->preparing_at = now();
            }
            if ($order->prepared_at === null && in_array($s, [
                \App\Enums\OrderStatus::PREPARED, \App\Enums\OrderStatus::OUT_FOR_DELIVERY,
                \App\Enums\OrderStatus::DELIVERED,
            ], true)) {
                $order->prepared_at = now();
            }
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

    /**
     * [F-SPLIT-PAYMENT-001] Multi-tender breakdown.
     *
     * `OrderDetailsResource::buildPaymentsBreakdown()` lit cette relation
     * pour rendre le receipt avec la liste des tranches (mode/amount/
     * change/reference). Quand vide, le resource retombe sur le path
     * legacy single-tender (`pos_payment_method` + `pos_received_amount`).
     */
    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrderPayment::class, 'order_id');
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

    /**
     * [DASH-NET-01 heal 2026-06-01, owner decision "net, agree with the Z"]
     * Net realized-revenue rows for management reporting. Mirrors the signed
     * ZReportService netting (LOCK_ZREPORT_REFUND_NETTING): include PAID orders
     * NOT in a terminal status (CANCELED/REJECTED/RETURNED) — which drops a
     * cancelled-but-paid order — PLUS the counter-entry refund mirrors
     * (status=RETURNED + parent_order_id) whose `total` is already negated, so
     * summing `total` over this scope nets a refunded order back to ~0.
     * Intended use: ->realizedRevenue()->sum('total'). Counts that want
     * "placed orders" should instead exclude mirrors via whereNull('parent_order_id').
     */
    public function scopeRealizedRevenue($query)
    {
        return $query->where(function ($q) {
            $q->where(function ($paid) {
                // [SELF-AUDIT R3 P1 2026-07-05 — CA gonflé vs Z signé] Ce scope est DOCUMENTÉ « mirrors the
                // signed ZReportService netting » ; or une commande Uber (PAID, non-terminale, mais
                // fiscal_sequence_no NULL car uber.fiscalize=false, facturée SÉPARÉMENT par l'agrégateur)
                // COMPTAIT dans le CA management alors que le Z l'EXCLUT → dashboard/sales-report/EOD >
                // Z signé. Fix CIBLÉ : exclure le SEUL canal non-fiscalisé par design (source_surface=
                // 'uber_eats'), sans casser le contrat « PAID = CA réalisé » des ventes POS/kiosk/livraison
                // (toutes fiscalisées en prod). NULL source_surface conservé (ventes internes). Les miroirs
                // de remboursement (branche ci-dessous) restent comptés (total négaté).
                $paid->where('payment_status', \App\Enums\PaymentStatus::PAID)
                    ->whereNotIn('status', [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED])
                    ->where(function ($src) {
                        $src->whereNull('source_surface')->orWhere('source_surface', '!=', 'uber_eats');
                    });
            })->orWhere(function ($mirror) {
                $mirror->where('status', OrderStatus::RETURNED)
                    ->whereNotNull('parent_order_id');
            });
        });
    }

    /**
     * [SALES-NET-01 / DASH-NET-01 heal 2026-06-01] Collection-side mirror of
     * scopeRealizedRevenue, for surfaces that operate on already-fetched models
     * (PDF blade, Excel export, salesReportOverview collection). MUST stay in
     * lock-step with scopeRealizedRevenue's SQL predicate.
     */
    public static function isRealizedRevenueRow($o): bool
    {
        $terminal = [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED];
        // [SELF-AUDIT R3 P1 2026-07-05] Miroir EXACT du scope : une vente live compte dans le CA
        // « réconcilié Z » SAUF le canal Uber (source_surface='uber_eats', non fiscalisé par design).
        $isLivePaidSale = (int) $o->payment_status === \App\Enums\PaymentStatus::PAID
            && ! in_array((int) $o->status, $terminal, true)
            && $o->source_surface !== 'uber_eats';
        $isRefundMirror = (int) $o->status === OrderStatus::RETURNED
            && $o->parent_order_id !== null;

        return $isLivePaidSale || $isRefundMirror;
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
