<?php

namespace App\Http\Resources;

use App\Enums\Ask;
use App\Libraries\AppLibrary;
use Illuminate\Http\Resources\Json\JsonResource;

class SimpleDeliveryBoyOrderResource extends JsonResource
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
            'id'                             => $this->id,
            'order_serial_no'                => $this->order_serial_no,
            'order_type'                     => $this->order_type,
            'order_datetime'                 => AppLibrary::datetime($this->order_datetime, 'H:i, d-m-Y'),
            'order_date'                     => AppLibrary::date($this->order_datetime),
            'order_time'                     => AppLibrary::time($this->order_datetime, 'H:i'),
            'delivery_date'                  => $this->is_advance_order == Ask::YES ? AppLibrary::increaseDate(
                $this->order_datetime,
                1
            ) : AppLibrary::date($this->order_datetime),
            'delivery_time'    => AppLibrary::deliveryTime($this->delivery_time),
            'payment_method'   => $this->payment_method,
            'payment_status'   => $this->payment_status,
            'is_advance_order' => $this->is_advance_order,
            'status'           => $this->status,
            'status_name'      => trans('orderStatus.' . $this->status),
            'reason'           => $this->reason,
            'order_address'    => new AddressResource($this->address),
            // [GOAL-2026-05-18 P1-LIV-01] Items list so the livreur sees what
            // they are carrying from the `index` payload (was MISSING — driver
            // had to round-trip `/show/{id}` per order, defeating the purpose
            // of the list view). Lean shape — names, qty, price, snapshot.
            'items'            => $this->resolveItemsForDriver(),
        ];
    }

    /**
     * Lean item list — built from the eager-loaded orderItems collection so
     * the resource never executes its own SELECTs (N+1 protection). When the
     * caller has not loaded orderItems, we fall back to an empty array rather
     * than triggering a lazy load.
     */
    private function resolveItemsForDriver(): array
    {
        $relation = $this->resource->relationLoaded('orderItems')
            ? $this->resource->getRelation('orderItems')
            : null;

        if ($relation === null) {
            return [];
        }

        return $relation->map(function ($line) {
            return [
                'item_id'      => (int) $line->item_id,
                'item_name'    => $line->orderItem?->name,
                'quantity'     => (int) $line->quantity,
                'price'        => AppLibrary::currencyAmountFormat($line->price),
                'total_price'  => AppLibrary::currencyAmountFormat($line->total_price),
                'instruction'  => $line->instruction,
            ];
        })->values()->all();
    }
}
