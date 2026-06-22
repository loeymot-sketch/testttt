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
        'siret', 'vat_intra', 'register_id', 'legal_footer',
        // [Sprint H3 DEL-5 2026-05-17] Branch-configurable delivery fee
        'delivery_fee_base', 'delivery_fee_per_km', 'delivery_fee_minimum',
        // [DEL-FEE whole-km 2026-06-01] free radius covered by base, +per_km/started-km beyond
        'delivery_fee_free_km',
        // [Sprint H3 DEL-8 2026-05-17] Branch-configurable delivery minimum order
        'delivery_minimum_order',
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
        'siret'              => 'string',
        'vat_intra'          => 'string',
        'register_id'        => 'string',
        'legal_footer'       => 'string',
        // [Sprint H3 DEL-5 2026-05-17] decimal:2 keeps Eloquent honest about precision
        'delivery_fee_base'    => 'decimal:2',
        'delivery_fee_per_km'  => 'decimal:2',
        'delivery_fee_minimum' => 'decimal:2',
        'delivery_fee_free_km' => 'decimal:2',
        // [Sprint H3 DEL-8 2026-05-17] decimal:2 for order-minimum threshold
        'delivery_minimum_order' => 'decimal:2',
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