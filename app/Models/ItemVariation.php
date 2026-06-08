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
        'description',
        'image_path',
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
        'description'       => 'string', // [W1] per-option description (catalog metadata, non-fiscal)
        'image_path'        => 'string', // [W1] per-option stored image (public-relative path or URL)
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
        $basePath = Config::get('menu_images.base_path', 'images/menu');
        $defaultFile = Config::get('menu_images.default', 'item-default.svg');

        // [W1 builder] A stored per-option image (set via the wizard builder) WINS
        // over the legacy name->config map. config/menu_images.php stays the
        // fallback for the 45 legacy items that carry no stored image.
        if (! empty($this->image_path)) {
            $stored = $this->resolveStoredImage((string) $this->image_path);
            if ($stored !== null) {
                return $stored;
            }
        }

        $attrName = optional($this->itemAttribute)->name ?? '';

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

    /**
     * [W1] Resolve a stored per-option image path to a cache-busted URL.
     * Delegates to the shared XSS-hardened guard ([W7] CatalogImagePath) — the
     * resolved value flows unescaped into the frozen pos-wizard.js img sink, so
     * hostile/malformed paths must collapse to null (fall back to config image).
     */
    private function resolveStoredImage(string $path): ?string
    {
        return \App\Support\CatalogImagePath::safeResolve($path);
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
