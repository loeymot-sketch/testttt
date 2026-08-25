<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

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
     * LE DRAPEAU DE LA LANGUE, GARANTI AFFICHABLE.
     *
     * [2026-08-25, mesuré] Le garde d'origine ne couvrait qu'un seul cas : AUCUN média
     * déclaré. Il ne couvrait pas celui qu'on a réellement rencontré — un média DÉCLARÉ en
     * base dont le FICHIER a disparu du disque. `getFirstMediaUrl()` rend alors une adresse
     * parfaitement formée vers un fichier absent : le garde `!empty()` la laisse passer, le
     * navigateur la demande, et reçoit un 404. Constaté 52 fois sur une seule campagne de
     * captures, sur toutes les pages qui portent le sélecteur de langue.
     *
     * Cause première du fichier disparu : des tests e2e écrivent dans `storage/app/public/1/`
     * (on y trouve `roue-photoYeWfku-*`, `admin-photo-*`) et ont emporté `english.png`. Le
     * ménage des tests est un sujet à part ; ce garde-ci fait que le produit ne casse pas
     * quand un média manque, quelle qu'en soit la raison — suppression dans l'admin, disque
     * désynchronisé, restauration partielle.
     *
     * On vérifie donc la PRÉSENCE RÉELLE du fichier, pas seulement celle de la ligne en base.
     * Deux langues en V1 : le coût est de deux `stat`, payés une fois par rendu.
     */
    public function getImageAttribute(): string
    {
        $media = $this->getFirstMedia('language');

        if ($media !== null) {
            try {
                if (is_file($media->getPath())) {
                    return asset($media->getUrl());
                }
            } catch (\Throwable $e) {
                // Disque non monté ou non local : on ne fait pas tomber une page d'admin
                // pour un drapeau. On retombe sur le repli ci-dessous.
                report($e);
            }
        }

        return asset('images/item/thumb.png');
    }
}