<?php

namespace App\Http\Resources;

use App\Models\WizardPageChoice;
use Illuminate\Http\Resources\Json\JsonResource;

class WizardPageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'key' => (string) $this->key,
            'label' => (string) $this->label,
            'kind' => (string) $this->kind,
            'source_type' => (string) $this->source_type,
            'item_attribute_id' => $this->item_attribute_id !== null ? (int) $this->item_attribute_id : null,
            'item_attribute_name' => $this->whenLoaded('itemAttribute', fn () => $this->itemAttribute?->name),
            'extra_group_label' => $this->extra_group_label,
            'addon_role' => $this->addon_role,
            'min_select' => (int) $this->min_select,
            'max_select' => (int) $this->max_select,
            'allow_repeat' => (bool) $this->allow_repeat,
            'visible_on' => $this->visible_on,
            'stockable_choices' => (bool) $this->stockable_choices,
            'is_active' => (bool) $this->is_active,
            'is_library' => $this->owner_category_id === null,
            'owner_category_id' => $this->owner_category_id !== null ? (int) $this->owner_category_id : null,
            'owner_category_name' => $this->whenLoaded('ownerCategory', fn () => $this->ownerCategory?->name),
            'description' => $this->description,
            'sort' => (int) $this->sort,
            'source_ref' => $this->effectiveSourceRef(),
            'step_key' => $this->effectiveStepKey(),
            'steps_count' => $this->when(isset($this->steps_count), fn () => (int) $this->steps_count),
            // Catégories DISTINCTES qui utilisent la page — ce que la liste annonce.
            'usage_count' => $this->when(isset($this->usage_count), fn () => (int) $this->usage_count),
            'usage' => $this->when(isset($this->usage), fn () => $this->usage),
            'choices_count' => $this->whenLoaded('choices', fn () => $this->choices->count()),
            'choices' => $this->whenLoaded('choices', fn () => $this->choices->map(fn (WizardPageChoice $choice): array => [
                'id' => (int) $choice->id,
                'name' => (string) $choice->name,
                'price' => (float) $choice->price,
                'addon_item_id' => $choice->addon_item_id !== null ? (int) $choice->addon_item_id : null,
                'sort' => (int) $choice->sort,
                'status' => (int) $choice->status,
                'visible_on' => $choice->visible_on,
            ])->values()->all()),
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}
