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
            'item_addons'     => $this->resolveAddonsForKds(),
            'instruction'     => $this->instruction,
        ];
    }

    /**
     * KDS must see immutable composer addons (drink/side/dessert/menu choices)
     * that live in composition_snapshot; legacy rows simply have no addons.
     */
    private function resolveAddonsForKds(): array
    {
        $snapshot = $this->safeJsonDecodeArray($this->composition_snapshot);
        if (isset($snapshot['addons']) && is_array($snapshot['addons'])) {
            return array_values($snapshot['addons']);
        }

        return [];
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

    private function safeJsonDecodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (empty($value) || ! is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
    }
}
