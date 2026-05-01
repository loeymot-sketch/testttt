<?php

namespace App\Http\Resources;


use App\Libraries\AppLibrary;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemExtraResource extends JsonResource
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
            'id'            => $this->id,
            'item_id'       => $this->item_id,
            'name'          => $this->name,
            'price'         => $this->price,
            'currency_price'=> AppLibrary::currencyAmountFormat($this->price),
            'flat_price'    => AppLibrary::flatAmountFormat($this->price),
            'convert_price' => AppLibrary::convertAmountFormat($this->price),
            'status'        => $this->status,
            'visible_on'    => $this->visible_on,   // null = all surfaces
            'group_label'   => $this->group_label,  // e.g. "Sauce", "Supplément", "Garniture"
            'thumb'         => $this->thumb ?? null,
            'is_available'  => $this->is_available === null ? true : (bool) $this->is_available,
            'unavailable_reason' => $this->unavailable_reason,
            'is_new'        => (bool) ($this->is_new ?? false),
            'item'          => optional($this->item)->name,
        ];
    }
}
