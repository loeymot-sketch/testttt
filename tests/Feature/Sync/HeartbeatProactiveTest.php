<?php

namespace Tests\Feature\Sync;

use App\Enums\EventType;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use App\Models\Order;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * [PARALLEL-TRACK-5 / 5.3] Complementary heartbeat invariants.
 *
 * `HeartbeatCacheTest` (Track 3) already pins the basic contract
 * "DispatchDomainEventsJob writes ws:heartbeat after a successful
 * dispatch". This suite adds the harder invariants that protect
 * HealthController.ready() from misbehaving:
 *
 *   - the cache TTL is exactly 60 seconds (not "forever");
 *   - the value is ISO 8601 (parseable by HealthController);
 *   - multiple successive writes update the timestamp (no race);
 *   - the array cache driver used in tests does NOT honour TTL via
 *     Carbon::setTestNow — so we assert the TTL parameter explicitly
 *     rather than letting time advance, which silently passes.
 *
 * NOTE: HealthController is "agent territory" per Track-5 charter —
 * it is read-only here. We never modify production code; if a test
 * fails the remediation is in `app/`, not in this file.
 */
class HeartbeatProactiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('ws:heartbeat');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    /**
     * Forge a real DomainEvent + a stub broadcast manager so that
     * DispatchDomainEventsJob::handle() reaches the heartbeat write.
     *
     * Returns the saved DomainEvent so individual tests can run the job
     * (or a sequence of jobs) without re-duplicating boilerplate.
     */
    private function makeJobReadyEvent(int $aggregateId): DomainEvent
    {
        $event = DomainEvent::query()->create([
            'event_type'     => EventType::ORDER_CREATED,
            'aggregate_type' => Order::class,
            'aggregate_id'   => $aggregateId,
            'branch_id'      => 1,
            'payload'        => ['order_id' => $aggregateId],
            'channel'        => json_encode(['private-branch.1']),
            'broadcast_as'   => 'OrderCreated',
            'correlation_id' => 'heartbeat-proactive-' . $aggregateId,
            'occurred_at'    => now(),
        ]);

        $pusher = Mockery::mock();
        $pusher->shouldReceive('trigger')->andReturnNull();

        $connection = Mockery::mock();
        $connection->shouldReceive('getPusher')->andReturn($pusher);

        $manager = Mockery::mock(BroadcastManager::class);
        $manager->shouldReceive('connection')->with('pusher')->andReturn($connection);

        $this->app->instance(BroadcastManager::class, $manager);
        $this->app->instance('broadcast.manager', $manager);

        return $event;
    }

    /**
     * The heartbeat must be a parseable ISO 8601 string. The
     * downstream HealthController converts it to a Carbon instance to
     * compute "stale > 60s = degraded".
     */
    public function test_heartbeat_value_is_parseable_iso8601(): void
    {
        $event = $this->makeJobReadyEvent(1001);

        (new DispatchDomainEventsJob($event->id))->handle();

        $value = Cache::get('ws:heartbeat');
        $this->assertNotNull($value, 'ws:heartbeat must be set after a successful dispatch');
        $this->assertIsString($value, 'ws:heartbeat must be a string');

        // Parse must not throw.
        $parsed = Carbon::parse($value);
        $this->assertInstanceOf(Carbon::class, $parsed);
        $this->assertSame($value, $parsed->toIso8601String(), 'ws:heartbeat must round-trip through Carbon');
    }

    /**
     * The TTL must be exactly 60s. We assert this by spying on Cache::put()
     * — the third argument is the TTL — because the `array` cache driver
     * configured in phpunit.xml does NOT honour Carbon::setTestNow() (it
     * uses microtime() internally), so a "advance time + assert null"
     * test would silently fail to catch a regression.
     */
    public function test_heartbeat_cache_put_uses_60_second_ttl(): void
    {
        $event = $this->makeJobReadyEvent(2001);

        $captured = [];
        Cache::shouldReceive('put')
            ->withArgs(function ($key, $value, $ttl) use (&$captured) {
                if ($key === 'ws:heartbeat') {
                    $captured[] = ['key' => $key, 'value' => $value, 'ttl' => $ttl];
                }
                return true;
            })
            ->andReturn(true);

        // The job also calls forceFill->save on the DomainEvent model
        // which doesn't touch Cache, so the spy is safe.
        (new DispatchDomainEventsJob($event->id))->handle();

        $heartbeatPuts = array_filter($captured, fn ($c) => $c['key'] === 'ws:heartbeat');
        $this->assertNotEmpty($heartbeatPuts, 'DispatchDomainEventsJob must call Cache::put(ws:heartbeat, ...)');

        $ttl = reset($heartbeatPuts)['ttl'];
        $this->assertSame(60, $ttl, 'ws:heartbeat TTL must be exactly 60 seconds (HealthController.ready() relies on this)');
    }

    /**
     * Two sequential dispatches must both refresh the heartbeat — the
     * second call writes a NEW timestamp, not a stale-since-the-first
     * one. This is what makes the heartbeat "live" rather than "stuck".
     */
    public function test_two_dispatches_refresh_heartbeat_timestamp(): void
    {
        $eventA = $this->makeJobReadyEvent(3001);

        Carbon::setTestNow(Carbon::parse('2026-05-08 10:00:00'));
        (new DispatchDomainEventsJob($eventA->id))->handle();
        $first = Cache::get('ws:heartbeat');

        // Advance the simulated clock and dispatch a second event.
        $eventB = $this->makeJobReadyEvent(3002);
        Carbon::setTestNow(Carbon::parse('2026-05-08 10:00:30'));
        (new DispatchDomainEventsJob($eventB->id))->handle();
        $second = Cache::get('ws:heartbeat');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second, 'Each successful dispatch must refresh the heartbeat timestamp');

        // Sanity: the second timestamp is strictly later than the first.
        $this->assertTrue(
            Carbon::parse($second)->greaterThan(Carbon::parse($first)),
            'Second heartbeat must be strictly later than the first'
        );
    }

    /**
     * The heartbeat key name is a public contract between the writer
     * (DispatchDomainEventsJob) and the reader (HealthController).
     * Pin it explicitly so a typo on either side fails CI loudly.
     */
    public function test_heartbeat_cache_key_is_exactly_ws_heartbeat(): void
    {
        $event = $this->makeJobReadyEvent(4001);

        // Capture every key that DispatchDomainEventsJob pushes to Cache.
        $putKeys = [];
        Cache::shouldReceive('put')
            ->withArgs(function ($key, ...$rest) use (&$putKeys) {
                $putKeys[] = $key;
                return true;
            })
            ->andReturn(true);

        (new DispatchDomainEventsJob($event->id))->handle();

        $this->assertContains(
            'ws:heartbeat',
            $putKeys,
            'DispatchDomainEventsJob must write the literal key "ws:heartbeat" — '
            . 'HealthController.ready() reads this exact key. '
            . 'Keys observed: ' . implode(', ', $putKeys)
        );
    }

    /**
     * Read-only assertion on HealthController.ready(): the controller
     * still exists and responds with 200 / 503 (defensive sanity check
     * that no Track-3 / Track-5 collision broke the route). We do NOT
     * assert "ready returns 503 when heartbeat stale" because the
     * current HealthController implementation does not check
     * ws:heartbeat yet — that integration is part of V1
     * SYNC_ROBUSTNESS 3.3 and lives in agent territory.
     */
    public function test_health_ready_endpoint_exists_and_responds(): void
    {
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }
        $this->seedMinimalSettings();

        $response = $this->getJson('/api/health/ready');

        // Either 200 (subsystems OK) or 503 (subsystems degraded). Anything
        // else — 404 / 500 — means the route disappeared or the controller
        // crashed, which would silently kill the whole readiness loop.
        $this->assertContains(
            $response->status(),
            [200, 503],
            'HealthController.ready() must remain available — got ' . $response->status()
        );
    }
}
