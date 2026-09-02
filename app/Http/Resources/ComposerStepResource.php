<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ComposerStepResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'profile_id' => $this->profile_id,
            'wizard_page_id' => $this->wizard_page_id,
            'page' => $this->whenLoaded('page', fn () => $this->page ? [
                'id' => (int) $this->page->id,
                'key' => (string) $this->page->key,
                'label' => (string) $this->page->label,
                'kind' => (string) $this->page->kind,
                'source_type' => (string) $this->page->source_type,
                'is_library' => $this->page->owner_category_id === null,
                'is_active' => (bool) $this->page->is_active,
                'choices' => $this->page->relationLoaded('choices')
                    ? $this->page->choices->map(fn ($choice): array => [
                        'id' => (int) $choice->id,
                        'name' => (string) $choice->name,
                        'price' => (float) $choice->price,
                        'status' => (int) $choice->status,
                        'addon_item_id' => $choice->addon_item_id !== null ? (int) $choice->addon_item_id : null,
                    ])->values()->all()
                    : [],
            ] : null),
            'step_key' => $this->step_key,
            'label' => $this->label,
            'source_type' => $this->source_type,
            'source_ref' => $this->source_ref,
            'source_item_attribute_id' => $this->source_item_attribute_id,
            'min_select' => $this->min_select,
            'max_select' => $this->max_select,
            'allow_repeat' => $this->allow_repeat,
            'visible_on' => $this->visible_on,
            'stockable_choices' => $this->stockable_choices,
            'position' => $this->position,
            'is_active' => $this->is_active,
            'addon_role' => $this->addon_role,
        ];
    }
}
