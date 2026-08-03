<?php

namespace App\Http\Resources;

use App\Enums\Ask;
use App\Enums\OrderType;
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
            // [N-HEAL-02 M-KDS-4 F-01 2026-05-24] KdsHistoryDrawer renders
            // `<time :datetime="order.updated_at">{{ formatTime(order.updated_at) }}</time>`
            // — without exposing updated_at the bumped-at cell is permanently empty.
            'updated_at'                          => $this->updated_at?->toIso8601String(),
            'order_datetime'                      => AppLibrary::datetime($this->order_datetime),
            'order_date'                          => AppLibrary::date($this->order_datetime),
            'order_time'                          => AppLibrary::time($this->order_datetime),
            'delivery_date'                       => $this->is_advance_order == Ask::YES ? AppLibrary::increaseDate($this->order_datetime, 1) : AppLibrary::date($this->order_datetime),
            'delivery_time'                       => $this->is_advance_order == Ask::YES ? AppLibrary::deliveryTime($this->delivery_time) : AppLibrary::deliveryTimeCheck($this->delivery_time),
            'is_advance_order'                    => $this->is_advance_order,
            // [E4 SCHEDULED-INTAKE 2026-07-20] Commande programmée : heure cible ISO
            // + rendu court H:i (cast datetime = tz app) pour la carte cuisine et le
            // bandeau « ⏰ programmées à venir » (KitchenReleaseRule W4). NULL = ASAP
            // (les deux champs restent null). Projection pure, SELECT-only.
            'scheduled_at'                        => $this->scheduled_at?->toIso8601String(),
            'scheduled_hm'                        => $this->scheduled_at?->format('H:i'),
            // [KDS-SCHEDULED-CARD-MISLEADS 2026-07-22] Instant de LIBÉRATION cuisine
            // d'une programmée = scheduled_at − lead (le moment où elle entre sur le
            // board actif). La carte ancre son chrono « ATTENTE » ici et NON sur
            // created_at : une programmée passée des heures à l'avance affichait un
            // faux « en retard » monstrueux dès sa libération à T−lead. Le lead vient
            // du SSOT KitchenReleaseRule (serveur — pas de duplication front). NULL =
            // ASAP → la carte retombe sur created_at (100 % inchangé).
            'kitchen_timer_anchor_iso'            => $this->scheduled_at
                ? $this->scheduled_at->copy()
                    ->subMinutes(\App\Domain\Kds\KitchenReleaseRule::scheduledLeadMinutes())
                    ->toIso8601String()
                : null,
            'preparation_time'                    => $this->preparation_time,
            // [KITCHEN-TIMING 2026-07-03] horodatages RÉELS du parcours cuisine (vs l'estimé
            // preparation_time) + temps de préparation réel mesuré en secondes (accepted→prepared).
            // Null tant que non franchi / pour les anciennes commandes. Socle analytique productivité.
            'accepted_at_iso'                     => $this->accepted_at?->toIso8601String(),
            'preparing_at_iso'                    => $this->preparing_at?->toIso8601String(),
            'prepared_at_iso'                     => $this->prepared_at?->toIso8601String(),
            'actual_prep_seconds'                 => ($this->accepted_at && $this->prepared_at)
                ? $this->prepared_at->diffInSeconds($this->accepted_at)
                : null,
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
            // [Sprint 5A Z9-P0-03] GDPR data-minimization: customer.phone is
            // shipped ONLY for DELIVERY orders. Dine-in/takeaway/kiosk KDS
            // cards don't need (and shouldn't expose) the customer phone
            // number; the Vue UI gated rendering on isDeliveryOrder but JSON
            // wire still leaked phone to all KDS WebSocket subscribers.
            'customer'                            => $this->whenLoaded('user', fn () => $this->user ? [
                'name'  => $this->user->name,
                'phone' => ((int) $this->order_type === OrderType::DELIVERY) ? $this->user->phone : null,
            ] : null),
        ];
    }
}
