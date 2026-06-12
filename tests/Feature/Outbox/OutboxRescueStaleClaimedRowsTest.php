<?php

namespace Tests\Feature\Outbox;

use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Rescue command — claimed-rows contract.
 *
 * HISTORY OF THE CONTRACT (each assertion change documented per W-REM R1
 * task 6 mandate):
 *
 * 1. Heal B.4 (2026-05-19) introduced "lane B": rescue re-queued rows with
 *    `dispatched_at NOT NULL AND dispatched_at < now()-10min AND attempts<5`
 *    to recover workers crashed between Phase 1 claim and Phase 3b release.
 *    This file then asserted lane B PICKS such rows.
 *
 * 2. [W-REM R1 T-R1.1b — 2026-06-12 — F-SHARED-02] Lane B REMOVED, contract
 *    INVERTED. The lane B predicate could not distinguish a crash-claimed
 *    row from a SUCCESSFULLY dispatched one (Phase 3a keeps dispatched_at,
 *    clears last_error) — so every cron tick re-broadcast every dispatched
 *    event older than 10 min, attempts 1→5 ≈ 4 duplicate broadcasts per
 *    event (duplicate KDS re-renders + duplicate notifications). Crash-
 *    claimed orphans are instead PAGED by MonitorOutboxStaleness (H3 alarm
 *    dimension) for manual re-drive.
 *
 *    Assertions changed in step 2:
 *      - test_rescue_picks_crash_claimed_row_past_threshold (assertDispatched
 *        + dispatched_at re-nulled) → REPLACED by
 *        test_rescue_never_requeues_claimed_rows (assertNotDispatched +
 *        dispatched_at PRESERVED). See also OutboxLaneBNoRebroadcastTest.
 *      - lane A tests unchanged (pending lane behaviour preserved, now
 *        additionally bounded + fresh-only — see OutboxRescueTest /
 *        OutboxQuarantineExpiredTest).
 */
class OutboxRescueStaleClaimedRowsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Lane A (pending behaviour) — null-dispatched row past the `stale(2)`
     * minute gate gets rescued. Unchanged since heal B.4.
     */
    public function test_rescue_picks_classic_pending_stale_row(): void
    {
        Bus::fake();

        $event = $this->seedDomainEvent([
            'dispatched_at' => null,
            'attempts' => 0,
        ], createdMinutesAgo: 3);

        $exit = Artisan::call('foodking:outbox:rescue');

        $this->assertSame(0, $exit);
        Bus::assertDispatched(DispatchDomainEventsJob::class, function (DispatchDomainEventsJob $job) use ($event) {
            return $job->domainEventId === $event->id;
        });
    }

    /**
     * [F-SHARED-02 inversion — was test_rescue_picks_crash_claimed_row_past_threshold]
     * A claimed row (dispatched_at NOT NULL), however old, must NEVER be
     * re-queued by rescue: the predicate cannot tell crash-claimed from
     * successfully-dispatched, and re-queueing the latter produced ~4
     * duplicate broadcasts per event. The claim must stay untouched so the
     * monitor's orphan dimension can page for a manual decision.
     */
    public function test_rescue_never_requeues_claimed_rows(): void
    {
        Bus::fake();

        $event = $this->seedDomainEvent([
            'dispatched_at' => now()->subMinutes(15),
            'attempts' => 2,
        ], createdMinutesAgo: 20);

        $exit = Artisan::call('foodking:outbox:rescue');

        $this->assertSame(0, $exit);
        Bus::assertNotDispatched(DispatchDomainEventsJob::class);

        $event->refresh();
        $this->assertNotNull(
            $event->dispatched_at,
            'Claimed rows are NEVER released by rescue (F-SHARED-02) — manual re-drive after monitor page only.'
        );
    }

    /**
     * In-flight claim (younger than any threshold) — must NOT be picked.
     * Unchanged outcome since heal B.4 (stronger now: NO claimed row is
     * ever picked).
     */
    public function test_rescue_ignores_in_flight_claim_under_threshold(): void
    {
        Bus::fake();

        $this->seedDomainEvent([
            'dispatched_at' => now()->subMinutes(2),
            'attempts' => 2,
        ], createdMinutesAgo: 5);

        $exit = Artisan::call('foodking:outbox:rescue');

        $this->assertSame(0, $exit);
        Bus::assertNotDispatched(DispatchDomainEventsJob::class);
    }

    /**
     * Claimed row past the attempts cap — out of rescue scope. Unchanged
     * outcome (claimed rows are categorically excluded post F-SHARED-02).
     */
    public function test_rescue_skips_crash_claimed_row_past_attempts_cap(): void
    {
        Bus::fake();

        $this->seedDomainEvent([
            'dispatched_at' => now()->subMinutes(15),
            'attempts' => 5,
        ], createdMinutesAgo: 20);

        $exit = Artisan::call('foodking:outbox:rescue');

        $this->assertSame(0, $exit);
        Bus::assertNotDispatched(DispatchDomainEventsJob::class);
    }

    /**
     * Pending lane minute-2 gate still active: a freshly-created
     * null-dispatched row should NOT be picked (worker hasn't had its
     * chance yet). Unchanged since heal B.4.
     */
    public function test_rescue_ignores_fresh_pending_row_under_stale_gate(): void
    {
        Bus::fake();

        $this->seedDomainEvent([
            'dispatched_at' => null,
            'attempts' => 0,
        ], createdMinutesAgo: 0);

        $exit = Artisan::call('foodking:outbox:rescue');

        $this->assertSame(0, $exit);
        Bus::assertNotDispatched(DispatchDomainEventsJob::class);
    }

    /**
     * @param  array<string,mixed>  $attrs
     */
    private function seedDomainEvent(array $attrs, int $createdMinutesAgo): DomainEvent
    {
        $event = DomainEvent::query()->create(array_merge([
            'event_type' => 'order.created',
            'aggregate_type' => 'order',
            'aggregate_id' => (string) random_int(1000, 9999),
            'branch_id' => 7,
            'payload' => [],
            'channel' => json_encode(['private-branch.7']),
            'broadcast_as' => 'OrderCreated',
            'correlation_id' => 'test-heal-b4-'.uniqid(),
            'occurred_at' => now()->subMinutes($createdMinutesAgo),
            'last_error' => null,
        ], $attrs));

        $event->forceFill([
            'created_at' => now()->subMinutes($createdMinutesAgo),
            'updated_at' => now()->subMinutes($createdMinutesAgo),
        ])->save();

        return $event->fresh();
    }
}
