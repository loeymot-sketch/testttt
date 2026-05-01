<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ItemAddon extends Model
{
    use HasFactory, SoftDeletes;

    public const ROLES = ['drink', 'side', 'dessert', 'menu_component', 'upsell'];

    protected $table = "item_addons";
    protected $fillable = ['item_id', 'addon_item_id', 'addon_item_variation', 'role'];
    protected $casts = [
        'id'                   => 'integer',
        'item_id'              => 'integer',
        'addon_item_id'        => 'integer',
        'addon_item_variation' => 'array',
        'role'                 => 'string',
    ];

    public function setRoleAttribute($value): void
    {
        if ($value !== null && $value !== '' && ! in_array($value, self::ROLES, true)) {
            throw new \InvalidArgumentException('Invalid addon role.');
        }

        $this->attributes['role'] = $value === '' ? null : $value;
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id', 'id');
    }
    public function addonItem()
    {
        return $this->belongsTo(Item::class, 'addon_item_id', 'id');
    }
}
