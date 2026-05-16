<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentTerminalResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'branch_id'      => $this->branch_id,
            'branch_name'    => optional($this->branch)->name,
            'name'           => $this->name,
            'gateway_type'   => $this->gateway_type,
            // Decimal cast → cast back to float for API response stability
            'fee_percent'    => (float) $this->fee_percent,
            'fee_fixed'      => (float) $this->fee_fixed,
            'serial_number'  => $this->serial_number,
            'status'         => (int) $this->status,
            'created_at'     => optional($this->created_at)?->toISOString(),
            'updated_at'     => optional($this->updated_at)?->toISOString(),
        ];
    }
}
