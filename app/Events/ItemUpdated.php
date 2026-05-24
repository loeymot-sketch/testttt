<?php

namespace App\Events;

use App\Events\Concerns\DispatchableAfterCommit;

/**
 * [GOAL-I2-HEAL-02 2026-05-24] Phase I.3 RISK-01 AMBER:
 * Fired when admin updates an item (rename, reprice, description, …).
 * Mirrors ItemCreated / ItemDeleted symmetry so that kiosk menu cache
 * (`kiosk.menu.branch.{id}`) is invalidated immediately instead of
 * waiting for the 60s TTL.
 *
 * Uses DispatchableAfterCommit so cache invalidation never fires
 * before the DB write is durable (NF525 chain integrity preserved).
 */
class ItemUpdated
{
    use DispatchableAfterCommit;

    public function __construct(
        public int $itemId,
        public ?int $branchId = null
    ) {
    }
}
