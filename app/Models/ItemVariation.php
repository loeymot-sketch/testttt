<?php

namespace App\Models;

use App\Libraries\AppLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ItemVariation extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = "item_variations";
    protected $appends = ['convert_price', 'currency_price', 'flat_price', 'thumb'];

    protected $fillable = [
        'item_id',
        'item_attribute_id',
        'name',
        'price',
        'caution',
        'status',
        'visible_on',
    ];
    protected $casts = [
        'id'                => 'integer',
        'item_id'           => 'integer',
        'item_attribute_id' => 'integer',
        'name'              => 'string',
        'price'             => 'decimal:6',
        'caution'           => 'string',
        'status'            => 'integer',
        'visible_on'        => 'array',  // null = all surfaces; ["kiosk","pos","web"] = restricted
    ];

    /**
     * Returns true if this variation is visible on the given surface.
     * null visible_on means visible everywhere (backward-compatible default).
     */
    public function isVisibleOn(string $surface): bool
    {
        return $this->visible_on === null || in_array($surface, $this->visible_on, true);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function itemAttribute()
    {
        return $this->belongsTo(ItemAttribute::class);
    }

    /**
     * Image URL for sauces, crudités, viandes (from config/menu_images.php)
     */
    public function getThumbAttribute(): ?string
    {
        $attrName = optional($this->itemAttribute)->name ?? '';
        $basePath = Config::get('menu_images.base_path', 'images/menu');
        $defaultFile = Config::get('menu_images.default', 'item-default.png');

        if (str_contains($attrName, 'Sauce') || str_contains($attrName, 'sauce')) {
            $sauces = Config::get('menu_images.sauces', []);
            $filename = $sauces[$this->name] ?? null;
        } elseif (str_contains($attrName, 'Crudité') || str_contains($attrName, 'Garniture')) {
            $crudites = Config::get('menu_images.crudites', []);
            $filename = $crudites[$this->name] ?? null;
        } elseif (str_contains($attrName, 'Viande')) {
            $viandes = Config::get('menu_images.viandes', []);
            $filename = $viandes[$this->name] ?? null;
        } else {
            $filename = null;
        }

        if ($filename && file_exists(public_path("{$basePath}/{$filename}"))) {
            return asset("{$basePath}/{$filename}");
        }
        if (file_exists(public_path("{$basePath}/{$defaultFile}"))) {
            return asset("{$basePath}/{$defaultFile}");
        }
        return null;
    }

    public function getCurrencyPriceAttribute(): string
    {
        return AppLibrary::currencyAmountFormat($this->price);
    }

    public function getFlatPriceAttribute(): string
    {
        return AppLibrary::currencyAmountFormat($this->price);
    }

    public function getConvertPriceAttribute(): float
    {
        return AppLibrary::convertAmountFormat($this->price);
    }
}
