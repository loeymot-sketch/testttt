<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;

/**
 * [AUDIT-F-008] Reconciliation queue audit row.
 *
 * Persisted by PaymentReconcileController for every reconcile entry
 * (pending → resolved | failed). Provides ops dashboard visibility on
 * orphaned TPE transactions (cash débité côté banque mais order PENDING).
 *
 * Idempotency: UNIQUE(transaction_id). Replays are catched and translated
 * to status='already_paid' downstream.
 */
class PendingPaymentConfirmation extends Model
{
    protected $table = 'pending_payment_confirmations';

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    protected $fillable = [
        'branch_id',
        'order_id',
        'transaction_id',
        'amount_cents',
        'card_type',
        'payment_method',
        'attempted_at',
        'resolved_at',
        'status',
        'retry_count',
        'last_error',
    ];

    protected $casts = [
        'branch_id'      => 'integer',
        'order_id'       => 'integer',
        'amount_cents'   => 'integer',
        'payment_method' => 'integer',
        'attempted_at'   => 'datetime',
        'resolved_at'    => 'datetime',
        'retry_count'    => 'integer',
    ];

    public const STATUS_PENDING  = 'pending';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_FAILED   = 'failed';
}
