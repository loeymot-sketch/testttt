<?php

namespace App\Models;

use App\Libraries\AppLibrary;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ItemVariation extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes;

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
        // La photo posée depuis l'admin prime sur la correspondance par nom.
        if ($url = $this->photoTeleverseeUrl()) {
            return $url;
        }

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
        } elseif (str_contains($attrName, 'Pain') || str_contains($attrName, 'Base')) {
            // [FIX 2026-08-09] Sans cette branche, « Type de Pain » et « Base bol »
            // tombaient dans le `else` et affichaient l'image par défaut : les deux
            // PREMIÈRES étapes du wizard, donc la première chose que voit le
            // client, étaient des cases grises alors que les photos existaient.
            $filename = $this->resolveMenuImageFilename(
                Config::get('menu_images.bases', []),
                (string) $this->name
            );
        } else {
            $filename = null;
        }

        if ($filename && file_exists(public_path("{$basePath}/{$filename}"))) {
            // [W5-PERF #1 2026-07-06] Vignette WebP ≤320px pré-générée servie en
            // priorité (viandes/sauces plein format dans le wizard caisse+borne).
            // Fallback plein format inchangé si la vignette manque.
            if ($thumbUrl = \App\Support\MenuImageThumb::url($basePath, $filename)) {
                return $thumbUrl;
            }
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

    /**
     * [PILOTAGE 2026-08-09] Une option pouvait être CRÉÉE depuis l'admin, mais
     * pas ILLUSTRÉE : sa photo était déduite de son NOM via config/menu_images.php,
     * un fichier PHP. Ajouter une image demandait donc un développeur et un accès
     * au serveur — et 131 choix sur 1002 s'affichaient en case grise, dont les deux
     * premières étapes du wizard borne.
     *
     * Même mécanique que Item : la photo téléversée GAGNE, la table de
     * correspondance par nom reste le repli. Rien de ce qui marchait ne change.
     */
    public function registerMediaConversions(Media $media = null): void
    {
        $this->addMediaConversion('thumb')->crop('crop-center', 168, 180)->keepOriginalImageFormat()->sharpen(10);
        $this->addMediaConversion('cover')->crop('crop-center', 390, 270)->keepOriginalImageFormat()->sharpen(10);
    }

    /** URL de la photo téléversée, ou null si l'option n'en a pas. */
    protected function photoTeleverseeUrl(): ?string
    {
        if (empty($this->getFirstMediaUrl('option'))) {
            return null;
        }
        $m = $this->getMedia('option')->last();

        return file_exists($m->getPath('thumb')) ? $m->getUrl('thumb') : $m->getUrl();
    }
}
