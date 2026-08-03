<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Insert-only snapshot of an ItemWizardProfile published version.
 *
 * Architecture rationale: aligned with stock_movements (insert-only) and
 * order_items.composition_snapshot (immutable post-payment) patterns. NF525-friendly.
 *
 * Lifecycle:
 *  - INSERT only via ComposerProfileService::publish() (or related publish flows).
 *  - Never UPDATE: enforced via overridden update() throw.
 *  - Never direct DELETE: cascade only when parent ItemWizardProfile is deleted.
 *  - No cleanup policy in V1 (re-evaluate V2 if row count grows beyond 10k per profile).
 *
 * @property int $id
 * @property int $profile_id
 * @property int $version
 * @property array $snapshot
 * @property \Illuminate\Support\Carbon $published_at
 * @property int|null $published_by_id
 */
class ItemWizardStepVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id',
        'version',
        'snapshot',
        'published_at',
        'published_by_id',
    ];

    protected $casts = [
        'profile_id' => 'integer',
        'version' => 'integer',
        'snapshot' => 'array',
        'published_at' => 'datetime',
        'published_by_id' => 'integer',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ItemWizardProfile::class, 'profile_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_id');
    }

    /**
     * Insert-only enforcement: prevent UPDATE.
     * DELETE is allowed only via FK cascade from parent profile (SQL-level, bypasses Eloquent).
     * Direct Eloquent delete() remains technically possible — service layer must not call it.
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \RuntimeException(
            'ItemWizardStepVersion is insert-only. Use ItemWizardStepVersion::create() to add a new version row.'
        );
    }
}
