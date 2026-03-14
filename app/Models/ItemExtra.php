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
    protected $fillable = ['item_id', 'name', 'status', 'price'];
    protected $casts = [
        'id'      => 'integer',
        'item_id' => 'integer',
        'name'    => 'string',
        'status'  => 'integer',
        'price'   => 'decimal:6',
    ];

    /**
     * Image URL for supplements (from config/menu_images.php)
     * Handles: Supplément X, Sauce supplémentaire: X
     */
    public function getThumbAttribute(): ?string
    {
        $basePath = Config::get('menu_images.base_path', 'images/menu');
        $defaultFile = Config::get('menu_images.default', 'item-default.png');

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
            $supplements = Config::get('menu_images.supplements', []);
            $filename = $supplements[$this->name] ?? null;
        }

        if ($filename && file_exists(public_path("{$basePath}/{$filename}"))) {
            return asset("{$basePath}/{$filename}");
        }
        if (file_exists(public_path("{$basePath}/{$defaultFile}"))) {
            return asset("{$basePath}/{$defaultFile}");
        }
        return null;
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id', 'id');
    }
}
