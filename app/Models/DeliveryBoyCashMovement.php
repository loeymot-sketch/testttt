<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * [V1.0.2 Sub-6.3 — 2026-05-18] Delivery boy cash movement — atomic event tied to a session.
 *
 * Types (semantic difference vs POS `CashMovement`) :
 *   - order_collect : livreur encaisse cash chez le client (NEW vs POS `order_payment`)
 *   - change_given  : livreur rend la monnaie (NEW vs POS `cashback`)
 *   - drawer_open   : audit ouverture session
 *   - drawer_close  : audit fermeture session
 *   - adjustment    : régularisation manuelle hors livraison
 *
 * Direction : in | out
 *
 * BranchScope : isolation forte. Le movement hérite du branch_id de la session.
 * NF525 immutability : DELETE forbidden via DB trigger (migration 120300).
 */
class DeliveryBoyCashMovement extends Model
{
    use HasFactory;

    // Movement types — distinct namespace from POS to avoid Z-report collisions.
    public const TYPE_ORDER_COLLECT = 'order_collect';
    public const TYPE_CHANGE_GIVEN  = 'change_given';
    public const TYPE_DRAWER_OPEN   = 'drawer_open';
    public const TYPE_DRAWER_CLOSE  = 'drawer_close';
    public const TYPE_ADJUSTMENT    = 'adjustment';

    public const DIRECTION_IN  = 'in';
    public const DIRECTION_OUT = 'out';

    protected $table = 'delivery_boy_cash_movements';

    protected $fillable = [
        'delivery_boy_cash_session_id',
        'branch_id',
        'order_id',
        'type',
        'amount',
        'direction',
        'notes',
    ];

    protected $casts = [
        'id'                            => 'integer',
        'delivery_boy_cash_session_id'  => 'integer',
        'branch_id'                     => 'integer',
        'order_id'                      => 'integer',
        'type'                          => 'string',
        'amount'                        => 'decimal:2',
        'direction'                     => 'string',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::addGlobalScope(new BranchScope());
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(DeliveryBoyCashSession::class, 'delivery_boy_cash_session_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Signed amount : + si IN, − si OUT. Utile pour aggrégations expected.
     */
    public function signedAmount(): float
    {
        $sign = $this->direction === self::DIRECTION_IN ? 1.0 : -1.0;

        return $sign * (float) $this->amount;
    }
}
