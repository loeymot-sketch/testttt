<?php

namespace App\Models;

use Carbon\Carbon;
use App\Enums\Status;
use Illuminate\Support\Facades\Config;
use Spatie\MediaLibrary\HasMedia;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\InteractsWithMedia;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Item extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes;

    protected $table = "items";
    protected $fillable = [
        'name',
        'item_category_id',
        'slug',
        'barcode',
        'tax_id',
        'item_type',
        'price',
        'is_featured',
        'is_upsell',
        'is_chef_pick',
        'is_new',
        'is_available',
        'is_spicy',
        'is_vegetarian',
        'is_pork_free',
        'is_halal',
        'is_gluten_free',
        'chef_pick_order',
        'description',
        'caution',
        'status',
        'order',
        'channels',
        'allergen_flags',
        'kiosk_emoji',
        'kds_station',
    ];
    protected $dates = ['deleted_at'];
    protected $casts = [
        'id'               => 'integer',
        'name'             => 'string',
        'item_category_id' => 'integer',
        'slug'             => 'string',
        'barcode'          => 'string',
        'tax_id'           => 'integer',
        'item_type'        => 'integer',
        'price'            => 'decimal:6',
        'is_featured'      => 'integer',
        'is_upsell'        => 'integer',
        'is_chef_pick'     => 'boolean',
        'is_new'           => 'boolean',
        'is_available'     => 'boolean',
        'is_spicy'         => 'boolean',
        'is_vegetarian'    => 'boolean',
        'is_pork_free'     => 'boolean',
        'is_halal'         => 'boolean',
        'is_gluten_free'   => 'boolean',
        'chef_pick_order'  => 'integer',
        'description'      => 'string',
        'caution'          => 'string',
        'status'           => 'integer',
        'order'            => 'integer',
        'channels'         => 'array', // null = all surfaces (back-compat V1)
        'allergen_flags'   => 'array',
        'kiosk_emoji'      => 'string',
        'kds_station'      => 'string',
    ];

    /**
     * Dual-channel projection helper — section 5 MENU SSOT.
     * NULL `channels` = visible on every surface (legacy default).
     */
    public function isVisibleOn(string $channel): bool
    {
        return $this->channels === null || in_array($channel, (array) $this->channels, true);
    }

    public function getThumbAttribute(): string
    {
        if (!empty($this->getFirstMediaUrl('item'))) {
            $item = $this->getMedia('item')->last();
            return file_exists($item->getPath('thumb')) ? $item->getUrl('thumb') : $item->getUrl();
        }
        // Fallback: images depuis config/menu_images.php (améliore visuel POS)
        $images = Config::get('menu_images.items', []) + Config::get('menu_images.addons', []);
        $basePath = Config::get('menu_images.base_path', 'images/menu');
        $defaultFile = Config::get('menu_images.default', 'item-default.svg');
        $filename = $images[$this->slug] ?? $defaultFile;
        $fullPath = public_path("{$basePath}/{$filename}");
        if (file_exists($fullPath)) {
            return asset("{$basePath}/{$filename}");
        }
        return asset('images/item/thumb.png');
    }

    public function getCoverAttribute(): string
    {
        if (!empty($this->getFirstMediaUrl('item'))) {
            $item = $this->getMedia('item')->last();
            return file_exists($item->getPath('cover')) ? $item->getUrl('cover') : $item->getUrl();
        }
        return asset('images/item/cover.png');
    }

    public function getPreviewAttribute(): string
    {
        if (!empty($this->getFirstMediaUrl('item'))) {
            $item = $this->getMedia('item')->last();
            return file_exists($item->getPath('preview')) ? $item->getUrl('preview') : $item->getUrl();
        }
        return asset('images/item/cover.png');
    }

    public function registerMediaConversions(Media $media = null): void
    {
        $this->addMediaConversion('thumb')->crop('crop-center', 168, 180)->keepOriginalImageFormat()->sharpen(10);
        $this->addMediaConversion('cover')->crop('crop-center', 390, 270)->keepOriginalImageFormat()->sharpen(10);
        $this->addMediaConversion('preview')->width(600)->keepOriginalImageFormat()->sharpen(10);
    }

    public function variations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ItemVariation::class)->with('itemAttribute')->where(['status' => Status::ACTIVE]);
    }

    public function extras(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ItemExtra::class)->where(['status' => Status::ACTIVE]);
    }

    public function addons(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ItemAddon::class);
    }

    public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id', 'id');
    }

    public function tax(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrderItem::class, 'item_id', 'id');
    }

    public function offer(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Offer::class, 'offer_items');
    }

    /**
     * Kiosk Design V1 — Phase 1.2.
     * Relation normalisée item ↔ allergen via pivot `item_allergen`.
     * Source de vérité pour l'affichage kiosk ; `allergen_flags` JSON reste
     * un cache projeté (synchronisation : `AllergenService::projectFlags`).
     */
    public function allergens(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Allergen::class, 'item_allergen')
            ->withPivot('is_trace')
            ->withTimestamps();
    }
}
