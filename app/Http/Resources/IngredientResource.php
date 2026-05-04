<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class IngredientResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'global_id' => (string) $this->resource['global_id'],
            'type' => (string) $this->resource['type'],
            'id' => (int) $this->resource['id'],
            'name' => (string) $this->resource['name'],
            'group_label' => $this->resource['group_label'] ?? null,
            'role' => $this->resource['role'] ?? null,
            'is_available' => (bool) $this->resource['is_available'],
            'unavailable_reason' => $this->resource['unavailable_reason'] ?? null,
            'used_by_count' => (int) ($this->resource['used_by_count'] ?? 0),
        ];
    }
}
