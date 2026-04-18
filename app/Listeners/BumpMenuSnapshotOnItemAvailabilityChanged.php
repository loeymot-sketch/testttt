<?php

namespace App\Listeners;

use App\Events\ItemAvailabilityChanged;
use App\Models\Branch;
use App\Services\Menu\MenuSnapshot;
use Illuminate\Support\Facades\Log;

/**
 * Bumps the branch-scoped menu snapshot version whenever an item availability
 * flip is observed. Branch-scoped events bump exactly one branch; global
 * events (admin edits item) bump every active branch so that every surface
 * re-fetches its projection on the next tick.
 *
 * Runs in-process after {@see PersistItemAvailabilityChangedToOutbox} so the
 * snapshot increment reflects the committed DB state.
 *
 * @see app/Services/Menu/MenuSnapshot.php
 */
class BumpMenuSnapshotOnItemAvailabilityChanged
{
    public function __construct(
        private readonly MenuSnapshot $snapshot,
    ) {
    }

    public function handle(ItemAvailabilityChanged $event): void
    {
        try {
            if ($event->branchId !== null) {
                $this->snapshot->bump($event->branchId);
                return;
            }

            Branch::query()->pluck('id')->each(function (int $branchId): void {
                $this->snapshot->bump($branchId);
            });
        } catch (\Throwable $e) {
            // Snapshot bump is a best-effort freshness hint. A failure here must
            // NOT block the outbox publication, so we swallow and log.
            Log::warning('MenuSnapshot bump failed', [
                'item_id' => $event->itemId,
                'branch_id' => $event->branchId,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
