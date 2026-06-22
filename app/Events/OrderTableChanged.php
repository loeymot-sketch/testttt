<?php

namespace App\Events;

use App\Contracts\BroadcastableOrder;
use App\Events\Concerns\DispatchableAfterCommit;

/**
 * [F-02] Domain event fired when an order's dining table assignment changes
 * (occupy / transfer between two tables in the floorplan).
 *
 * Per orchestrator gate G-2 decision (in_place_with_css_flash): the KDS card
 * must visually flash for ~2s on receipt and update its table label without
 * being re-printed or re-sounded — this gives the kitchen a clear "table moved"
 * signal without disrupting the prep queue.
 *
 * Uses {@see DispatchableAfterCommit} so the event only fires once the
 * surrounding DB::transaction() (table lock + Order.dining_table_id update)
 * has committed. Multi-tenant: payload always includes branch_id so the
 * outbox listener can scope broadcast to the correct branch channel.
 *
 * NOT broadcast directly — the outbox persists the payload and the dispatcher
 * fans it out via private-branch.{branchId}.
 */
class OrderTableChanged
{
    use DispatchableAfterCommit;

    public function __construct(
        public readonly BroadcastableOrder $order,
        public readonly ?int $previousTableId,
        public readonly int $newTableId,
        public readonly string $action = 'transfer'
    ) {
    }
}
