<?php

namespace App\Models;

use App\Enums\Status;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Config;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ItemCategory extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;
    use SoftDeletes;

    protected $table = "item_categories";
    protected $fillable = [
        'name', 'slug', 'description', 'status', 'sort',
        // [PLAN_11 ARCH-01] Config wizard
        'wizard_template', 'has_menu', 'default_menu_kiosk', 'sauce_included_menu',
        'kiosk_upsell_include', 'kiosk_upsell_skip_after_cart',
        // [V1 SECTION 5] Dual-channel projections
        'channels', 'kiosk_sort', 'pos_sort', 'kiosk_label',
    ];
    protected $casts = [
        'id'                  => 'integer',
        'name'                => 'string',
        'slug'                => 'string',
        'description'         => 'string',
        'status'              => 'integer',
        // [PLAN_11 ARCH-01] Config wizard
        'has_menu'            => 'boolean',
        'default_menu_kiosk'  => 'boolean',
        'sauce_included_menu' => 'boolean',
        'kiosk_upsell_include'         => 'boolean',
        'kiosk_upsell_skip_after_cart' => 'boolean',
        // [V1 SECTION 5] Dual-channel projections
        'channels'            => 'array',
        'kiosk_sort'          => 'integer',
        'pos_sort'            => 'integer',
        'kiosk_label'         => 'string',
    ];

    /**
     * Dual-channel projection helpers — section 5 MENU SSOT.
     * NULL `channels` = visible on every surface (legacy default).
     */
    public function isVisibleOn(string $channel): bool
    {
        return $this->channels === null || in_array($channel, (array) $this->channels, true);
    }

    /**
     * Channel-aware display name. Falls back to `name` when no override exists.
     */
    public function displayNameFor(string $channel): string
    {
        if ($channel === 'kiosk' && !empty($this->kiosk_label)) {
            return (string) $this->kiosk_label;
        }

        return (string) $this->name;
    }

    /**
     * Channel-aware sort key. Falls back to `sort` when no override exists.
     */
    public function sortFor(string $channel): int
    {
        if ($channel === 'kiosk' && $this->kiosk_sort !== null) {
            return (int) $this->kiosk_sort;
        }
        if ($channel === 'pos' && $this->pos_sort !== null) {
            return (int) $this->pos_sort;
        }

        return (int) ($this->sort ?? 0);
    }

    public function getThumbAttribute(): string
    {
        if (!empty($this->getFirstMediaUrl('item-category'))) {
            $category = $this->getMedia('item-category')->last();
            return $category->getUrl('thumb');
        }
        // Fallback: images depuis config/menu_images.php (améliore visuel POS)
        $images = Config::get('menu_images.categories', []);
        $basePath = Config::get('menu_images.base_path', 'images/menu');
        $defaultFile = Config::get('menu_images.default', 'item-default.svg');
        $filename = $images[$this->slug] ?? $defaultFile;
        $fullPath = public_path("{$basePath}/{$filename}");
        if (file_exists($fullPath)) {
            return asset("{$basePath}/{$filename}");
        }
        return asset('images/category/thumb.png');
    }

    public function getCoverAttribute(): string
    {
        if (!empty($this->getFirstMediaUrl('item-category'))) {
            $category = $this->getMedia('item-category')->last();
            return $category->getUrl('cover');
        }
        return asset('images/category/cover.png');
    }

    public function registerMediaConversions(Media $media = null): void
    {
        $this->addMediaConversion('thumb')->crop('crop-center', 112, 72)->keepOriginalImageFormat()->sharpen(10);
        $this->addMediaConversion('cover')->width(400)->keepOriginalImageFormat()->sharpen(10);
    }

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Item::class)->where(['status' => Status::ACTIVE]);
    }
}