<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemWizardProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_id',
        'template',
        'version',
        'is_published',
        'published_at',
        'branch_id_scope',
    ];

    protected $casts = [
        'id' => 'integer',
        'item_id' => 'integer',
        'version' => 'integer',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'branch_id_scope' => 'integer',
    ];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function branchScope()
    {
        return $this->belongsTo(Branch::class, 'branch_id_scope');
    }

    public function steps()
    {
        return $this->hasMany(ItemWizardStep::class, 'profile_id')->orderBy('position');
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
