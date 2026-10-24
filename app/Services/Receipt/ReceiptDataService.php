<?php

namespace App\Services\Receipt;

use App\Contracts\BroadcastableOrder;
use App\Models\Order;
use App\Models\User;

/**
 * NF525 receipt data SSOT.
 *
 * Owns the assembly of the six fiscal/operator header fields that
 * appear on every printed POS ticket:
 *   - fiscal_sequence_no  (gap-free per-branch counter)
 *   - pos_register_id     (caisse identifier)
 *   - pos_siret           (établissement)
 *   - pos_vat_intra       (TVA intracommunautaire)
 *   - pos_legal_footer    (mentions légales)
 *   - operator_name       (caissier ayant ouvert la commande : creator_id, jamais le client)
 *
 * Consumed by {@see \App\Http\Resources\OrderDetailsResource} which
 * delegates the six keys above so the HTTP API + the JS-side receipt
 * builder ({@see resources/js/helpers/posReceiptBuilder.js}) converge
 * on a single source of truth. Anything that needs the printed-ticket
 * payload MUST go through this service rather than reading the model
 * directly — see {@see Tests\Feature\Receipt\ReceiptDataServiceWireInTest}.
 *
 * Pure read. No mutation. No pricing computation. No fiscal allocation.
 */
final class ReceiptDataService
{
    /**
     * Database-fetching entry-point. Used by callers that only have an
     * order id (legacy contract, documented in
     * docs/audit/POS_AUDIT_MASTER_PLAN_2026-05-06.md row 23).
     */
    public function buildForOrder(int $orderId): array
    {
        $order = Order::with(['branch', 'user'])->findOrFail($orderId);

        return $this->buildForOrderModel($order);
    }

    /**
     * Model-aware entry-point. Used by OrderDetailsResource which
     * already has the hydrated $order (with branch + user) in scope —
     * avoids a duplicate SELECT per HTTP request.
     *
     * Accepts any `BroadcastableOrder` — both `App\Models\Order` and
     * `App\Models\FrontendOrder` implement this marker interface
     * (cf. app/Contracts/BroadcastableOrder.php "designed so broadcast
     * events can accept either model without a type mismatch"). The
     * previous narrow `Order $order` type-hint silently 500'd every
     * `/api/frontend/order POST` since commit 80fb27c48 (kiosk checkout
     * fully broken, ghost orders persisted before throw). See Foundation
     * Audit failures F1+F2+F3.
     *
     * Returned shape matches {@see buildForOrder()} exactly so both
     * signatures are interchangeable.
     */
    public function buildForOrderModel(BroadcastableOrder $order): array
    {
        return [
            'order_id' => $order->id,
            'order_serial_no' => $order->order_serial_no,
            'fiscal_sequence_no' => $order->fiscal_sequence_no ?? null,
            'pos_register_id' => optional($order->branch)->register_id,
            'pos_siret' => optional($order->branch)->siret,
            'pos_vat_intra' => optional($order->branch)->vat_intra,
            'pos_legal_footer' => optional($order->branch)->legal_footer,
            'operator_name' => $this->operatorName($order),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }

    /**
     * Le caissier = l'opérateur qui a ouvert la commande (`creator_id`), JAMAIS le client.
     * `orders.user_id` porte le CLIENT (« Walking Customer » en caisse, le compte du client en
     * livraison / borne) : le lire ici imprimait « Caissier : <nom du client> » sur le ticket.
     * Sans `creator_id` (commande de borne ou du site, ou ancienne commande), il n'y a pas de
     * caissier : on renvoie null et le ticket n'imprime aucune ligne plutôt qu'un nom faux.
     */
    private function operatorName(BroadcastableOrder $order): ?string
    {
        $creatorId = $order->creator_id ?? null;
        if (! $creatorId) {
            return null;
        }

        return User::withTrashed()->whereKey($creatorId)->value('name');
    }
}
