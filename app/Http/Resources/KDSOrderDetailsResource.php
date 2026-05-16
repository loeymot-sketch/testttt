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
            // [kds/sprint-2 B-1] ISO8601 for FIFO sort + age math on the client.
            // Eloquent already casts `created_at` to Carbon; we add a stable wire
            // format that the new unified-queue grid sorts on without local-tz drift.
            'created_at_iso'                      => $this->created_at?->toIso8601String(),
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
            // [Sprint 2A DEL-3 2026-05-16] Expose delivery address + customer
            // contact so the chef / livreur can actually fulfil DELIVERY orders.
            // Order::address() is hasOne (not orderAddress) — see Order.php:147.
            // Schema (migration 2023_02_20_180253) only ships:
            //   id, order_id, user_id, label, address, apartment, latitude, longitude.
            // `instructions` / `floor` columns do NOT exist — do not expose phantom fields.
            // User is BranchScope-exempt + withTrashed() (see Order::user()), so the
            // eager-load by KitchenDisplaySystemOrderService is multi-tenant-safe.
            'order_address'                       => $this->whenLoaded('address', fn () => $this->address ? [
                'label'     => $this->address->label,
                'address'   => $this->address->address,
                'apartment' => $this->address->apartment,
                'latitude'  => $this->address->latitude,
                'longitude' => $this->address->longitude,
            ] : null),
            'customer'                            => $this->whenLoaded('user', fn () => $this->user ? [
                'name'  => $this->user->name,
                // E.164 normalization handled upstream (Sprint 2B); raw `phone`
                // column is the canonical contact channel for delivery callbacks.
                'phone' => $this->user->phone,
            ] : null),
        ];
    }
}
