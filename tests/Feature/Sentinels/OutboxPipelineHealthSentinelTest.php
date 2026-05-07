<?php

namespace Tests\Feature\Sentinels;

use App\Enums\EventType;
use App\Exceptions\PayloadMismatchException;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * @FK-ID         FK-CV1-CI-WEBSOCKETS-HARNESS-001
 * @source        RED-R3 / RED-R5 — adversarial sync rupture audit (2026-05-07).
 * @mission       CV1-CI-WEBSOCKETS-HARNESS-001
 * @runbook       docs/runbooks/CI_WEBSOCKETS_HARNESS.md
 *
 * # WHY THIS SENTINEL EXISTS — read before editing
 * RED-R3 reproduced the following silent failure mode:
 *
 *   Without `php artisan websockets:serve` (or soketi) UP on 127.0.0.1:6001,
 *   AND with BROADCAST_DRIVER=pusher, DispatchDomainEventsJob phase 3b
 *   "release the claim" path always wins. Result on EVERY domain event:
 *     - dispatched_at = NULL forever
 *     - last_error = "cURL error 7: Couldn't connect..."
 *     - attempts climbs to 6 then job lands in failed_jobs.
 *
 * Conversely, with BROADCAST_DRIVER=log (PHPUnit's default in phpunit.xml),
 * the broadcaster always succeeds — but NOTHING reaches Pusher/soketi, so
 * any regression in the Pusher serialization, channel naming, or pusher-php
 * library passes silently.
 *
 * This sentinel is the only place in the suite that asserts:
 *   1) The harness env is honoured (broadcast.default != 'log',
 *      queue.default != 'sync') — i.e. the test is actually testing the
 *      pipeline RED-R3 cares about, not the in-memory log driver.
 *   2) End-to-end happy path: an ItemAvailabilityChanged event row → pulled
 *      by `queue:work --once --queue=high` → dispatched_at set, attempts=1,
 *      last_error null. If a real broadcaster is up, this exercises the
 *      Pusher-protocol HTTP request all the way through.
 *   3) Phase 3b release-claim invariant: when the broadcaster throws, the
 *      claim is released (dispatched_at=null) and last_error is populated,
 *      so the queue retry curve [1, 5, 15, 60, 300]s × 6 tries can run.
 *      This invariant is what RED-R3 documented as the silent-failure root.
 *
 * # HOW TO ACTIVATE
 *   ./scripts/ci-bootstrap-websockets-harness.sh
 *   export CI_WEBSOCKETS_HARNESS=1
 *   export BROADCAST_DRIVER=pusher
 *   export QUEUE_CONNECTION=database
 *   php artisan test --filter=OutboxPipelineHealthSentinel
 *   ./scripts/ci-teardown-websockets-harness.sh
 *
 * # WHEN THIS BREAKS
 * Either (a) the harness was not started before the test ran (skipped tests
 * don't count as passing — CI must keep them un-skipped), or (b) the
 * pipeline regressed. Either way: read RED-R3 in
 * docs/runbooks/CI_WEBSOCKETS_HARNESS.md before editing this file.
 *
 * # INVARIANTS — DO NOT WEAKEN
 * - The `assertHarnessActive()` precondition must NEVER be relaxed to a
 *   silent skip if `CI_WEBSOCKETS_HARNESS=1` IS set but the env overrides
 *   are not. That's the exact failure mode this sentinel exists to prevent.
 * - Test 1 must use `Artisan::call('queue:work', ['--once' => true, ...])`
 *   — not `Bus::fake()`, not `Queue::fake()`. Faking defeats the purpose.
 * - Test 3 must keep the Mockery broadcaster failing path. It documents
 *   the EXACT release-claim contract that RED-R3 caught.
 */
class OutboxPipelineHealthSentinelTest extends TestCase
{
    use RefreshDatabase;

    /** Loud-skip if the harness was not bootstrapped. */
    private function assertHarnessActive(): void
    {
        $flag = getenv('CI_WEBSOCKETS_HARNESS');
        if ($flag !== '1') {
            $this->markTestSkipped(
                'CI_WEBSOCKETS_HARNESS=1 is not set. This sentinel only runs '
                . 'against a real broadcaster on 127.0.0.1:6001. Run '
                . 'scripts/ci-bootstrap-websockets-harness.sh, export '
                . 'CI_WEBSOCKETS_HARNESS=1, BROADCAST_DRIVER=pusher, '
                . 'QUEUE_CONNECTION=database, then re-run the filter. See '
                . 'docs/runbooks/CI_WEBSOCKETS_HARNESS.md for the full procedure.'
            );
        }
    }

    /**
     * Test 1 — End-to-end happy path under the harness.
     *
     * Creates a fresh ItemAvailabilityChanged-shaped DomainEvent row,
     * dispatches the job, runs `queue:work --once --queue=high`, then
     * asserts the row was dispatched (dispatched_at set, attempts=1,
     * last_error null). On a CI runner with soketi UP, this exercises the
     * REAL Pusher-protocol HTTP request — RED-R3's missing coverage.
     */
    public function test_outbox_pipeline_dispatches_under_active_harness(): void
    {
        $this->assertHarnessActive();

        // Hard-fail (NOT skip) if the harness flag is set but the actual
        // broadcasting/queue drivers were silently downgraded by phpunit.xml.
        // Skipping here would re-enable RED-R3's silent failure mode.
        $broadcastDriver = (string) config('broadcasting.default');
        $queueDriver = (string) config('queue.default');

        $this->assertNotSame(
            'log',
            $broadcastDriver,
            'BROADCAST_DRIVER=log under the harness defeats the purpose: the log '
            . 'driver always succeeds, so any Pusher/soketi regression passes silently. '
            . 'Export BROADCAST_DRIVER=pusher BEFORE running php artisan test.'
        );
        $this->assertNotSame(
            'sync',
            $queueDriver,
            'QUEUE_CONNECTION=sync under the harness short-circuits queue:work --once: '
            . 'the job runs inline at dispatch() time and the worker exits with nothing '
            . 'to do. Export QUEUE_CONNECTION=database (or redis) to test the actual '
            . 'queue path.'
        );

        // Build a freshly-shaped event row directly. We bypass the
        // event/listener bus to keep the test focused on the
        // Job → Broadcaster contract that RED-R3 cares about.
        $event = DomainEvent::query()->create([
            'event_type'     => EventType::MENU_ITEM_AVAILABILITY_CHANGED,
            'aggregate_type' => 'item',
            'aggregate_id'   => 999001,
            'branch_id'      => 1,
            'payload'        => [
                'item_id'      => 999001,
                'status'       => 0,
                'price'        => 10.0,
                'type'         => 'branch_availability',
                'is_available' => false,
                'branch_id'    => 1,
                'reason'       => 'rupture-stock-test',
            ],
            'channel'        => json_encode(['private-branch.1']),
            'broadcast_as'   => 'ItemAvailabilityChanged',
            'correlation_id' => 'sentinel-' . uniqid('', true),
            'occurred_at'    => now(),
            'attempts'       => 0,
            'last_error'     => null,
            'dispatched_at'  => null,
        ]);

        DispatchDomainEventsJob::dispatch($event->id);

        // Run the queue worker exactly once. With QUEUE_CONNECTION=database
        // (the harness contract) the job is on disk; --once pulls it,
        // executes handle(), and exits.
        $exitCode = Artisan::call('queue:work', [
            '--once'   => true,
            '--queue'  => 'high',
            '--stop-when-empty' => true,
            '--tries'  => 1,
        ]);
        $this->assertSame(0, $exitCode, 'queue:work --once exited non-zero. Output: ' . Artisan::output());

        $event->refresh();

        $this->assertNotNull(
            $event->dispatched_at,
            'dispatched_at is still NULL after queue:work --once. This is the EXACT '
            . 'silent-failure mode RED-R3 reproduced when soketi/websockets is not UP. '
            . 'Output: ' . Artisan::output() . ' / last_error: ' . ($event->last_error ?? 'null')
        );
        $this->assertSame(
            1,
            (int) $event->attempts,
            'attempts must equal 1 on the success path (set during phase 1 claim, '
            . 'not incremented on success). Got: ' . $event->attempts
        );
        $this->assertNull(
            $event->last_error,
            'last_error should be cleared on success (phase 3a). Got: ' . $event->last_error
        );
    }

    /**
     * Test 2 — Configuration self-check.
     *
     * Independent of the broadcaster: when the harness is active, the
     * effective config MUST not be the PHPUnit defaults that mask the
     * Pusher pipeline (log + sync). This is a defensive check so a
     * future PR that strips the workflow's env exports — but leaves
     * CI_WEBSOCKETS_HARNESS=1 in place — fails LOUD here instead of
     * silently passing.
     *
     * Also asserts config('broadcasting.default') is non-null (the
     * mission spec mentions "Stomp (peu importe)" but the underlying
     * invariant is: a non-null configured driver).
     */
    public function test_harness_environment_is_not_the_phpunit_defaults(): void
    {
        $this->assertHarnessActive();

        $broadcastDefault = config('broadcasting.default');
        $queueDefault = config('queue.default');

        $this->assertNotNull(
            $broadcastDefault,
            'config(broadcasting.default) is null — Laravel BroadcastManager will '
            . 'crash before reaching the Pusher path. Check config/broadcasting.php '
            . 'and the BROADCAST_DRIVER env override.'
        );
        $this->assertNotNull(
            $queueDefault,
            'config(queue.default) is null — Laravel queue worker has no driver to '
            . 'pull from. Check config/queue.php and the QUEUE_CONNECTION env override.'
        );

        // The two drivers that MUST NOT be active under the harness.
        $forbiddenBroadcastDrivers = ['log', 'null'];
        $this->assertNotContains(
            $broadcastDefault,
            $forbiddenBroadcastDrivers,
            sprintf(
                'broadcasting.default = "%s" defeats the harness. The log driver '
                . 'always returns success without hitting Pusher; the null driver '
                . 'is a no-op. RED-R3 is ONLY caught when the real Pusher protocol '
                . 'is exercised.',
                $broadcastDefault
            )
        );
        $forbiddenQueueDrivers = ['sync', 'null'];
        $this->assertNotContains(
            $queueDefault,
            $forbiddenQueueDrivers,
            sprintf(
                'queue.default = "%s" defeats the harness. The sync driver runs '
                . 'jobs inline at dispatch() time, so queue:work --once finds an '
                . 'empty queue and the test passes for the wrong reason.',
                $queueDefault
            )
        );

        // The Pusher connection block must be wired. Empty creds are tolerated
        // ONLY for the soketi config (app-id/app-key/app-secret in soketi.json).
        $pusherConfig = config('broadcasting.connections.pusher');
        $this->assertIsArray(
            $pusherConfig,
            'config(broadcasting.connections.pusher) is missing — broadcasting.php drift?'
        );
    }

    /**
     * Test 3 — Phase 3b release-claim invariant.
     *
     * If the broadcaster throws (real-world: soketi restart, network
     * blip, contract drift), DispatchDomainEventsJob MUST:
     *   - reset dispatched_at to NULL (release the claim)
     *   - set last_error to the exception message
     *   - rethrow the exception so the job goes through the queue
     *     retry/backoff curve
     *
     * RED-R3 mistakenly believed `dispatched_at != null` while CI was green
     * — but the actual reproduction showed phase 3b correctly nulling it.
     * This test pins that contract so a future "optimization" that skips
     * the phase 3b reset cannot land silently.
     *
     * NOTE: This test does NOT require the harness flag because it mocks
     * the broadcaster entirely. It runs under any environment, including
     * the default phpunit.xml log+sync env. It pairs with test 1: test 1
     * proves the success path; test 3 proves the failure path.
     */
    public function test_phase_3b_release_claim_on_broadcaster_failure(): void
    {
        $event = DomainEvent::query()->create([
            'event_type'     => EventType::MENU_ITEM_AVAILABILITY_CHANGED,
            'aggregate_type' => 'item',
            'aggregate_id'   => 999002,
            'branch_id'      => 1,
            'payload'        => [
                'item_id'      => 999002,
                'status'       => 1,
                'price'        => 5.0,
                'type'         => 'branch_availability',
                'is_available' => true,
                'branch_id'    => 1,
                'reason'       => null,
            ],
            'channel'        => json_encode(['private-branch.1']),
            'broadcast_as'   => 'ItemAvailabilityChanged',
            'correlation_id' => 'sentinel-3b-' . uniqid('', true),
            'occurred_at'    => now(),
            'attempts'       => 0,
            'last_error'     => null,
            'dispatched_at'  => null,
        ]);

        // Inject a broadcaster that always throws — simulates soketi/Pusher down.
        $failing = Mockery::mock(Broadcaster::class);
        $failing->shouldReceive('broadcast')
            ->once()
            ->andThrow(new RuntimeException('simulated soketi outage'));
        $manager = Mockery::mock(BroadcastManager::class);
        $manager->shouldReceive('connection')->andReturn($failing);
        $this->app->instance(BroadcastManager::class, $manager);
        $this->app->instance('broadcast.manager', $manager);

        $caught = null;
        try {
            (new DispatchDomainEventsJob($event->id))->handle();
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull(
            $caught,
            'DispatchDomainEventsJob MUST rethrow the broadcaster exception so the '
            . 'queue retry/backoff curve can re-attempt. Swallowing it would silently '
            . 'consume the job and lose the event forever.'
        );
        $this->assertSame('simulated soketi outage', $caught->getMessage());

        $event->refresh();

        // Phase 3b contract — load-bearing for the queue retry to work.
        $this->assertNull(
            $event->dispatched_at,
            'Phase 3b MUST reset dispatched_at to NULL on broadcaster failure. '
            . 'If dispatched_at stays set, scopePending() filters the row out and '
            . 'the rescue command can never re-queue it. RED-R3 silent-failure '
            . 'mode would return.'
        );
        $this->assertSame(
            1,
            (int) $event->attempts,
            'attempts must remain 1 (incremented during the phase 1 claim). '
            . 'Phase 3b does NOT touch the counter.'
        );
        $this->assertSame(
            'simulated soketi outage',
            $event->last_error,
            'last_error must be populated with the exception message so the '
            . 'rescue/retry-failed CLI commands can categorise the failure and '
            . 'pager-grade alerts can grep it. PayloadMismatchException would '
            . 'instead carry the "contract_violation:" prefix — see the existing '
            . 'OutboxProductionLikeSimulationTest for that path.'
        );
    }

    /**
     * Test 4 — PayloadMismatchException is NOT silently rescued.
     *
     * Bonus invariant: if the envelope is malformed (missing item_id/status),
     * EventContract::assertEnvelopeValid throws PayloadMismatchException and
     * phase 3b sets last_error = "contract_violation: ...". Pager-grade
     * alerts grep on this prefix. If a future refactor strips the prefix or
     * swallows the exception, this test breaks loud.
     */
    public function test_contract_violation_preserves_pager_grade_prefix(): void
    {
        $event = DomainEvent::query()->create([
            'event_type'     => EventType::MENU_ITEM_AVAILABILITY_CHANGED,
            'aggregate_type' => 'item',
            'aggregate_id'   => 999003,
            'branch_id'      => 1,
            // Intentionally malformed: missing the contract-required keys.
            'payload'        => ['malformed' => true],
            'channel'        => json_encode(['private-branch.1']),
            'broadcast_as'   => 'ItemAvailabilityChanged',
            'correlation_id' => 'sentinel-contract-' . uniqid('', true),
            'occurred_at'    => now(),
            'attempts'       => 0,
            'last_error'     => null,
            'dispatched_at'  => null,
        ]);

        // Broadcaster MUST NOT be reached on contract violation — assertEnvelopeValid
        // runs before broadcast(). We assert that explicitly.
        $broadcaster = Mockery::mock(Broadcaster::class);
        $broadcaster->shouldNotReceive('broadcast');
        $manager = Mockery::mock(BroadcastManager::class);
        $manager->shouldReceive('connection')->andReturn($broadcaster);
        $this->app->instance(BroadcastManager::class, $manager);
        $this->app->instance('broadcast.manager', $manager);

        $caught = null;
        try {
            (new DispatchDomainEventsJob($event->id))->handle();
        } catch (PayloadMismatchException $e) {
            $caught = $e;
        }

        $this->assertNotNull(
            $caught,
            'PayloadMismatchException MUST be thrown so failed_jobs records the '
            . 'terminal failure with the right error_class for monitoring.'
        );

        $event->refresh();
        $this->assertNull($event->dispatched_at, 'Phase 3b reset is mandatory.');
        $this->assertStringStartsWith(
            'contract_violation:',
            (string) $event->last_error,
            'The "contract_violation:" prefix is grepped by pager-grade alerts '
            . '(see DispatchDomainEventsJob::failed() docblock). Stripping it '
            . 'would silently lose terminal contract-violation visibility.'
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
