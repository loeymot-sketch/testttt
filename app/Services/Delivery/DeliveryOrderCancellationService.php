<?php

namespace App\Services\Delivery;

use App\Domain\Delivery\PlatformAdapterRegistry;
use App\Domain\Order\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Models\DeliveryPlatformExternalOrder;
use App\Models\DeliveryWebhookEvent;
use App\Models\FrontendOrder;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [PARALLEL-TRACK-1.3 / Delivery Platform Integration — Phase 3]
 *
 * Sibling of DeliveryOrderIngestionService, scoped to inbound `order.cancelled`
 * events. The IngestionService deliberately short-circuits on non-create
 * events (Phase 2 design — see DeliveryOrderIngestionService.php:103) so
 * cancellations need their own pathway.
 *
 * Lifecycle:
 *   a. Look up the existing DeliveryPlatformExternalOrder by external_id.
 *      No row → unknown order, exit silently (the platform is sometimes
 *      noisy about cancelations of orders we never saw, e.g. test events).
 *   b. Resolve the linked FrontendOrder. No link → mapping never closed
 *      (probably an early-cancel race), exit silently.
 *   c. If the order is already in a terminal state (CANCELED/REJECTED/
 *      DELIVERED/RETURNED) — idempotent no-op. Cancel events can replay
 *      after our processing succeeded; we never want to flip a delivered
 *      order back to CANCELED.
 *   d. OrderStateMachine::apply($order, CANCELED, null, $reason) — the
 *      atomic guard+mutate+audit path. Reason is REQUIRED (see
 *      OrderStateMachine::requiresReason); we extract it from the
 *      adapter-specific payload below or fall back to a generic label.
 *   e. After commit: dispatch OrderStatusChanged so the existing
 *      PersistOrderStatusChangedToOutbox listener fans out to the KDS
 *      and the (now also Phase 3) PushStatusToDeliveryPlatform listener
 *      will *not* echo back to the platform (the change came FROM the
 *      platform; the listener will short-circuit because the new status
 *      maps but pushing CANCELED back to the platform that just told us
 *      it's cancelled is a no-op on their side, or — more importantly —
 *      will trigger the regular retry/persistence flow which is what
 *      we want for forensic completeness).
 *   f. AuditLog row 'delivery.order.cancelled' for the per-branch chain.
 *
 * Frozen-zone respect: this service consumes OrderStateMachine::apply()
 * and AuditLogService verbatim — never mutating their source.
 */
class DeliveryOrderCancellationService
{
    public function __construct(
        private PlatformAdapterRegistry $registry,
        private AuditLogService $auditLog,
    ) {
    }

