<?php

namespace App\Events;

use App\Events\Concerns\DispatchableAfterCommit;
use App\Models\Item;

/**
 * Plain domain event fired when an item's availability changes.
 *
 * Two emission modes:
 *  - **Global** (legacy) — admin changes item.status / item.price / item.variations.
 *    Constructed via {@see self::fromItem()}. Broadcast to all active branches.
 *  - **Branch-scoped** (V1 MENU_86) — per-branch rupture toggle / auto-86.
 *    Constructed via {@see self::forBranch()}. Broadcast only on that branch channel.
 *
 * Both paths ride the outbox via {@see \App\Listeners\PersistItemAvailabilityChangedToOutbox}.
 * Payload shape (V1 event contract) always includes `item_id` and `status`; branch mode
 * additionally includes `branch_id`, `is_available`, `reason`.
 */
class ItemAvailabilityChanged
{
    use DispatchableAfterCommit;

    public int     $itemId;
    public int     $status;
    public float   $price;
    public string  $type;
    public ?int    $branchId;
    public ?bool   $isAvailable;
    public ?string $reason;

    public function __construct(
        int $itemId,
        int $status,
        float $price,
        string $type = 'status',
        ?int $branchId = null,
        ?bool $isAvailable = null,
        ?string $reason = null
    ) {
        $this->itemId      = $itemId;
        $this->status      = $status;
        $this->price       = $price;
        $this->type        = $type;
        $this->branchId    = $branchId;
        $this->isAvailable = $isAvailable;
        $this->reason      = $reason;
    }

    /**
     * Global item change (admin edits item status/price/variations).
     * Back-compat with pre-V1 callers (ItemService).
     */
    public static function fromItem(Item $item, string $type = 'status'): self
    {
        return new self(
            itemId: (int) $item->id,
            status: (int) $item->status,
            price: (float) $item->price,
            type: $type,
            branchId: null,
            isAvailable: null,
            reason: null
        );
    }

    /**
     * Branch-scoped availability toggle (MENU_86 rupture flow).
     */
    public static function forBranch(
        int $itemId,
        int $branchId,
        bool $isAvailable,
        ?string $reason,
        float $price = 0.0
    ): self {
        return new self(
            itemId: $itemId,
            status: $isAvailable ? 1 : 0,
            price: $price,
            type: 'branch_availability',
            branchId: $branchId,
            isAvailable: $isAvailable,
            reason: $reason
        );
    }
}
