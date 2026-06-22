<?php

namespace App\Http\Resources;


use Illuminate\Http\Resources\Json\JsonResource;

class ItemAttributeResource extends JsonResource
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
            'id'           => $this->id,
            'name'         => $this->name,
            'status'       => $this->status,
            'min_select'   => (int) ($this->min_select ?? 0),
            'max_select'   => (int) ($this->max_select ?? 1),
            'allow_repeat' => (bool) ($this->allow_repeat ?? false),
        ];
    }

}
