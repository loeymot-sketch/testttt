<?php

namespace App\Events;

use App\Events\Concerns\DispatchableAfterCommit;

/**
 * [F-016a-BIS] Branch-scoped variation rupture toggle event.
 *
 * Fired by AvailabilityService::toggleVariation() after the per-(branch,
 * variation) stock_levels row is updated with manual_unavailable_reason.
 * Persisted to the outbox by
 * {@see \App\Listeners\PersistItemVariationAvailabilityChangedToOutbox} and
 * broadcast on the per-branch channel.
 *
 * Payload contract:
 *   { variation_id, branch_id, is_available, reason, changed_at }
 */
class ItemVariationAvailabilityChanged
{
    use DispatchableAfterCommit;

    public int $variationId;
    public int $branchId;
    public bool $isAvailable;
    public ?string $reason;

    public function __construct(
        int $variationId,
        int $branchId,
        bool $isAvailable,
        ?string $reason = null
    ) {
        $this->variationId = $variationId;
        $this->branchId = $branchId;
        $this->isAvailable = $isAvailable;
        $this->reason = $reason;
    }
}
