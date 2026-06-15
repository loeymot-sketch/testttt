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
    protected $fillable = ['item_id', 'name', 'status', 'price', 'visible_on', 'group_label', 'is_available', 'unavailable_reason', 'image_path', 'description']; // [WIZARD-STUDIO W4] additive per-option image+description
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

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id', 'id');
    }
}
