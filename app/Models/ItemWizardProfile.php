<?php

namespace App\Models;

use App\Models\Scopes\WizardProfileBranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemWizardProfile extends Model
{
    use HasFactory;

    /**
     * [2026-05-18 PR-D blocker heal] Apply the nullable-column variant of
     * BranchScope. Wizard profiles can be globally published (branch_id_scope
     * NULL → visible to every branch) or branch-scoped (specific INT → only
     * that branch sees it). Default `BranchScope` would 500 on `unknown
     * column 'branch_id'` AND would hide every global profile from staff
     * queries. Admin (branch_id=0) bypasses the filter.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new WizardProfileBranchScope());
    }

    protected $fillable = [
        'item_id',
        'item_category_id',
        'template',
        'version',
        'is_published',
        'published_at',
        'branch_id_scope',
    ];

    protected $casts = [
        'id' => 'integer',
        'item_id' => 'integer',
        'item_category_id' => 'integer',
        'version' => 'integer',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'branch_id_scope' => 'integer',
    ];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    public function scopeForCategory($query, int $categoryId)
    {
        return $query->where('item_category_id', $categoryId);
    }

    public function ownerType(): string
    {
        if ($this->item_id) {
            return 'item';
        }

        if ($this->item_category_id) {
            return 'category';
        }

        return 'orphan';
    }

    public function branchScope()
    {
        return $this->belongsTo(Branch::class, 'branch_id_scope');
    }

    public function steps()
    {
        return $this->hasMany(ItemWizardStep::class, 'profile_id')->orderBy('position');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ItemWizardStepVersion::class, 'profile_id');
    }

    public function latestVersion(): ?ItemWizardStepVersion
    {
        return $this->versions()->orderByDesc('version')->first();
    }

    public function publish(): void
    {
        $this->forceFill([
            'is_published' => true,
            'published_at' => now(),
            'version' => ((int) $this->version) + 1,
        ])->save();
    }

    public function unpublish(): void
    {
        $this->forceFill([
            'is_published' => false,
            'version' => ((int) $this->version) + 1,
        ])->save();
    }
}
