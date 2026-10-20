<?php

namespace App\Http\Resources;


use Illuminate\Http\Resources\Json\JsonResource;

class AddressResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request) : array
    {
        return [
            "id"         => $this->id,
            "user_id"    => $this->user_id,
            "label"      => $this->label,
            "address"    => $this->address,
            "apartment"  => $this->apartment,
            "latitude"   => $this->latitude,
            "longitude"  => $this->longitude,
            // [LIVRAISON 2026-10-20] Commune et code postal posés par le géocodeur serveur (le tarif en dépend).
            "city"        => $this->city,
            "postal_code" => $this->postal_code,
            "labelAddress" => $this->label . " - ". $this->address,
        ];
    }
}
