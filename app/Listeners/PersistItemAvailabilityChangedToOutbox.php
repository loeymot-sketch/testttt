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
            $channels = self::channelsForBranch((int) $event->branchId);
        } else {
            // Global menu change (admin edits item) — fan-out to every active branch.
            // [ultra-goal A3 heal 2026-05-13] Accept legacy `1` alongside Status::ACTIVE (=5);
            // production DB has status=1 from pre-enum-migration seeding. See
            // PersistCatalogChangedToOutbox for the same heal + owner-action note.
            $channels = Branch::query()
                ->whereIn('status', [Status::ACTIVE, 1])
                ->pluck('id')
                ->flatMap(fn (int $branchId): array => self::channelsForBranch((int) $branchId))
                ->values()
                ->all();
        }

        $correlationId = $this->resolveCorrelationId();

        // [iter15-P1b — ref iter14 SPECIALIST-2 pattern]
        // Availability is a transition (toggle) and is NOT one-shot — the same
        // (item × branch) tuple can flip true→false→true across legitimate
        // distinct requests. Scope dedupe to the originating request via
        // correlation_id so a duplicate listener fire within the same request
        // collapses, but the next legitimate toggle gets a fresh row. Key also
        // distinguishes the global emission (branch_id=null) from a per-branch
        // emission for the same item in the same request — they MUST coexist
        // because they target different downstream channels.
        $idempotencyKey = sha1(implode('|', [
            EventType::MENU_ITEM_AVAILABILITY_CHANGED,
            (int) $event->itemId,
            $event->branchId === null ? 'global' : (int) $event->branchId,
            $event->isAvailable === null ? 'null' : ($event->isAvailable ? '1' : '0'),
            (string) $event->type,
            $correlationId,
        ]));

        $domainEvent = DomainEvent::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'event_type'     => EventType::MENU_ITEM_AVAILABILITY_CHANGED,
                'aggregate_type' => 'item',
                'aggregate_id'   => $event->itemId,
                'branch_id'      => $event->branchId,
                'payload'        => $payload,
                'channel'        => json_encode($channels),
                'broadcast_as'   => 'ItemAvailabilityChanged',
                'correlation_id' => $correlationId,
                'occurred_at'    => now(),
            ]
        );

        // [Sprint 5C Z8-P1-01 2026-05-16] Skip afterCommit dispatch on listener
        // replay (firstOrCreate returned existing row). Parity with
        // PersistOrderCreatedToOutbox / PersistCatalogChangedToOutbox.
        if (! $domainEvent->wasRecentlyCreated) {
            return;
        }

        DB::afterCommit(function () use ($domainEvent): void {
            // [Audit Claude NEW-03 B7] Queue lane SSOT = job constructor.
            // [test-e2e fix E-001 round-2 2026-05-11] Under QUEUE_CONNECTION=sync
            // (dev / CI) the job runs INLINE here and any broadcaster failure
            // (Pusher port 6001 unreachable, Soketi down, network blip) bubbles
            // up through DB::afterCommit → DB::transaction → AvailabilityController
            // and produces an HTTP 500. The outbox row IS already persisted
            // (firstOrCreate above committed before this callback runs), so the
            // dispatch handoff is best-effort: a re-dispatcher
            // (foodking:outbox:retry-failed cron) picks up rows with
            // dispatched_at=null. Same defect class as B-001 FCM bubble.
            try {
                DispatchDomainEventsJob::dispatch($domainEvent->id);
            } catch (\Throwable $broadcastException) {
                Log::warning('[Outbox] DispatchDomainEventsJob inline dispatch failed (non-blocking)', [
                    'domain_event_id' => $domainEvent->id,
                    'event_type'      => $domainEvent->event_type,
                    'aggregate_id'    => $domainEvent->aggregate_id,
                    'branch_id'       => $domainEvent->branch_id,
                    'error'           => $broadcastException->getMessage(),
                    'gate'            => 'test-e2e-fix-E-001-round-2',
                ]);
                // Do not bubble — outbox row is persisted; outbox-retry cron
                // will re-attempt dispatch when broadcaster recovers.
            }
        });
    }

    /**
     * Channel fan-out for one branch's availability event.
     *
     * [WEB-86-SYNC 2026-07-19] Availability (86 / rupture) is emitted on TWO channels:
     *   - `private-branch.{id}` — STAFF real-time (POS/KDS/Kiosk), auth-gated
     *     (routes/channels.php restricts to kiosk-token/own-branch/Admin). UNCHANGED.
     *   - `public-menu.{id}`   — CUSTOMER-facing web. Laravel treats any name WITHOUT
     *     the `private-`/`presence-` prefix as a PUBLIC channel → the unauthenticated
     *     web can subscribe (Echo.channel('public-menu.1')) without a token. This mirrors
     *     the kiosk private path so the web greys a 86'd item in quasi-real-time.
     *
     * Safe to broadcast the SAME outbox payload on the public channel: the availability
     * envelope is PII-free by contract (item_id/status/price/type/is_available/branch_id/
     * reason) — the exact catalogue facts already exposed by the PUBLIC GET /api/frontend/item.
     * Polling that same endpoint remains the reliable SSOT socle; this WS hop is the
     * instant bonus (a re-poll trigger for web clients).
     *
     * @return array<int, string>
     */
    private static function channelsForBranch(int $branchId): array
    {
        return [
            'private-branch.' . $branchId,
            'public-menu.' . $branchId,
        ];
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
