<?php

namespace App\Services;

use App\Domain\Kds\KitchenReleaseRule;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Scopes\BranchScope;

/**
 * [T-C SUIVI-CLIENT 2026-08-16 · GOAL owner] Suivi de commande temps réel
 * public, depuis le téléphone du client — "puisque la commande était rentrée
 * sur notre système, en cours, jusqu'à la dernière étape ; presque prête
 * lorsqu'elle reste entre les deux dernières commandes dans la liste".
 *
 * Identité = `tracking_token` (opaque, généré Order::boot(), PAS `token`/
 * `order_serial_no` qui sont séquentiels et devinables — voir migration
 * 2026_08_16_090000).
 *
 * File « devant » = même sémantique SSOT KitchenReleaseRule que
 * WaitEstimateService/KDS (ne jamais re-définir la file).
 *
 * SELECT-only : zéro impact NF525, zéro écriture.
 */
class OrderTrackingService
{
    /** Nombre de commandes encore devant, à partir duquel on affiche "bientôt prête". */
    public const ALMOST_READY_THRESHOLD = 2;

    public function findByToken(string $trackingToken): ?Order
    {
        return Order::withoutGlobalScope(BranchScope::class)
            ->where('tracking_token', $trackingToken)
            ->first();
    }

    /**
     * @return array{
     *   found: bool,
     *   queue_number: ?string,
     *   status: int,
     *   status_label: string,
     *   step: int,
     *   position_ahead: ?int,
     *   almost_ready: bool,
     *   ready: bool,
     *   wait_low: ?int,
     *   wait_high: ?int,
     *   server_time: string,
     * }
     */
    public function track(string $trackingToken): array
    {
        $now = now(config('app.timezone'));
        $order = $this->findByToken($trackingToken);

        if (! $order) {
            return [
                'found' => false,
                'server_time' => $now->toIso8601String(),
            ];
        }

        $status = (int) $order->status;
        [$step, $label] = $this->stepAndLabel($status);
        $ready = $status === OrderStatus::PREPARED
            || $status === OrderStatus::OUT_FOR_DELIVERY
            || $status === OrderStatus::DELIVERED;

        $positionAhead = null;
        $almostReady = false;

        // Position dans la file uniquement pendant la phase cuisine active
        // (avant PREPARED) — une fois prête, la notion de "devant" n'a plus de sens.
        if (! $ready && in_array($status, KitchenReleaseRule::visibleStatuses(), true)) {
            $query = Order::withoutGlobalScope(BranchScope::class)
                ->where('branch_id', $order->branch_id)
                ->where('id', '!=', $order->id)
                ->whereIn('status', KitchenReleaseRule::visibleStatuses())
                ->where('order_datetime', '<', $order->order_datetime);

            KitchenReleaseRule::applyBoardReleaseFilter($query);
            KitchenReleaseRule::applyScheduledBoardFilter($query, $now);

            $positionAhead = $query->count();
            $almostReady = $positionAhead <= self::ALMOST_READY_THRESHOLD;
        }

        $estimate = $ready ? null : app(WaitEstimateService::class)->estimate((int) $order->branch_id);

        return [
            'found' => true,
            'queue_number' => $order->queue_number ?: $order->order_serial_no,
            'status' => $status,
            'status_label' => $label,
            'step' => $step,
            'position_ahead' => $positionAhead,
            'almost_ready' => $almostReady,
            'ready' => $ready,
            'wait_low' => $estimate['wait_low'] ?? null,
            'wait_high' => $estimate['wait_high'] ?? null,
            'server_time' => $now->toIso8601String(),
        ];
    }

    /**
     * @return array{0:int,1:string}
     */
    private function stepAndLabel(int $status): array
    {
        return match (true) {
            $status === OrderStatus::PENDING => [1, 'Commande reçue'],
            $status === OrderStatus::ACCEPT => [2, 'Commande acceptée'],
            $status === OrderStatus::PREPARING => [3, 'En préparation'],
            $status === OrderStatus::PREPARED => [4, 'Prête'],
            $status === OrderStatus::OUT_FOR_DELIVERY => [4, 'En livraison'],
            $status === OrderStatus::DELIVERED => [5, 'Livrée / Récupérée'],
            $status === OrderStatus::CANCELED => [0, 'Annulée'],
            $status === OrderStatus::REJECTED => [0, 'Refusée'],
            default => [1, 'Commande reçue'],
        };
    }
}
