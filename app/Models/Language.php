<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Illuminate\Support\Facades\Storage;

class Language extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = "languages";
    protected $fillable = ['name', 'code', 'status', 'display_mode'];
    protected $casts = [
        'id'            => 'integer',
        'name'          => 'string',
        'code'          => 'string',
        'display_mode'  => 'integer',
        'status'        => 'integer',
    ];

    /**
     * [2026-09-02] Avant : la seule présence d'une LIGNE media suffisait à renvoyer son URL, même
     * quand le fichier n'était plus sur le disque — la fiche produit servait un 404
     * (`/storage/1/english.png`) et affichait un drapeau cassé. On vérifie maintenant le fichier,
     * pas seulement l'enregistrement ; sinon on retombe sur la vignette par défaut.
     */
    public function getImageAttribute(): string
    {
        $media = $this->getFirstMedia('language');

        if ($media !== null) {
            try {
                if (Storage::disk($media->disk)->exists($media->getPathRelativeToRoot())) {
                    return asset($this->getFirstMediaUrl('language'));
                }
            } catch (\Throwable) {
                // Disque injoignable : on ne casse pas l'écran pour une vignette.
            }
        }

        return asset('images/item/thumb.png');
    }
}