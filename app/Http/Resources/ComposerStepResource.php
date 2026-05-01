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
            'step_key' => $this->step_key,
            'label' => $this->label,
            'source_type' => $this->source_type,
            'source_ref' => $this->source_ref,
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
