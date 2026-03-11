<?php

namespace App\Http\Resources;


use App\Libraries\AppLibrary;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemVariationResource extends JsonResource
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
            'id' => $this->id,
            'item_id' => $this->item_id,
            'item_attribute_id' => $this->item_attribute_id,
            'name' => $this->name,
            'price' => $this->price,
            "flat_price" => AppLibrary::flatAmountFormat($this->price),
            "convert_price" => AppLibrary::convertAmountFormat($this->price),
            "currency_price" => AppLibrary::currencyAmountFormat($this->price),
            'caution' => $this->caution,
            'status' => $this->status,
            // [SPRINT-8] Images et badge NOUVEAU pour les cartes wizard kiosk
            'thumb' => $this->thumb ?? null,
            'is_new' => (bool) ($this->is_new ?? false),
            'item' => optional($this->item)->name,
            'item_attribute' => optional($this->itemAttribute)->name,
        ];
    }
}