<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use HasFactory;
    use SoftDeletes;
    protected $table = "branches";
    protected $fillable = [
        'name', 'email', 'phone', 'latitude', 'longitude',
        'city', 'state', 'zip_code', 'address', 'zone', 'status',
        'available_locales',
    ];
    protected $casts = [
        'id'                 => 'integer',
        'name'               => 'string',
        'email'              => 'string',
        'phone'              => 'string',
        'latitude'           => 'string',
        'longitude'          => 'string',
        'city'               => 'string',
        'state'              => 'string',
        'zip_code'           => 'string',
        'address'            => 'string',
        'zone'               => 'string',
        'status'             => 'integer',
        // Kiosk Design V1 — Phase 1.2
        'available_locales'  => 'array',
    ];

    /**
     * Locales disponibles avec fallback sur la config app si le champ est
     * null/vide. Garantit que l'UI kiosk a toujours au moins 1 locale.
     */
    public function activeLocales(): array
    {
        $locales = $this->available_locales;
        if (is_array($locales) && count($locales) > 0) {
            return array_values(array_filter(array_map('strval', $locales)));
        }
        $default = config('kiosk.default_locale', 'fr');
        return [$default];
    }
}