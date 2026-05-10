<?php

namespace App\Http\Resources;

use App\Enums\Ask;
use App\Enums\PaymentStatus;
use App\Libraries\AppLibrary;
use Illuminate\Http\Resources\Json\JsonResource;

class KDSOrderDetailsResource extends JsonResource
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
            'id'                                  => $this->id,
            'order_serial_no'                     => $this->order_serial_no,
            'token'                               => $this->token,
            'order_type'                          => $this->order_type,
            // [test-e2e fix E-003 round-3] V1 dine-in disabled — kiosk orders are TAKEAWAY;
            // KDS lane bucketing must use source_surface ('kiosk' | 'pos' | 'web' | 'mobile' | 'admin')
            // not order_type, otherwise the "🖥️ Borne" KDS column stays permanently empty.
            'source_surface'                      => $this->source_surface,
            'order_datetime'                      => AppLibrary::datetime($this->order_datetime),
            'order_date'                          => AppLibrary::date($this->order_datetime),
            'order_time'                          => AppLibrary::time($this->order_datetime),
            'delivery_date'                       => $this->is_advance_order == Ask::YES ? AppLibrary::increaseDate($this->order_datetime, 1) : AppLibrary::date($this->order_datetime),
            'delivery_time'                       => $this->is_advance_order == Ask::YES ? AppLibrary::deliveryTime($this->delivery_time) : AppLibrary::deliveryTimeCheck($this->delivery_time),
            'is_advance_order'                    => $this->is_advance_order,
            'preparation_time'                    => $this->preparation_time,
            'status'                              => $this->status,
            'status_name'                         => trans('orderStatus.' . $this->status),
            'payment_status'                      => $this->payment_status,
            'payment_pending_counter'             => (int) $this->payment_status === PaymentStatus::PENDING_COUNTER,
            'queue_number'                        => $this->queue_number,
            'order_items'                         => OrderItemResource::collection($this->orderItems->loadMissing('orderItem')),
            'table_name'                          => $this->diningTable?->name,
        ];
    }
}
