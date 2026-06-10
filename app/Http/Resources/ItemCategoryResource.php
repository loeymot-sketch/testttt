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
            // [GOAL CMS C1.4 2026-06-10] Sub-category support: without this
            // field no admin UI can build the category tree (schema has had
            // parent_id since migration 2026_04_18_120001).
            'parent_id'       => $this->parent_id !== null ? (int) $this->parent_id : null,
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
            'kiosk_upsell_include'         => (bool)($this->kiosk_upsell_include ?? true),
            'kiosk_upsell_skip_after_cart' => (bool)($this->kiosk_upsell_skip_after_cart ?? false),
        ];
    }
}
