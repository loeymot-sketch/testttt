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
            'sort'            => (int) ($this->sort ?? 0),
            'thumb'           => $this->thumb,
            'cover'           => $this->cover,
            // Kiosk catalogue legacy fields expected by older Vue components.
            'image'           => $this->thumb ?: $this->cover,
            'image_full_path' => $this->cover ?: $this->thumb,
            'wizard_template' => $this->wizard_template ?? 'simple',
            'has_menu'        => (bool)($this->has_menu ?? false),
            // Sans ces deux champs, Modifier → Enregistrer (juste le nom)
            // renvoyait 0 et décochait « menu précoché » / « sauce incluse » sur la borne.
            'default_menu_kiosk'           => (bool)($this->default_menu_kiosk ?? false),
            'sauce_included_menu'          => (bool)($this->sauce_included_menu ?? false),
            'kiosk_upsell_include'         => (bool)($this->kiosk_upsell_include ?? true),
            'kiosk_upsell_skip_after_cart' => (bool)($this->kiosk_upsell_skip_after_cart ?? false),
        ];
    }
}
