<?php

namespace Tests\Feature\Outbox;

use App\Enums\EventType;
use App\Enums\Status;
use App\Events\BranchStatusChanged;
use App\Listeners\PersistBranchStatusChangedToOutbox;
use App\Models\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * [T-6.4 — GOAL_V1_PRODUCTION_PERFECT_PHASE2_2026-05-18.md §8 Sub 6.4]
 *
 * Z7-V1.0.2-P2-01 P2 — BranchStatusChanged outbox persist
 * Mirror Wave 5G PersistSettingsUpdatedToOutbox pattern with 5 adaptations:
 *   - single branch (no fan-out — event carries scalar branchId)
 *   - idempotency_key includes status-tuple ($old->$new)
 *   - payload {branch_id, old_status, new_status}
 *   - fire on EVERY real transition (not INACTIVE-only)
 *   - registration AFTER RevokeTokensOnBranchDeactivated (append in EventServiceProvider)
 */
class PersistBranchStatusChangedTest extends TestCase
{
    use RefreshDatabase;

    public function test_persists_domain_event_row_on_branch_status_changed_dispatch(): void
    {
        $listener = new PersistBranchStatusChangedToOutbox();
        $event = new BranchStatusChanged(
            branchId: 1,
            oldStatus: (int) Status::ACTIVE,
            newStatus: (int) Status::INACTIVE,
        );

        $listener->handle($event);

        $this->assertSame(1, DomainEvent::query()->count(), '1 row inserted');

        $row = DomainEvent::query()->first();
        $this->assertSame(EventType::BRANCH_STATUS_CHANGED, $row->event_type);
        $this->assertSame('branch', $row->aggregate_type);
        $this->assertSame(1, (int) $row->aggregate_id);
        $this->assertSame(1, (int) $row->branch_id);
        $this->assertSame('BranchStatusChanged', $row->broadcast_as);
        $this->assertNotNull($row->correlation_id, 'correlation_id populated (uuid fallback for CLI)');

        $payload = is_string($row->payload) ? json_decode($row->payload, true) : $row->payload;
        $this->assertSame(1, $payload['branch_id']);
        $this->assertSame((int) Status::ACTIVE, $payload['old_status']);
        $this->assertSame((int) Status::INACTIVE, $payload['new_status']);

        $channels = is_string($row->channel) ? json_decode($row->channel, true) : $row->channel;
        $this->assertContains('private-branch.1', $channels, 'channel scoped to target branch');
    }

    public function test_was_recently_created_dedup_on_same_idempotency_key(): void
    {
        // Stable correlation_id so idempotency_key collapses between two calls
        // (mirror real HTTP request which carries X-Correlation-ID, OR shared CLI context).
        Log::shareContext(['correlation_id' => 'fixed-correlation-uuid-for-dedup-test']);

        $listener = new PersistBranchStatusChangedToOutbox();
        $event = new BranchStatusChanged(
            branchId: 2,
            oldStatus: (int) Status::ACTIVE,
            newStatus: (int) Status::INACTIVE,
        );

        // Fire twice — second call must dedup via idempotency_key UNIQUE.
        $listener->handle($event);
        $listener->handle($event);

        $this->assertSame(1, DomainEvent::query()->where('branch_id', 2)->count(), 'Dedup: second dispatch reuses row');
    }

    public function test_reverse_transition_inactive_to_active_creates_separate_row(): void
    {
        Log::shareContext(['correlation_id' => 'fixed-correlation-uuid-reverse-test']);

        $listener = new PersistBranchStatusChangedToOutbox();

        // ACTIVE → INACTIVE
        $listener->handle(new BranchStatusChanged(branchId: 3, oldStatus: (int) Status::ACTIVE, newStatus: (int) Status::INACTIVE));
        // INACTIVE → ACTIVE (reverse)
        $listener->handle(new BranchStatusChanged(branchId: 3, oldStatus: (int) Status::INACTIVE, newStatus: (int) Status::ACTIVE));

        $this->assertSame(2, DomainEvent::query()->where('branch_id', 3)->count(), 'Reverse transition = distinct row (status-tuple in idempotency key)');
    }

    public function test_listener_registered_in_event_service_provider_after_revoke_tokens(): void
    {
        // Inspect EventServiceProvider $listen array via reflection (works
        // regardless of how the framework stores listeners internally).
        $provider = new \App\Providers\EventServiceProvider(app());
        $reflection = new \ReflectionClass($provider);
        $listenProp = $reflection->getProperty('listen');
        $listenProp->setAccessible(true);
        $listen = $listenProp->getValue($provider);

        $this->assertArrayHasKey(BranchStatusChanged::class, $listen);
        $listeners = $listen[BranchStatusChanged::class];

        $this->assertContains(\App\Listeners\RevokeTokensOnBranchDeactivated::class, $listeners);
        $this->assertContains(PersistBranchStatusChangedToOutbox::class, $listeners);

        // Order check: RevokeTokens BEFORE PersistToOutbox.
        $revokeIdx = array_search(\App\Listeners\RevokeTokensOnBranchDeactivated::class, $listeners, true);
        $persistIdx = array_search(PersistBranchStatusChangedToOutbox::class, $listeners, true);
        $this->assertLessThan($persistIdx, $revokeIdx, 'RevokeTokens listener runs BEFORE PersistToOutbox');
    }

    public function test_no_op_on_same_status_no_real_transition(): void
    {
        $listener = new PersistBranchStatusChangedToOutbox();
        $listener->handle(new BranchStatusChanged(branchId: 4, oldStatus: (int) Status::ACTIVE, newStatus: (int) Status::ACTIVE));

        $this->assertSame(0, DomainEvent::query()->where('branch_id', 4)->count(), 'No-op guard: same-status transition does not persist');
    }
}
