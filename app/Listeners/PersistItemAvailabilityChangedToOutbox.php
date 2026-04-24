<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Enums\Status;
use App\Events\ItemAvailabilityChanged;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\Branch;
use App\Models\DomainEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PersistItemAvailabilityChangedToOutbox
{
    public function handle(ItemAvailabilityChanged $event): void
    {
        // [F-04bis] All payloads (global and branch-scoped) MUST include the same
        // contract keys so frontend handlers can rely on `is_available`, `branch_id`
        // and `reason` being PRESENT (possibly null) in every event. Before this
        // fix, global events omitted `is_available`, which made POS/KDS/Kiosk
        // handlers wrongly prune carts on plain price/structural changes (the
        // handler reads `payload.is_available === true` ⇒ false when undefined).
        $payload = [
            'item_id'      => $event->itemId,
            'status'       => $event->status,
            'price'        => $event->price,
            'type'         => $event->type,
            'is_available' => $event->isAvailable,
            'branch_id'    => $event->branchId,
            'reason'       => $event->reason,
        ];

        if ($event->branchId !== null) {
            // Branch-scoped toggle (MENU_86) — single-branch channel.
            $channels = ['private-branch.' . $event->branchId];
        } else {
            // Global menu change (admin edits item) — fan-out to every active branch.
            $channels = Branch::query()
                ->where('status', Status::ACTIVE)
                ->pluck('id')
                ->map(fn (int $branchId): string => 'private-branch.' . $branchId)
                ->values()
                ->all();
        }

        $domainEvent = DomainEvent::query()->create([
            'event_type'     => EventType::MENU_ITEM_AVAILABILITY_CHANGED,
            'aggregate_type' => 'item',
            'aggregate_id'   => $event->itemId,
            'branch_id'      => $event->branchId,
            'payload'        => $payload,
            'channel'        => json_encode($channels),
            'broadcast_as'   => 'ItemAvailabilityChanged',
            'correlation_id' => $this->resolveCorrelationId(),
            'occurred_at'    => now(),
        ]);

        DB::afterCommit(function () use ($domainEvent): void {
            // [Audit Claude NEW-03 B7] Queue lane SSOT = job constructor.
            DispatchDomainEventsJob::dispatch($domainEvent->id);
        });
    }

    private function resolveCorrelationId(): string
    {
        $sharedContext = Log::sharedContext();
        $sharedCorrelationId = is_array($sharedContext) ? ($sharedContext['correlation_id'] ?? null) : null;

        if (is_string($sharedCorrelationId) && trim($sharedCorrelationId) !== '') {
            return $sharedCorrelationId;
        }

        $requestCorrelationId = request()?->header('X-Correlation-ID');

        if (is_string($requestCorrelationId) && trim($requestCorrelationId) !== '') {
            return $requestCorrelationId;
        }

        return (string) Str::uuid();
    }
}
