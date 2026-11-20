<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    use HasFactory;

    protected $table = "addresses";
    // [Sprint 2B / DEL-1] geocode_status added — populated by AddressService::store
    // from the autocomplete/geocoding callback response. Read by
    // DeliveryQuoteService::quoteForAddress to gate DELIVERY quotes.
    // [PROFIL APP 2026-11-12] `is_default` : l'adresse proposée en premier au paiement en livraison (une seule par client).
    protected $fillable    = ['label', 'address', 'user_id', 'apartment', 'latitude', 'longitude', 'geocode_status', 'city', 'postal_code', 'is_default'];
    protected $casts = [
        'id'             => 'integer',
        'label'          => 'string',
        'address'        => 'string',
        'user_id'        => 'integer',
        'apartment'      => 'string',
        'latitude'       => 'string',
        'longitude'      => 'string',
        'geocode_status' => 'string',
        'city'           => 'string',
        'postal_code'    => 'string',
        'is_default'     => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
