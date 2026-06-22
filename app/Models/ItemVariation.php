<?php

namespace App\Models;

use App\Libraries\AppLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
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
        // [WIZARD-STUDIO W4 / DATA-01] image_path + description columns exist (nullable) and the
        // projection READS them (prefers image_path over thumb), but they are intentionally NOT
        // $fillable: no validated writer exists yet, and the item create/update `variations` JSON
        // blob is not key-whitelisted — leaving them fillable was an uncontrolled write surface.
        // A future Studio option-edit endpoint must set them EXPLICITLY (forceFill / validated),
        // not via mass-assignment. Reads are unaffected by $fillable.
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
        $defaultFile = Config::get('menu_images.default', 'item-default.svg');

        if (str_contains($attrName, 'Sauce') || str_contains($attrName, 'sauce')) {
            $filename = $this->resolveMenuImageFilename(
                Config::get('menu_images.sauces', []),
                (string) $this->name
            );
        } elseif (str_contains($attrName, 'Crudité') || str_contains($attrName, 'Garniture')) {
            $filename = $this->resolveMenuImageFilename(
                Config::get('menu_images.crudites', []),
                (string) $this->name
            );
        } elseif (str_contains($attrName, 'Viande')) {
            $filename = $this->resolveMenuImageFilename(
                Config::get('menu_images.viandes', []),
                (string) $this->name
            );
        } else {
            $filename = null;
        }

        if ($filename && file_exists(public_path("{$basePath}/{$filename}"))) {
            $hash = @filemtime(public_path("{$basePath}/{$filename}")) ?: 0;
            return asset("{$basePath}/{$filename}") . "?v={$hash}";
        }
        if (file_exists(public_path("{$basePath}/{$defaultFile}"))) {
            $hash = @filemtime(public_path("{$basePath}/{$defaultFile}")) ?: 0;
            return asset("{$basePath}/{$defaultFile}") . "?v={$hash}";
        }
        return null;
    }

    /**
     * Résout le fichier image pour un libellé catalogue (casse, espaces, accents).
     *
     * @param  array<string, string>  $map
     */
    private function resolveMenuImageFilename(array $map, string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        if (isset($map[$name])) {
            return $map[$name];
        }
        $lower = mb_strtolower($name);
        foreach ($map as $key => $file) {
            if (mb_strtolower(trim((string) $key)) === $lower) {
                return $file;
            }
        }
        $asciiName = Str::lower(Str::ascii($name));
        foreach ($map as $key => $file) {
            if (Str::lower(Str::ascii((string) $key)) === $asciiName) {
                return $file;
            }
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
