<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * [AUDIT-P47-BUG1] Loyalty transaction ledger — immutable audit trail of all point movements.
 * Each row records one earn, redeem, manual adjustment, or expire event.
 * This enables:
 *   - Per-channel analytics (kiosk vs POS vs web vs mobile)
 *   - Customer history feed (mobile app, web account)
 *   - Dispute resolution
 *   - Future: tier calculation, expiry logic
 */
class LoyaltyTransaction extends Model
{
    protected $table = 'loyalty_transactions';

    protected $fillable = [
        'user_id',
        'loyalty_code',
        'order_id',
        'type',
        'points',
        'balance_after',
        'source_surface',
        'description',
        // [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19] Cashier audit trail —
        // links a POS redemption to the open CashDrawerSession at the time
        // of redemption. Null for kiosk / web / mobile.
        'pos_session_id',
    ];

    protected $casts = [
        'points'         => 'integer',
        'balance_after'  => 'integer',
        'pos_session_id' => 'integer',
    ];

    /**
     * User who owns these points.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Order that triggered this transaction (earn/redeem).
     * May be null for manual adjustments or pre-order redemptions.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
