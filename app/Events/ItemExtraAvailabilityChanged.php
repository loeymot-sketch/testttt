<?php

namespace App\Events;

use App\Events\Concerns\DispatchableAfterCommit;

/**
 * [F-016a-BIS] Branch-scoped extra rupture toggle event.
 *
 * Fired by AvailabilityService::toggleExtra() after the per-(branch, extra)
 * stock_levels row is updated with manual_unavailable_reason. Persisted to the
 * outbox by {@see \App\Listeners\PersistItemExtraAvailabilityChangedToOutbox}
 * and broadcast on the per-branch channel so POS / Kiosk / KDS can invalidate
 * cached selections without a full menu refresh.
 *
 * Payload contract (consumed by future StockManager UI + Echo handlers):
 *   { extra_id, branch_id, is_available, reason, changed_at }
 */
class ItemExtraAvailabilityChanged
{
    use DispatchableAfterCommit;

    public int $extraId;
    public int $branchId;
    public bool $isAvailable;
    public ?string $reason;

    public function __construct(
        int $extraId,
        int $branchId,
        bool $isAvailable,
        ?string $reason = null
    ) {
        $this->extraId = $extraId;
        $this->branchId = $branchId;
        $this->isAvailable = $isAvailable;
        $this->reason = $reason;
    }
}
