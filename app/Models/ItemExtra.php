<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ItemExtra extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = "item_extras";
    protected $fillable = ['item_id', 'name', 'status', 'price', 'visible_on', 'group_label', 'is_available', 'unavailable_reason', 'description', 'image_path'];
    protected $casts = [
        'id'                 => 'integer',
        'item_id'            => 'integer',
        'name'               => 'string',
        'status'             => 'integer',
        'price'              => 'decimal:6',
        'visible_on'         => 'array',   // null = all surfaces; ["kiosk","pos","web"] = restricted
        'group_label'        => 'string',
        'is_available'       => 'boolean',
        'unavailable_reason' => 'string',
        'description'        => 'string',  // [W1] per-option description (catalog metadata, non-fiscal)
        'image_path'         => 'string',  // [W1] per-option stored image (public-relative path or URL)
    ];

    /**
     * Returns true if this extra is visible on the given surface.
     * null visible_on means visible everywhere (backward-compatible default).
     */
    public function isVisibleOn(string $surface): bool
    {
        return $this->visible_on === null || in_array($surface, $this->visible_on, true);
    }

    /**
     * Image URL for supplements (from config/menu_images.php)
     * Handles: Supplément X, Sauce supplémentaire: X
     */
    public function getThumbAttribute(): ?string
    {
        $basePath = Config::get('menu_images.base_path', 'images/menu');
        $defaultFile = Config::get('menu_images.default', 'item-default.svg');

        // [W1 builder] A stored per-option image (set via the wizard builder) WINS
        // over the legacy name->config map; config stays the fallback for legacy.
        if (! empty($this->image_path)) {
            $stored = $this->resolveStoredImage((string) $this->image_path);
            if ($stored !== null) {
                return $stored;
            }
        }

        $filename = null;
        if (str_starts_with($this->name, 'Sauce supplémentaire:')) {
            $sauceName = trim(str_replace('Sauce supplémentaire:', '', $this->name));
            $sauces = Config::get('menu_images.sauces', []);
            $filename = $sauces[$sauceName] ?? null;
        } elseif (str_starts_with($this->name, 'Sauce ')) {
            $sauceName = trim(str_replace('Sauce ', '', $this->name));
            $sauces = Config::get('menu_images.sauces', []);
            $filename = $sauces[$sauceName] ?? null;
        } else {
            // Crudités atomiques (MenuSeeder : extras « Salade », « Tomate », « Oignon » — pas le préfixe « Sauce »).
            $cruditeExtras = Config::get('menu_images.crudite_extras', []);
            $supplements = Config::get('menu_images.supplements', []);
            $filename = $cruditeExtras[$this->name] ?? $supplements[$this->name] ?? null;
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
     * [W1] Resolve a stored per-option image path to a cache-busted URL.
     * Delegates to the shared XSS-hardened guard ([W7] CatalogImagePath) — the
     * resolved value flows unescaped into the frozen pos-wizard.js img sink, so
     * hostile/malformed paths must collapse to null (fall back to config image).
     */
    private function resolveStoredImage(string $path): ?string
    {
        return \App\Support\CatalogImagePath::safeResolve($path);
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id', 'id');
    }
}
