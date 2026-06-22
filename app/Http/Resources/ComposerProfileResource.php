<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ComposerProfileResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'item_category_id' => $this->item_category_id,
            'template' => $this->template,
            'version' => $this->version,
            'is_published' => $this->is_published,
            'published_at' => optional($this->published_at)->toISOString(),
            'branch_id_scope' => $this->branch_id_scope,
            'steps' => ComposerStepResource::collection($this->whenLoaded('steps')),
        ];
    }
}
