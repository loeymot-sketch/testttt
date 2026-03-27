<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Enums\Source;
use App\Events\OrderCreated;
use App\Jobs\SendFcmNotificationJob;

/**
 * [PHASE-36-P1] Send FCM push notifications when a new order is created.
 *
 * Notifications:
 * - Kitchen: "Nouvelle commande #{N}" (always, if not auto-accepted)
 * - POS: "Commande web/kiosque #{N}" (for kiosk/web orders)
 * - Customer: "Commande confirmée #{N}"
 */
class SendFcmOnOrderCreated
{
    /**
     * Handle the event.
     */
    public function handle(OrderCreated $event): void
    {
        $order       = $event->order;
        $branchId    = $order->branch_id;
        $queueNumber = $order->queue_number ?? $order->id;
        $orderId     = $order->id;
        $source      = (int) ($order->source ?? Source::POS);
        $status      = (int) ($order->status ?? OrderStatus::PENDING);

        if (empty($branchId)) {
            return;
        }

        // Kitchen notification (unless auto-accepted and already notifying via status change)
        if (!in_array($status, [OrderStatus::ACCEPT, OrderStatus::PREPARING], true)) {
            SendFcmNotificationJob::dispatch(
                null,
                "kitchen_branch_{$branchId}",
                "Nouvelle commande #{$queueNumber}",
                "En attente de validation",
                [
                    'order_id'     => $orderId,
                    'queue_number' => $queueNumber,
                    'type'         => 'new_order',
                    'source'       => $source,
                ]
            );
        }

        // POS notification for non-POS orders (kiosk, web, app)
        if (!in_array($source, [Source::POS, Source::WEB], true)) {
            SendFcmNotificationJob::dispatch(
                null,
                "pos_branch_{$branchId}",
                $this->getSourceLabel($source) . " #{$queueNumber}",
                "Nouvelle commande à traiter",
                [
                    'order_id'     => $orderId,
                    'queue_number' => $queueNumber,
                    'type'         => 'external_order',
                    'source'       => $source,
                ]
            );
        }

        // Customer notification (if we had device token in future)
        SendFcmNotificationJob::dispatch(
            null,
            "customer_order_{$orderId}",
            "Commande #{$queueNumber} confirmée",
            "Votre commande a été envoyée en cuisine.",
            [
                'order_id'     => $orderId,
                'queue_number' => $queueNumber,
                'type'         => 'order_confirmed',
            ]
        );
    }

    /**
     * Get human-readable source label.
     */
    protected function getSourceLabel(int $source): string
    {
        return match ($source) {
            Source::APP   => 'Commande app',
            Source::POS   => 'Commande POS',
            Source::WEB   => 'Commande web',
            default       => 'Nouvelle commande',
        };
    }
}
