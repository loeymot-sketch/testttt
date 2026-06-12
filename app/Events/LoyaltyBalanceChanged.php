<?php

namespace App\Events;

use App\Events\Concerns\DispatchableAfterCommit;

/**
 * [GOAL LOYALTY_UNIFIED_SYNC L2 2026-06-11]
 *
 * Emitted whenever a customer's loyalty balance changes (earn on delivery,
 * POS redeem, kiosk/frontend redeem, refund credit-back, clawback, welcome
 * bonus). Before this event NO loyalty movement was ever pushed to the bus —
 * a cashier with the redeem modal open saw a stale balance forever
 * (e2e loyalty-global 2026-06-10).
 *
 * Plain event (NOT ShouldBroadcast) following the outbox pattern of
 * {@see KdsOrderRecalled}: {@see \App\Listeners\PersistLoyaltyBalanceChangedToOutbox}
 * persists a domain_events row fanned out on `private-branch.{branchId}`.
 * Payload is balance-only (no PII: no name/phone/code — WP-07 discipline).
 *
 * Uses DispatchableAfterCommit so the push only fires once the surrounding
 * DB transaction commits (and is dropped on rollback).
 */
class LoyaltyBalanceChanged
{
    use DispatchableAfterCommit;

    public function __construct(
        public int $userId,
        public int $branchId,
        public int $balanceAfter,
        public int $delta,
        public string $reason, // earn|redeem|clawback|refund|welcome
        public ?string $correlationId = null
    ) {
    }
}
