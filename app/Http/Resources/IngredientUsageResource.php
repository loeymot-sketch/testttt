<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class IngredientUsageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'global_id' => $this['global_id'],
            'type' => $this['type'],
            'id' => $this['id'],
            'name' => $this['name'],
            'is_available' => $this['is_available'],
            'used_by' => $this['used_by'],
            'used_by_count' => count($this['used_by']),
        ];
    }
}
