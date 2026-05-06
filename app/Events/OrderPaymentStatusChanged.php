<?php

namespace App\Events;

use App\Contracts\BroadcastableOrder;
use App\Events\Concerns\DispatchableAfterCommit;

/**
 * Plain domain event fired when an order's `payment_status` transitions.
 *
 * Mirrors {@see OrderStatusChanged}. Uses {@see DispatchableAfterCommit}
 * (gate C9 — KI-001) so the event is deferred until the surrounding
 * DB::transaction() commits, and dropped entirely on rollback.
 *
 * Plan source : docs/audit/plans/PLAN_P13_PAYMENT_STATUS_STATE_MACHINE_2026-05-06.md
 * Findings    : F-VERIFY-09-01 (PARTIAL → CLOSED) + F-VERIFY-09-10 (OPEN → CLOSED)
 */
class OrderPaymentStatusChanged
{
    use DispatchableAfterCommit;

    public function __construct(
        public BroadcastableOrder $order,
        public int $oldPaymentStatus,
        public int $newPaymentStatus
    ) {
    }
}
