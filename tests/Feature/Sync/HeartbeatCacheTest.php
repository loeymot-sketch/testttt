<?php

namespace Tests\Feature\Sync;

use App\Enums\EventType;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use App\Models\Order;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * [V1 SYNC_ROBUSTNESS 3.3] HealthController.ready() will check Cache::get('ws:heartbeat')
 * to prove a worker actually executed the dispatch path. Without something setting
 * the key, the readiness check stays stale forever. This test pins the contract
 * that DispatchDomainEventsJob::handle() writes the key after a successful dispatch.
 */
class HeartbeatCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_job_sets_heartbeat_cache_key(): void
    {
        Cache::forget('ws:heartbeat');
        $this->assertNull(Cache::get('ws:heartbeat'));

        $domainEvent = DomainEvent::query()->create([
            'event_type' => EventType::ORDER_CREATED,
            'aggregate_type' => Order::class,
            'aggregate_id' => 999,
            'branch_id' => 1,
            'payload' => ['order_id' => 999],
            'channel' => json_encode(['private-branch.1']),
            'broadcast_as' => 'OrderCreated',
            'correlation_id' => 'heartbeat-test-uuid',
            'occurred_at' => now(),
        ]);

        $pusher = Mockery::mock();
        $pusher->shouldReceive('trigger')->once();

        $connection = Mockery::mock();
        $connection->shouldReceive('getPusher')->once()->andReturn($pusher);

        $manager = Mockery::mock(BroadcastManager::class);
        $manager->shouldReceive('connection')->once()->with('pusher')->andReturn($connection);

        $this->app->instance(BroadcastManager::class, $manager);
        $this->app->instance('broadcast.manager', $manager);

        (new DispatchDomainEventsJob($domainEvent->id))->handle();

        $this->assertTrue(Cache::has('ws:heartbeat'), 'ws:heartbeat cache key must be set after dispatch');
        $this->assertNotEmpty(Cache::get('ws:heartbeat'), 'ws:heartbeat must be a non-empty timestamp');
    }
}
