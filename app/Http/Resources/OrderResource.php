<?php

namespace App\Http\Resources;


use App\Libraries\AppLibrary;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
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
            'id' => $this->id,
            'order_serial_no' => $this->order_serial_no,
            'queue_number' => $this->queue_number,
            '_origin' => $this->source_surface,
            'user_id' => $this->user_id,
            'branch_id' => $this->branch_id,
            'branch_name' => optional($this->branch)->name,
            'order_items' => optional($this->orderItems)->count(),
            "total_currency_price" => AppLibrary::currencyAmountFormat($this->total),
            "total_tax_currency_price" => AppLibrary::currencyAmountFormat($this->total_tax),
            "total_amount_price" => AppLibrary::flatAmountFormat($this->total),
            "discount_currency_price" => AppLibrary::currencyAmountFormat($this->discount),
            "delivery_charge_currency_price" => AppLibrary::currencyAmountFormat($this->delivery_charge),
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'preparation_time' => $this->preparation_time,
            'order_type' => $this->order_type,
            'order_datetime' => AppLibrary::datetime($this->order_datetime),
            'status' => $this->status,
            'is_advance_order' => $this->is_advance_order,
            'status_name' => trans('orderStatus.' . $this->status),
            // [TERRAIN-HEAL 2026-07-16 · ORDER-RES-N1] loadMissing (au lieu de load) : no-op quand
            // list()/deliveredOrder() ont déjà eager-loadé (fin du N+1) ; reste sûr pour les autres
            // appelants (charge une fois si absent) au lieu de forcer un re-query par ligne.
            'customer' => new OrderUserResource($this->user->loadMissing('roles', 'media')),
            'transaction' => new TransactionResource($this->transaction?->loadMissing('order')),
        ];
    }
}
