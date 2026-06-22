<?php

namespace App\Events;

/**
 * Lightweight observability event fired when a sibling listener of OrderCreated
 * fails in isolation (catch-Throwable + log + dispatch this event + DO NOT
 * re-throw) so the cascade can continue and Outbox SSOT is preserved.
 *
 * [WG-2 WF-4 + PK1-ARCH-01 — 2026-05-19] Unified failure-isolation policy.
 *
 * Rationale :
 *   - OrderCreated uses {@see \App\Events\Concerns\DispatchableAfterCommit}, so
 *     all 4 OrderCreated listeners run AFTER the outer DB transaction commits.
 *   - Re-throwing from a sibling listener (a) rolls back NOTHING (commit done),
 *     (b) skips the remaining listeners (notably PersistOrderCreatedToOutbox
 *     which is the KDS/Kiosk/POS sync SSOT), and (c) surfaces a 500 to the POS
 *     client for an order that DOES exist in DB → cashier confusion + dual create.
 *
 * Intentionally **unwired** (no listener registered in EventServiceProvider).
 * Ops alerting can subscribe later. Until then, every dispatch is logged via
 * Log::error in the originating listener — this event class is the structured
 * hook for that drift.
 */
final class StockDecrementFailedEvent
{
    public function __construct(
        public readonly int $branchId,
        public readonly int $orderId,
        public readonly string $listener,
        public readonly string $errorMessage,
        public readonly \DateTimeImmutable $failedAt,
    ) {
    }
}
