<?php

namespace App\Listeners;

use App\Events\RefundCreated;
use App\Services\Menu\AvailabilityService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * [F-01 + NEW-05] Compensating release on refund.
 *
 *   - If `refundedItems` is empty → behaves like a full cancel (release all lines).
 *   - Otherwise → releases only the requested line-item quantities.
 *
 * Idempotent via {@see AvailabilityService::releaseForOrderItems}.
 *
 * [GOAL-I2-HEAL-01 2026-05-24] Phase I.4 AMBER I4-CONCERN-02 — defense-in-depth.
 * Currently LAST in RefundCreated cascade (EventServiceProvider:179-186), so a
 * Throwable here doesn't skip any sibling today. We still wrap to (a) mirror the
 * isolation policy across all 4 release listeners, and (b) prevent a future
 * reorder from silently reintroducing the cascade-halt class of bug.
 */
class ReleaseAvailabilityOnRefundCreated
{
    public function __construct(
        private readonly AvailabilityService $availabilityService,
    ) {
    }

    public function handle(RefundCreated $event): void
    {
        try {
            $order = $event->order;
            if (! method_exists($order, 'orderItems')) {
                return;
            }

            // [DEEP-R2 / DEEP-R2b 2026-07-15] Quota journalier : pas de crédit pour une
            // commande d'un jour précédent — mais le ledger released_qty s'écrit TOUJOURS
            // (miroir ReleaseAvailabilityOnOrderCanceled, régression P1 R2b).
            $creditDailyQuota = $order->created_at === null || $order->created_at->isToday();

            $order->loadMissing('orderItems');
            $orderItems = $order->orderItems;

            if ($event->refundedItems === []) {
                $lineItems = $orderItems->map(static function ($orderItem): array {
                    return [
                        'order_item_id' => (int) $orderItem->id,
                        'item_id'       => (int) $orderItem->item_id,
                        'branch_id'     => (int) $orderItem->branch_id,
                        'qty'           => (int) $orderItem->quantity,
                    ];
                })->all();

                if ($lineItems !== []) {
                    $this->availabilityService->releaseForOrderItems($lineItems, $creditDailyQuota);
                }

                return;
            }

            $requestedByOrderItemId = collect($event->refundedItems)
                ->keyBy(static fn (array $item): int => (int) $item['order_item_id']);

            $lineItems = $orderItems
                ->filter(static fn ($orderItem): bool => $requestedByOrderItemId->has((int) $orderItem->id))
                ->map(static function ($orderItem) use ($requestedByOrderItemId): array {
                    $requested = $requestedByOrderItemId->get((int) $orderItem->id);
                    return [
                        'order_item_id' => (int) $orderItem->id,
                        'item_id'       => (int) $orderItem->item_id,
                        'branch_id'     => (int) $orderItem->branch_id,
                        'qty'           => (int) $requested['qty'],
                    ];
                })
                ->values()
                ->all();

            if ($lineItems === []) {
                return;
            }

            $this->availabilityService->releaseForOrderItems($lineItems, $creditDailyQuota);
        } catch (Throwable $e) {
            Log::error('ReleaseAvailabilityOnRefundCreated isolated (cascade continues)', [
                'order_id'  => $event->order->id ?? null,
                'branch_id' => $event->order->branch_id ?? null,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
