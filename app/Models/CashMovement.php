<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * [AUDIT-F-003] Cash movement — atomic cash event tied to a session.
 *
 * Types : order_payment | cashback | drawer_open | drawer_close | adjustment
 * Direction : in | out
 *
 * BranchScope : isolation forte. Quand recordMovement est appelé via
 * PaymentService hook (cas standard order PAID cash), le movement hérite
 * du branch_id de l'order — qui est identique au branch_id de la session
 * (vérifié par le service avant insertion).
 */
class CashMovement extends Model
{
    use HasFactory;

    public const TYPE_ORDER_PAYMENT = 'order_payment';
    public const TYPE_CASHBACK      = 'cashback';
    public const TYPE_DRAWER_OPEN   = 'drawer_open';
    public const TYPE_DRAWER_CLOSE  = 'drawer_close';
    public const TYPE_ADJUSTMENT    = 'adjustment';

    public const DIRECTION_IN  = 'in';
    public const DIRECTION_OUT = 'out';

    protected $table = 'cash_movements';

    protected $fillable = [
        'cash_drawer_session_id',
        'branch_id',
        'order_id',
        'type',
        'amount',
        'direction',
        'notes',
    ];

    protected $casts = [
        'id'                     => 'integer',
        'cash_drawer_session_id' => 'integer',
        'branch_id'              => 'integer',
        'order_id'               => 'integer',
        'type'                   => 'string',
        'amount'                 => 'decimal:2',
        'direction'              => 'string',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::addGlobalScope(new BranchScope());
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashDrawerSession::class, 'cash_drawer_session_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Signed amount: + si IN, − si OUT. Utile pour aggrégations expected_cash.
     */
    public function signedAmount(): float
    {
        $sign = $this->direction === self::DIRECTION_IN ? 1.0 : -1.0;
        return $sign * (float) $this->amount;
    }
}
