<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * [V1.0.2 Sub-6.3 — 2026-05-18] Delivery boy cash drawer session.
 *
 * Path B (Planner H plan §0 / Decision Coin #1) : mirrors `CashDrawerSession`
 * verbatim — separate table to avoid polluting the POS NF525 path.
 *
 * Statuts (string) :
 *   - open       : livreur on-shift, doorstep collects in progress
 *   - closed     : closing_amount declared (physical cash counted)
 *   - reconciled : variance figée + audit chain sealed
 *
 * Branch isolation via BranchScope. Toute requête admin cross-branch doit
 * passer par `withoutGlobalScope(BranchScope::class)` explicite + commentaire.
 *
 * NF525 invariants :
 *   I1. open() refuse si une session OPEN existe déjà pour (branch_id,
 *       delivery_boy_id) — UNIQUE partial index `uk_branch_livreur_open`.
 *   I2. close() refuse si la session est déjà reconciled.
 *   I3. reconcile() calcule expected = opening + Σ(movements signed)
 *       et variance = closing - expected.
 *   I4. DELETE forbidden (DB trigger NF525 — see migration 120300).
 */
class DeliveryBoyCashSession extends Model
{
    use HasFactory;

    public const STATUS_OPEN       = 'open';
    public const STATUS_CLOSED     = 'closed';
    public const STATUS_RECONCILED = 'reconciled';

    protected $table = 'delivery_boy_cash_sessions';

    protected $fillable = [
        'branch_id',
        'delivery_boy_id',
        'opened_by_user_id',
        'closed_by_user_id',
        'reconciled_by_user_id',
        'opened_at',
        'closed_at',
        'opening_amount',
        'closing_amount',
        'expected_closing_amount',
        'variance',
        'variance_reason',
        'status',
        'notes',
    ];

    protected $casts = [
        'id'                      => 'integer',
        'branch_id'               => 'integer',
        'delivery_boy_id'         => 'integer',
        'opened_by_user_id'       => 'integer',
        'closed_by_user_id'       => 'integer',
        'reconciled_by_user_id'   => 'integer',
        'opened_at'               => 'datetime',
        'closed_at'               => 'datetime',
        'opening_amount'          => 'decimal:2',
        'closing_amount'          => 'decimal:2',
        'expected_closing_amount' => 'decimal:2',
        'variance'                => 'decimal:2',
        'status'                  => 'string',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::addGlobalScope(new BranchScope());
    }

    public function movements(): HasMany
    {
        return $this->hasMany(DeliveryBoyCashMovement::class, 'delivery_boy_cash_session_id');
    }

    public function deliveryBoy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivery_boy_id');
    }

    /**
     * [W-REM T-R2.3 Q-1 2026-06-12] Additive relation so the admin UI can
     * render the branch NAME instead of the raw branch_id (finding D-B3-01).
     * Branch is BranchScope-exempt (self-reference) — no scoping recursion.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by_user_id');
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
