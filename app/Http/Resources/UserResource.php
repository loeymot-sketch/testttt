<?php

namespace App\Http\Resources;


use App\Libraries\AppLibrary;
use App\Support\PhoneDisplay;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            "id"               => $this->id,
            "name"             => $this->name,
            "first_name"       => $this->FirstName,
            "last_name"        => $this->LastName,
            "phone"            => PhoneDisplay::safe($this->phone),
            "email"            => $this->email,
            'username'         => $this->username,
            "balance"          => AppLibrary::flatAmountFormat($this->balance),
            "currency_balance" => AppLibrary::currencyAmountFormat($this->balance),
            "image"            => $this->image,
            "role_id"          => $this->myRole,
            "country_code"     => $this->country_code,
            // [TERRAIN-HEAL 2026-07-16 · USERRES-ORDERCOUNT] idem : ne pas hydrater toute la relation
            // orders par user juste pour la compter (préférer withCount, sinon COUNT léger).
            "order"            => $this->orders_count
                ?? ($this->relationLoaded('orders') ? $this->orders->count() : $this->orders()->count()),
            "loyalty_code"     => $this->loyalty_code,
            "loyalty_points"   => (int) ($this->loyalty_points ?? 0),
            'create_date'      => AppLibrary::date($this->created_at),
            'update_date'      => AppLibrary::date($this->updated_at),

        ];
    }
}
