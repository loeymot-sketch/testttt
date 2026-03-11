<?php

namespace App\Http\Resources;


use Illuminate\Http\Resources\Json\JsonResource;

class KDSOrderItemsResource extends JsonResource
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
            'item_id'         => $this->item_id,
            'item_name'       => $this->orderItem?->name,
            'quantity'        => $this->quantity,
            'item_variations' => $this->safeJsonDecode($this->item_variations),
            'item_extras'     => $this->safeJsonDecode($this->item_extras),
            'instruction'     => $this->instruction,
        ];
    }

    /**
     * Safely decode JSON with error checking
     */
    private function safeJsonDecode(?string $json): mixed
    {
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
    }
}