    /**
     * @return array{
     *   external_order_id: int|null,
     *   frontend_order_id: int|null,
     *   transitioned: bool,
     *   reason: string|null,
     * }
     */
    public function cancel(DeliveryWebhookEvent $event): array
    {
        $platform = (string) $event->platform;
        $payload  = (array) ($event->body ?? []);

        $adapter    = $this->registry->for($platform);
        $externalId = $adapter->externalIdFrom($payload);

        // ---- (a) Lookup external order (BranchScope-bypassed; queue worker has no auth) ---
        /** @var DeliveryPlatformExternalOrder|null $external */
        $external = DeliveryPlatformExternalOrder::withoutGlobalScopes()
            ->where('platform', $platform)
            ->where('external_id', $externalId)
            ->first();

        if ($external === null) {
            Log::info('[DeliveryOrderCancellationService] unknown external_id — ignored', [
                'platform'    => $platform,
                'external_id' => $externalId,
            ]);
            return [
                'external_order_id' => null,
                'frontend_order_id' => null,
                'transitioned'      => false,
                'reason'            => null,
            ];
        }

        // ---- (b) Locate the FrontendOrder ---------------------------------
        $orderId = $external->frontend_order_id;
        if ($orderId === null) {
            Log::info('[DeliveryOrderCancellationService] mapping not yet closed — ignored', [
                'platform'        => $platform,
                'external_id'     => $externalId,
                'external_row_id' => $external->id,
            ]);
            return [
                'external_order_id' => $external->id,
                'frontend_order_id' => null,
                'transitioned'      => false,
                'reason'            => null,
            ];
        }

        /** @var FrontendOrder|null $order */
        $order = FrontendOrder::withoutGlobalScopes()->find($orderId);
        if ($order === null) {
            Log::warning('[DeliveryOrderCancellationService] FrontendOrder vanished', [
                'order_id' => $orderId,
            ]);
            return [
                'external_order_id' => $external->id,
                'frontend_order_id' => null,
                'transitioned'      => false,
                'reason'            => null,
            ];
        }

        // ---- (c) Idempotent guard against terminal states -----------------
        if (in_array((int) $order->status, [
            OrderStatus::CANCELED,
            OrderStatus::REJECTED,
            OrderStatus::DELIVERED,
            OrderStatus::RETURNED,
        ], true)) {
            return [
                'external_order_id' => $external->id,
                'frontend_order_id' => $order->id,
                'transitioned'      => false,
                'reason'            => null,
            ];
        }

        // ---- (d) Extract a human-readable reason from the payload --------
        $reason = $this->extractCancellationReason($platform, $payload);

        // ---- (e) Apply transition + record audit + dispatch event ---------
        $oldStatus = (int) $order->status;
        $branchId  = (int) $order->branch_id;

        DB::transaction(function () use (
            $order,
            $reason,
            $external,
            $platform,
            $externalId,
            $branchId,
            $oldStatus
        ): void {
            // OrderStateMachine::apply runs its own DB::transaction internally;
            // nesting it here keeps the audit row + the external_orders update
            // committed atomically with the status change.
            OrderStateMachine::apply(
                $order,
                OrderStatus::CANCELED,
                actor: null,
                reason: $reason,
            );

            // Close the loop on the external mapping row.
            $external->forceFill([
                'internal_status' => OrderStatus::CANCELED,
                'external_status' => 'cancelled',
            ])->save();

            // ---- (f) Audit chain -----------------------------------------
            $this->auditLog->write([
                'branch_id'   => $branchId,
                'action'      => 'delivery.order.cancelled',
                'resource'    => 'order',
                'resource_id' => $order->id,
                'payload'     => [
                    'platform'        => $platform,
                    'external_id'     => $externalId,
                    'order_id'        => $order->id,
                    'previous_status' => $oldStatus,
                    'reason'          => $reason,
                ],
            ]);
        });

        // ---- After-commit: broadcast OrderStatusChanged ------------------
        $orderForBroadcast = $order;
        $newStatus         = OrderStatus::CANCELED;
        DB::afterCommit(function () use ($orderForBroadcast, $oldStatus, $newStatus): void {
            OrderStatusChanged::dispatch($orderForBroadcast, $oldStatus, $newStatus);
        });

        return [
            'external_order_id' => $external->id,
            'frontend_order_id' => $order->id,
            'transitioned'      => true,
            'reason'            => $reason,
        ];
    }

    /**
     * Best-effort extraction of a cancellation reason from a per-platform
     * payload. Returns a non-empty string so OrderStateMachine::apply does
     * not bail on the requiresReason check (CANCELED is reason-required).
     */
    private function extractCancellationReason(string $platform, array $payload): string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        $candidates = match ($platform) {
            'uber_eats' => [
                $data['cancellation']['details'] ?? null,
                $data['cancellation']['reason'] ?? null,
            ],
            'deliveroo' => [
                $data['cancellation']['reason'] ?? null,
                $data['cancellation']['reason_code'] ?? null,
            ],
            'delicity' => [
                $data['cancellation']['reason'] ?? null,
            ],
            default => [],
        };

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return sprintf('cancelled via %s webhook', $platform);
    }
}
