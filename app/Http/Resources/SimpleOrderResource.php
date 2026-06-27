<?php

namespace App\Http\Resources;


use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
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
        // [Wave S-4 P-OWNER 2026-05-20] Cash-pending detection.
        // Canonical signal: order created by the kiosk paid-at-counter flow
        // (`FrontendOrderService` sets payment_status = PENDING_COUNTER (15)
        // AND pos_payment_method = COUNTER_DEFERRED (6) — see lines 264-266).
        // The POS tracker uses this flag to surface "À ENCAISSER" cards so
        // the cashier sees ONLY orders that genuinely require cash collection;
        // POS cash paid + Kiosk TPE paid + Stripe paid all fall through with
        // is_cash_pending=false → they skip directly to PRÉPARATION (Wave S-1).
        $isCashPending = (int) $this->payment_status === PaymentStatus::PENDING_COUNTER
            && (int) $this->pos_payment_method === PosPaymentMethod::COUNTER_DEFERRED;

        return [
            'id'                           => $this->id,
            'order_serial_no'              => $this->order_serial_no,
            'queue_number'                 => $this->queue_number,
            'order_datetime'               => AppLibrary::datetime($this->order_datetime),
            // [WT-D-R1-F4 2026-05-20] Raw numeric `total` for canonical FR EUR
            // rendering via the shared `formatPrice()` helper on admin surfaces
            // (PosOrderListComponent, tracker). Mirrors OrderDetailsResource
            // shape — `*_currency_price` / `*_amount_price` strings stay for
            // backward-compat consumers (exports, legacy templates) but new
            // admin renders should prefer the raw numeric so every surface
            // produces the same "19,00 €" instead of "19.00" / "19.00€".
            'total'                        => round((float) ($this->total ?? 0), 2),
            'subtotal'                     => round((float) ($this->subtotal ?? 0), 2),
            'discount'                     => round((float) ($this->discount ?? 0), 2),
            'delivery_charge'              => round((float) ($this->delivery_charge ?? 0), 2),
            "total_currency_price"         => AppLibrary::currencyAmountFormat($this->total),
            "total_amount_price"           => AppLibrary::flatAmountFormat($this->total),
            "discount_amount_price"        => AppLibrary::flatAmountFormat($this->discount),
            // [FR-MONEY 2026-06-27] variantes FR « 0,00 € » pour l'affichage liste (sales-report
            // rows, online/table orders) — le flat reste pour les inputs. Le brut « 0.00 » était
            // rendu au commerçant (SalesReportListComponent:197-198).
            "discount_currency_price"      => AppLibrary::currencyAmountFormat($this->discount),
            "delivery_charge_amount_price" => AppLibrary::flatAmountFormat($this->delivery_charge),
            "delivery_charge_currency_price" => AppLibrary::currencyAmountFormat($this->delivery_charge),
            'payment_method'               => $this->payment_method,
            'payment_status'               => $this->payment_status,
            'transaction'                  => $this->transaction ? strtoupper($this->transaction?->payment_method) : null,
            'order_type'                   => $this->order_type,
            'source'                       => $this->source,
            'source_surface'               => $this->source_surface,
            'pos_payment_method'           => $this->pos_payment_method,
            // [GOAL-CAISSE-UNIFIED W-HIST 2026-05-30] NF525 traceability columns
            // for the unified /admin/historique page. Pure projection — both are
            // existing nullable `orders` columns (fiscal_sequence_no allocated at
            // collection per Plan B; parent_order_id links a refund/counter-entry
            // to its origin sale). Exposed so the history table can show the
            // gap-free fiscal number + flag refunds without a second round-trip.
            'fiscal_sequence_no'           => $this->fiscal_sequence_no,
            'parent_order_id'              => $this->parent_order_id,
            'status'                       => $this->status,
            'status_name'                  => trans('orderStatus.' . $this->status),
            'customer_name'                => $this->user?->name,
            // [Wave S-4 P-OWNER 2026-05-20] Suivi commandes "À ENCAISSER"
            // column filter. Pure projection — no business rule changes here:
            // upstream FrontendOrderService stamps the canonical signals at
            // order creation (PENDING_COUNTER + COUNTER_DEFERRED), and the
            // POS encaissement flow (Wave S-5) flips both back to PAID once
            // the cashier collects cash. Frontend reads `is_cash_pending`
            // to filter cards into the À ENCAISSER lane and renders
            // `cash_pending_amount` as the amount due in EUR.
            'is_cash_pending'              => $isCashPending,
            'cash_pending_amount'          => $isCashPending
                ? AppLibrary::flatAmountFormat($this->total)
                : null,
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
            // [Wave Q-1 P-OWNER 2026-05-19] Items summary for the POS tracker
            // cards (suivi commandes). Without this, `PosOrdersTrackerComponent`
            // renders only N°/source/time — caissier ne voyait pas le contenu.
            // N+1 guard: `OrderService::list()` eager-loads
            // `orderItems.orderItem.media/category`; for callers that didn't
            // eager-load we return [] rather than triggering a lazy SELECT.
            // Branch isolation: `OrderItem` enforces BranchScope global.
            // Mirrors SimpleDeliveryBoyOrderResource::resolveItemsForDriver().
            'order_items'                  => $this->resolveItemsForTracker(),
        ];
    }

    /**
     * Lean item list — built from the eager-loaded `orderItems` relation so
     * the resource never executes its own SELECTs (N+1 protection). When the
     * caller has not eager-loaded the relation, we fall back to an empty
     * array rather than triggering a lazy load.
     *
     * Shape consumed by PosOrdersTrackerComponent::itemsPreview() — keys
     * `item_name` and `quantity` are mandatory; `item_id` is included for
     * future linkability without forcing a payload diff later.
     */
    private function resolveItemsForTracker(): array
    {
        $relation = $this->resource->relationLoaded('orderItems')
            ? $this->resource->getRelation('orderItems')
            : null;

        if ($relation === null) {
            return [];
        }

        return $relation->map(function ($line) {
            return [
                'item_id'   => (int) $line->item_id,
                'item_name' => $line->orderItem?->name,
                'quantity'  => (int) $line->quantity,
            ];
        })->values()->all();
    }
}
