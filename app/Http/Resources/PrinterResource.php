<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PrinterResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'branch_name' => optional($this->branch)->name,
            'name' => $this->name,
            'type' => $this->type,
            'host' => $this->host,
            'port' => $this->port,
            'station' => $this->station,
            'width_chars' => $this->width_chars,
            'status' => $this->status,
            'options' => $this->options ?? [],
            'created_at' => optional($this->created_at)?->toISOString(),
            'updated_at' => optional($this->updated_at)?->toISOString(),
        ];
    }
}
