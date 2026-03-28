<?php

namespace App\Http\Resources;


use Illuminate\Http\Resources\Json\JsonResource;

class ItemCategoryResource extends JsonResource
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
            'id'              => $this->id,
            'name'            => $this->name,
            'slug'            => $this->slug,
            'description'     => $this->description === null ? '' : $this->description,
            'status'          => $this->status,
            'thumb'           => $this->thumb,
            'cover'           => $this->cover,
            'wizard_template' => $this->wizard_template ?? 'simple',
            'has_menu'        => (bool)($this->has_menu ?? false),
            'kiosk_upsell_include'         => (bool)($this->kiosk_upsell_include ?? true),
            'kiosk_upsell_skip_after_cart' => (bool)($this->kiosk_upsell_skip_after_cart ?? false),
        ];
    }
}
