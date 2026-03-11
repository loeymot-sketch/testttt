<?php

namespace App\Http\Resources;


use Illuminate\Http\Resources\Json\JsonResource;

class GatewayOptionsResource extends JsonResource
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
            'id' => $this->id,
            'option' => $this->option,
            'value' => $this->value,
            'type' => $this->type,
            'activities' => $this->safeJsonDecode($this->activities)
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
