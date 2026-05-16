<?php

namespace App\Http\Resources;


use App\Enums\OrderType;
use App\Libraries\AppLibrary;
use Illuminate\Http\Resources\Json\JsonResource;

class SimpleOrderResource extends JsonResource
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
            'id'                           => $this->id,
            'order_serial_no'              => $this->order_serial_no,
            'queue_number'                 => $this->queue_number,
            'order_datetime'               => AppLibrary::datetime($this->order_datetime),
            "total_currency_price"         => AppLibrary::currencyAmountFormat($this->total),
            "total_amount_price"           => AppLibrary::flatAmountFormat($this->total),
            "discount_amount_price"        => AppLibrary::flatAmountFormat($this->discount),
            "delivery_charge_amount_price" => AppLibrary::flatAmountFormat($this->delivery_charge),
            'payment_method'               => $this->payment_method,
            'payment_status'               => $this->payment_status,
            'transaction'                  => $this->transaction ? strtoupper($this->transaction?->payment_method) : null,
            'order_type'                   => $this->order_type,
            'source'                       => $this->source,
            'source_surface'               => $this->source_surface,
            'pos_payment_method'           => $this->pos_payment_method,
            'status'                       => $this->status,
            'status_name'                  => trans('orderStatus.' . $this->status),
            'customer_name'                => $this->user?->name,
            // [Sprint 2A DEL-3 2026-05-16] Delivery enrichment subset for the
            // admin orders list / online orders / POS sales report screens that
            // consume SimpleOrderResource. Mirrors KDSOrderDetailsResource shape
            // for downstream JS consumers (KdsOrderCard delivery block, mobile
            // courier app). schema-anchored: only fields backed by columns —
            // `apartment` is nullable, `instructions`/`floor` columns do NOT
            // exist (see migration 2023_02_20_180253).
            'order_address'                => $this->whenLoaded('address', fn () => $this->address ? [
                'label'     => $this->address->label,
                'address'   => $this->address->address,
                'apartment' => $this->address->apartment,
                'latitude'  => $this->address->latitude,
                'longitude' => $this->address->longitude,
            ] : null),
            // [Sprint 5A Z9-P0-03] GDPR data-minimization: ship customer phone
            // ONLY for DELIVERY orders. The KDS/livreur surfaces need it; the
            // admin sales-report / online-orders / POS surfaces do not, and
            // shipping PII unconditionally over the wire is a data-protection
            // defect even though the Vue UI already gated rendering.
            'customer_phone'               => ((int) $this->order_type === OrderType::DELIVERY) ? $this->user?->phone : null,
        ];
    }
}
