<?php

namespace App\Http\Resources;

use App\Models\DeliveryBoyCashMovement;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * [V1.0.2 Sub-6.3 BUILD-1 — 2026-05-18] API Resource for DeliveryBoyCashSession.
 *
 * Mirrors `CashDrawerSessionController::serialize()` shape (lines 202-219)
 * plus livreur-specific fields (delivery_boy_id, reconciled_by_user_id).
 * Movements are conditionally included via `whenLoaded('movements')` so
 * `index()` stays lean while `show()` returns full detail.
 */
class DeliveryBoyCashSessionResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string,mixed>
     */
    public function toArray($request): array
    {
        return [
            'id'                      => $this->id,
            'branch_id'               => $this->branch_id,
            'delivery_boy_id'         => $this->delivery_boy_id,
            'opened_by_user_id'       => $this->opened_by_user_id,
            'closed_by_user_id'       => $this->closed_by_user_id,
            'reconciled_by_user_id'   => $this->reconciled_by_user_id,
            'opened_at'               => optional($this->opened_at)->toIso8601String(),
            'closed_at'               => optional($this->closed_at)->toIso8601String(),
            'opening_amount'          => (float) $this->opening_amount,
            'closing_amount'          => $this->closing_amount === null ? null : (float) $this->closing_amount,
            'expected_closing_amount' => $this->expected_closing_amount === null ? null : (float) $this->expected_closing_amount,
            'variance'                => $this->variance === null ? null : (float) $this->variance,
            'variance_reason'         => $this->variance_reason,
            'notes'                   => $this->notes,
            'status'                  => $this->status,
            'movements'               => $this->whenLoaded('movements', function () {
                return $this->movements->map(fn (DeliveryBoyCashMovement $m) => [
                    'id'         => $m->id,
                    'order_id'   => $m->order_id,
                    'type'       => $m->type,
                    'amount'     => (float) $m->amount,
                    'direction'  => $m->direction,
                    'notes'      => $m->notes,
                    'created_at' => optional($m->created_at)->toIso8601String(),
                ])->values()->all();
            }),
        ];
    }
}
