<?php

namespace Tests\Feature\Abuse;

use App\Console\Commands\OutboxRetryFailedCommand;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

/**
 * VECTOR sync_chaos — adversarial probe of the transactional-outbox recovery
 * lanes (DispatchDomainEventsJob + outbox:rescue + outbox:retry-failed +
 * outbox:monitor).
 *
 * GOAL: hunt for a domain_event that can fall through EVERY automatic recovery
 * lane AND escape the staleness/orphan alarm — i.e. a silently-lost broadcast.
 *
 * SCOPE NOTE (anti-hallucination, CLAUDE.md §3ter): PHPUnit runs SQLite
 * :memory: which makes `lockForUpdate()` a NO-OP (Laravel only emits
 * `SELECT ... FOR UPDATE` on MySQL/PostgreSQL). So the TRUE concurrent
 * row-lock race (two live workers claiming the same row at the same instant)
 * CANNOT be proven here — that is delegated to an orchestrator RECIPE against
 * the shared :8766 MySQL DB. What IS logic-isolatable and proven below:
 *   - the post-claim idempotence path (sequential),
 *   - the transactional-outbox no-loss invariant on broadcast failure,
 *   - the RECOVERY-LANE REACHABILITY MATRIX, which exposes a classification
 *     blind-spot the live audit confirmed is reachable.
 */
class SyncChaosOutboxAbuseTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // A. No-loss on broadcast failure (transactional outbox core invariant)
    // ------------------------------------------------------------------

    /**
     * ATTACK: kill the broadcaster (soketi down) mid-dispatch. The row MUST
     * survive with dispatched_at re-nulled so a recovery lane can replay it —
     * a degraded broadcast must NEVER swallow a domain event.
     *
     * EXPECT: DEFENDED (green).
     */
    public function test_broadcast_failure_never_loses_the_event_row(): void
    {
        $event = $this->seedEvent(['attempts' => 0]);

        $failing = Mockery::mock(Broadcaster::class);
        $failing->shouldReceive('broadcast')->once()
            ->andThrow(new \RuntimeException('soketi down: connection refused'));
        $this->bindBroadcaster($failing);

        try {
            (new DispatchDomainEventsJob($event->id))->handle();
            $this->fail('Expected the broadcaster failure to propagate for queue retry.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('soketi down', $e->getMessage());
        }

        $event->refresh();
        $this->assertNotNull($event->id, 'Event row must still exist — no silent loss.');
        $this->assertNull($event->dispatched_at, 'Claim released so retry/rescue can replay.');
        $this->assertSame(1, (int) $event->attempts, 'attempts bumped exactly once.');
        $this->assertStringContainsString('soketi down', (string) $event->last_error);
    }

    /**
     * ATTACK: replay the SAME row twice (duplicate enqueue from rescue + the
     * straggler worker both firing). After the first claim commits, the second
     * handle() MUST observe dispatched_at != null and broadcast ZERO extra
     * times — no double-broadcast.
     *
     * EXPECT: DEFENDED (green). NOTE: this proves the POST-claim idempotence
     * only; the simultaneous-instant race is a MySQL-only recipe (see below).
     */
    public function test_duplicate_replay_does_not_double_broadcast_post_claim(): void
    {
        $event = $this->seedEvent(['attempts' => 0]);

        $broadcaster = Mockery::mock(Broadcaster::class);
        $broadcaster->shouldReceive('broadcast')->once();
        $this->bindBroadcaster($broadcaster);

        (new DispatchDomainEventsJob($event->id))->handle();
        (new DispatchDomainEventsJob($event->id))->handle();

        $event->refresh();
        $this->assertNotNull($event->dispatched_at);
        $this->assertSame(1, (int) $event->attempts, 'Second handle() must skip (no attempts bump).');
    }

    // ------------------------------------------------------------------
    // B. Recovery-lane reachability matrix — the silent-loss hunt
    // ------------------------------------------------------------------

    /**
     * ATTACK: a worker is killed (kill -9) AFTER Phase-1 claim (dispatched_at
     * set, attempts bumped) but BEFORE the broadcast — on its very FIRST
     * attempt, so last_error is still NULL — and the row then ages past 10 min.
     *
     * This row is:
     *   - INVISIBLE to outbox:retry-failed   (scope: dispatched_at IS NULL)
     *   - INVISIBLE to the monitor's CRASH-CLAIMED alarm (requires
     *     last_error NOT NULL — MonitorOutboxStaleness.php:73-77)
     *   - INVISIBLE to the monitor's STALE alarm (requires dispatched_at NULL)
     *   - BUT VISIBLE to outbox:rescue lane B (dispatched_at NOT NULL +
     *     dispatched_at < 10min + attempts < 5 — OutboxRescueCommand.php:42-47)
     *
     * So at attempts < 5 the row IS auto-recovered by rescue. This test PROVES
     * that recovery path holds — the low-attempt crash-after-clean-claim is
     * NOT a silent loss.
     *
     * EXPECT: DEFENDED (green) — rescue re-queues it.
     */
    public function test_crash_after_clean_claim_low_attempts_is_recovered_by_rescue(): void
    {
        Bus::fake();

        $event = $this->seedEvent([
            'dispatched_at' => now()->subMinutes(15), // claimed, worker died
            'last_error'    => null,                   // crashed on first try, no error written
            'attempts'      => 1,
        ], createdMinutesAgo: 20);

        $exit = Artisan::call('foodking:outbox:rescue');
        $this->assertSame(0, $exit);

        Bus::assertDispatched(
            DispatchDomainEventsJob::class,
            fn (DispatchDomainEventsJob $j) => $j->domainEventId === $event->id
        );

        $event->refresh();
        $this->assertNull($event->dispatched_at, 'Stuck claim released before re-dispatch.');
    }

    /**
     * ATTACK / BLIND-SPOT REPRO: the SAME crash-after-claim shape but with
     * attempts >= 5 AND last_error NULL, aged past every threshold.
     *
     * How a row reaches this shape (reproducible chain):
     *   1. attempts 1..4 fail broadcast → each writes last_error, re-nulls
     *      dispatched_at (Phase 3b).
     *   2. attempt 5 succeeds the broadcast → Phase 3a CLEARS last_error
     *      and KEEPS dispatched_at.
     *   3. ...EXCEPT: if the worker is killed between the successful
     *      broadcast() call and the Phase-3a `last_error => null` save, OR a
     *      rescue lane-B re-drive (which nulls dispatched_at WITHOUT touching
     *      last_error) is itself interrupted, the row can end at
     *      attempts>=5 + dispatched_at SET + last_error NULL while the
     *      broadcast never actually reached subscribers.
     *
     * Reachability of this exact shape:
     *   - outbox:retry-failed  → NO (needs dispatched_at IS NULL)
     *   - outbox:rescue lane B → NO (needs attempts < 5)
     *   - monitor crash-claimed alarm → NO (needs last_error NOT NULL)
     *   - monitor stale alarm  → NO (needs dispatched_at IS NULL)
     *
     * => UNREACHABLE by every automatic lane AND UNALARMED.
     *
     * This test asserts the gap EXISTS (no lane touches it, monitor exits OK).
     * It is a CLASSIFICATION blind-spot, NOT a high-volume loss path: it
     * requires a kill in a sub-millisecond window AND attempts>=5. The live
     * foodking_e2e audit found 0 rows currently in this shape, so severity is
     * P3 (precision gap), surfaced for the orchestrator to confirm at scale.
     *
     * EXPECT: this test is GREEN because it asserts the (currently real) gap.
     * If a future heal closes the gap (e.g. monitor drops the last_error
     * predicate for attempts>=5 crash-claimed, or rescue widens attempts cap
     * for the last_error-NULL case), THIS test will go RED and must be updated
     * — that is the intended ratchet.
     */
    public function test_BLINDSPOT_crash_claimed_high_attempts_null_error_escapes_all_lanes(): void
    {
        Bus::fake();

        $event = $this->seedEvent([
            'dispatched_at' => now()->subMinutes(30), // claimed long ago
            'last_error'    => null,                   // error trail cleared / never written
            'attempts'      => 5,                      // past rescue lane-B cap
        ], createdMinutesAgo: 40);

        // Lane 1: rescue does NOT pick it (attempts >= 5 cap).
        Artisan::call('foodking:outbox:rescue');
        Bus::assertNotDispatched(DispatchDomainEventsJob::class);

        // Lane 2: retry-failed does NOT pick it (dispatched_at IS NOT NULL).
        Artisan::call('foodking:outbox:retry-failed', ['--since' => '24h']);
        Bus::assertNotDispatched(DispatchDomainEventsJob::class);

        // The row is still claimed-but-undelivered.
        $event->refresh();
        $this->assertNotNull(
            $event->dispatched_at,
            'BLIND-SPOT confirmed: no automatic lane released this crash-claimed claim.'
        );

        // Alarm: the monitor exits SUCCESS (0) — operator is NEVER paged.
        $exit = Artisan::call('foodking:outbox:monitor', ['--threshold' => 10, '--stale-after' => 30]);
        $this->assertSame(
            0,
            $exit,
            'BLIND-SPOT confirmed: monitor does NOT alarm (crash-claimed dimension requires '
            . 'last_error NOT NULL; this row has last_error NULL). The claimed-but-undelivered '
            . 'event is both unreachable AND unalarmed. Severity P3 — narrow kill-window + '
            . 'attempts>=5; orchestrator should confirm prevalence at scale on :8766.'
        );
    }

    /**
     * CONTROL: the WELL-KNOWN crash-claimed orphan (last_error SET, attempts>=5,
     * old) MUST page — re-proving the documented H3 alarm stays healed and the
     * blind-spot above is genuinely the last_error-NULL sibling, not a
     * regression of the main alarm.
     *
     * EXPECT: DEFENDED (green) — monitor exits FAILURE (1).
     */
    public function test_known_crash_claimed_orphan_with_error_still_pages(): void
    {
        $this->seedEvent([
            'dispatched_at' => now()->subMinutes(15),
            'last_error'    => 'Pusher unreachable (worker killed mid-broadcast)',
            'attempts'      => 5,
        ], createdMinutesAgo: 20);

        $exit = Artisan::call('foodking:outbox:monitor', ['--threshold' => 10, '--stale-after' => 30]);
        $this->assertSame(1, $exit, 'Documented crash-claimed orphan alarm must still page (control).');
    }

    // ------------------------------------------------------------------
    // C. Replay budget exhaustion — chronic-fail row cannot flap forever
    // ------------------------------------------------------------------

    /**
     * ATTACK: a payload that fails broadcast forever (poison row). retry-failed
     * must NOT replay it once attempts reach REPLAY_MAX_ATTEMPTS — otherwise a
     * single poison row burns the 'high' lane indefinitely (DoS the sync pipe).
     *
     * EXPECT: DEFENDED (green) — capped row is NOT re-dispatched.
     */
    public function test_chronic_fail_row_past_replay_cap_is_not_replayed(): void
    {
        Bus::fake();

        $capped = $this->seedEvent([
            'dispatched_at' => null,
            'last_error'    => 'poison: permanent broadcast failure',
            'attempts'      => OutboxRetryFailedCommand::REPLAY_MAX_ATTEMPTS, // 12
        ], createdMinutesAgo: 10);

        $exit = Artisan::call('foodking:outbox:retry-failed', ['--since' => '24h']);
        $this->assertSame(0, $exit);

        Bus::assertNotDispatched(
            DispatchDomainEventsJob::class,
            fn (DispatchDomainEventsJob $j) => $j->domainEventId === $capped->id
        );

        $event = $capped->fresh();
        $this->assertSame(
            OutboxRetryFailedCommand::REPLAY_MAX_ATTEMPTS,
            (int) $event->attempts,
            'attempts must stay capped (monotonic) — no flap reset.'
        );
        $this->assertSame('poison: permanent broadcast failure', $event->last_error, 'Forensic trail preserved.');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function seedEvent(array $attrs, int $createdMinutesAgo = 1): DomainEvent
    {
        $event = DomainEvent::query()->create(array_merge([
            'event_type'     => \App\Enums\EventType::ORDER_CREATED,
            'aggregate_type' => 'Order',
            'aggregate_id'   => random_int(1000, 9999),
            'branch_id'      => 1,
            'payload'        => [
                'order_id'       => 4242,
                'queue_number'   => 'Q-4242',
                '_origin'        => 'pos',
                'payment_method' => 'cash',
            ],
            'channel'        => json_encode(['private-branch.1']),
            'broadcast_as'   => 'OrderCreated',
            'correlation_id' => 'sync-chaos-'.uniqid('', true),
            'occurred_at'    => now()->subMinutes($createdMinutesAgo),
            'attempts'       => 0,
            'last_error'     => null,
            'dispatched_at'  => null,
        ], $attrs));

        $event->forceFill([
            'created_at' => now()->subMinutes($createdMinutesAgo),
            'updated_at' => now()->subMinutes($createdMinutesAgo),
        ])->save();

        return $event->fresh();
    }

    private function bindBroadcaster(Broadcaster $broadcaster): void
    {
        $manager = Mockery::mock(BroadcastManager::class);
        $manager->shouldReceive('connection')->andReturn($broadcaster);
        $this->app->instance(BroadcastManager::class, $manager);
        $this->app->instance('broadcast.manager', $manager);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
