<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Jobs\SendFcmNotificationJob;
use Illuminate\Support\Facades\Log;

/**
 * [PHASE-36-P1] Send FCM push notifications when order status changes.
 *
 * Notifications are queued (database driver) so they don't block the HTTP response.
 *
 * Notification mapping:
 * - PREPARING  → Kitchen: "Commande #{N} en préparation"
 * - PREPARED   → Kitchen: "Commande #{N} terminée" + Customer: "Votre commande est prête !"
 * - DELIVERED  → Customer: "Commande #{N} livrée"
 * - CANCELLED  → Customer: "Commande #{N} annulée"
 */
class SendFcmOnOrderStatusChange
{
    /**
     * Handle the event.
     */
    public function handle(OrderStatusChanged $event): void
    {
        $order       = $event->order;
        $newStatus   = $event->newStatus;
        $oldStatus   = $event->oldStatus;
        $branchId    = $order->branch_id;
        $queueNumber = $order->queue_number ?? $order->id;
        $orderId     = $order->id;

        // Skip if no branch ID (shouldn't happen)
        if (empty($branchId)) {
            return;
        }

        // Map status to notification actions
        $notifications = $this->buildNotifications(
            $newStatus,
            $branchId,
            $queueNumber,
            $orderId,
            $order
        );

        // Dispatch queued FCM jobs (non-blocking)
        foreach ($notifications as $notif) {
            SendFcmNotificationJob::dispatch(
                $notif['token'] ?? null,
                $notif['topic'] ?? null,
                $notif['title'],
                $notif['body'],
                array_merge(
                    ['order_id' => $orderId, 'queue_number' => $queueNumber, 'status' => $newStatus],
                    $notif['data'] ?? []
                ),
                $notif['sound'] ?? 'default'
            );
        }
    }

    /**
     * Build notification list based on new status.
     */
    protected function buildNotifications(
        int $newStatus,
        int $branchId,
        string $queueNumber,
        int $orderId,
        $order
    ): array {
        $notifications = [];

        switch ($newStatus) {
            case OrderStatus::PREPARING:
                // Kitchen notification
                $notifications[] = [
                    'topic' => "kitchen_branch_{$branchId}",
                    'title' => "Commande #{$queueNumber}",
                    'body'  => "En cours de préparation",
                    'data'  => ['type' => 'preparing'],
                ];
                break;

            case OrderStatus::PREPARED:
                // Kitchen notification (order done)
                $notifications[] = [
                    'topic' => "kitchen_branch_{$branchId}",
                    'title' => "Commande #{$queueNumber} prête",
                    'body'  => "À récupérer au comptoir",
                    'data'  => ['type' => 'prepared'],
                ];

                // Customer notification (if we had device token)
                // In future: $order->customer->fcm_token
                $notifications[] = [
                    'topic' => "customer_order_{$orderId}",
                    'title' => "Commande #{$queueNumber} prête !",
                    'body'  => "Présentez-vous au comptoir avec votre numéro.",
                    'data'  => ['type' => 'ready'],
                    'sound' => 'order_ready.mp3',
                ];

                // OSS (Order Status Screen) notification for on-site display
                $notifications[] = [
                    'topic' => "oss_branch_{$branchId}",
                    'title' => "Nouveau numéro prêt",
                    'body'  => "#{$queueNumber}",
                    'data'  => ['type' => 'oss_ready', 'queue_number' => $queueNumber],
                ];
                break;

            case OrderStatus::DELIVERED:
                // Customer notification
                $notifications[] = [
                    'topic' => "customer_order_{$orderId}",
                    'title' => "Commande #{$queueNumber} livrée",
                    'body'  => "Merci et à bientôt !",
                    'data'  => ['type' => 'delivered'],
                ];
                break;

            case OrderStatus::CANCELED:
                // Customer notification
                $notifications[] = [
                    'topic' => "customer_order_{$orderId}",
                    'title' => "Commande #{$queueNumber} annulée",
                    'body'  => "Contactez le restaurant pour plus d'informations.",
                    'data'  => ['type' => 'cancelled'],
                ];

                // Kitchen notification (remove from KDS)
                $notifications[] = [
                    'topic' => "kitchen_branch_{$branchId}",
                    'title' => "Commande #{$queueNumber} annulée",
                    'body'  => "Retirer du KDS",
                    'data'  => ['type' => 'cancelled'],
                ];
                break;
        }

        return $notifications;
    }
}
