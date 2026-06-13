<?php

namespace App\Events;

use App\Events\Concerns\DispatchableAfterCommit;

/**
 * [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]
 *
 * Emitted when a chef recalls an order within the 60-second grace window
 * after the PREPARING → PREPARED bump. The recall is a COMPENSATING ACTION:
 *  - `orders.status` is NOT mutated (stays PREPARED — append-only invariant)
 *  - an OrderStatusTransition row with reason='kitchen_recall' is appended
 *  - this event is broadcast on `private-branch.{branchId}` so KDS boards on
 *    other stations re-inject the card with a RAPPELÉ badge for 60s
 *  - the customer-facing OSS "Prêt" notification is NOT downgraded
 *    (NF525 ledger view-only — what the customer saw stays seen)
 *
 * Deliberately NOT reusing `OrderStatusChanged`: that event fans out to
 * `SendOrderMail`, `SendOrderSms`, `SendOrderPush` listeners which would
 * spuriously re-notify the customer. The recall is internal KDS only.
 *
 * Uses DispatchableAfterCommit (gate C9 — KI-001) so the broadcast is
 * deferred until the surrounding DB::transaction() commits, and dropped
 * entirely on rollback.
 */
class KdsOrderRecalled
{
    use DispatchableAfterCommit;

    public function __construct(
        public int $orderId,
        public int $branchId,
        // [KDS-OSS-V2 2026-06-13] queue_number is varchar(20) — alphanumeric
        // pickup numbers (e.g. "R0501") must survive the broadcast. Was ?int
        // which silently coerced to 0.
        public ?string $queueNumber,
        public int $actorId,
        public string $recalledAt,
        public string $correlationId
    ) {
    }
}
