<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un choix d'une page de wizard : nom + prix (+ article lié pour les pages « addon »).
 */
class WizardPageChoice extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'wizard_page_id',
        'name',
        'price',
        'addon_item_id',
        'sort',
        'status',
        'visible_on',
    ];

    protected $casts = [
        'id' => 'integer',
        'wizard_page_id' => 'integer',
        'price' => 'decimal:6',
        'addon_item_id' => 'integer',
        'sort' => 'integer',
        'status' => 'integer',
        'visible_on' => 'array',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(WizardPage::class, 'wizard_page_id');
    }

    public function addonItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'addon_item_id');
    }

    public static function normalizeName(?string $name): string
    {
        return mb_strtolower(trim((string) $name));
    }
}
