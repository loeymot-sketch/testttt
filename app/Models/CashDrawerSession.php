<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * [AUDIT-F-003] Cash drawer session — service d'un caissier.
 *
 * Statuts (string) :
 *   - open       : encaissement en cours
 *   - closed     : closing_amount déclaré, expected/variance pas encore figés
 *   - reconciled : reconcile() a calculé la variance et l'a persistée
 *
 * Branch isolation via BranchScope (cohérent avec Order/FrontendOrder).
 * Aucun bypass dans ce model — les requêtes admin cross-branch doivent
 * passer par withoutGlobalScope explicite + commentaire.
 */
class CashDrawerSession extends Model
{
    use HasFactory;

    public const STATUS_OPEN       = 'open';
    public const STATUS_CLOSED     = 'closed';
    public const STATUS_RECONCILED = 'reconciled';

    protected $table = 'cash_drawer_sessions';

    protected $fillable = [
        'branch_id',
        'opened_by_user_id',
        'opened_at',
        'closed_at',
        'opening_amount',
        'closing_amount',
        'expected_closing_amount',
        'variance',
        'variance_reason',
        'status',
        // [Sprint H2 F-10 2026-05-17] Per-row actor IDs for forensic clarity.
        'closed_by_user_id',
        'reconciled_by_user_id',
    ];

    protected $casts = [
        'id'                      => 'integer',
        'branch_id'               => 'integer',
        'opened_by_user_id'       => 'integer',
        'opened_at'               => 'datetime',
        'closed_at'               => 'datetime',
        'opening_amount'          => 'decimal:2',
        'closing_amount'          => 'decimal:2',
        'expected_closing_amount' => 'decimal:2',
        'variance'                => 'decimal:2',
        'status'                  => 'string',
        // [Sprint H2 F-10 2026-05-17] Per-row actor IDs.
        'closed_by_user_id'       => 'integer',
        'reconciled_by_user_id'   => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::addGlobalScope(new BranchScope());
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class, 'cash_drawer_session_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_CLOSED, self::STATUS_RECONCILED], true);
    }
}
