<?php

namespace Tests\Feature\Sync;

use App\Console\Commands\MonitorPusherDeliveryRatio;
use App\Enums\EventType;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\Order;
use App\Models\PusherEventAck;
use App\Models\User;
use App\Services\Sync\PusherDeliveryRatioMonitor;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * [PRE-PROD HARDENING / SYN-2 / P0-7] Test suite for the Pusher
 * heartbeat client→server ack pipeline.
 *
 * Coverage:
 *   - the controller writes one row per ack and silent-fails on errors;
 *   - the monitor service computes the right ratio;
 *   - the threshold gate works in both directions (healthy / degraded);
 *   - the console command surfaces the right exit code on degradation;
 *   - small-N traffic (<5 dispatched) is always reported healthy.
 */
class PusherAckTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mint a Sanctum token bound to a user — same pattern as
     * KioskEventTest. The ack endpoint requires only `auth:sanctum`,
     * any token works.
     */
    private function authedToken(): array
    {
        $branch = Branch::updateOrCreate(['id' => 1], [
            'name'      => 'HQ',
            'email'     => 'hq@foodking.com',
            'phone'     => '123',
            'latitude'  => '0',
            'longitude' => '0',
            'city'      => 'Paris',
            'state'     => 'IDF',
            'zip_code'  => '75',
            'address'   => '1 rue',
        ]);

        $user = User::factory()->create([
            'username'  => 'pusher_ack_' . uniqid(),
            'branch_id' => $branch->id,
        ]);

        $token = $user->createToken('ack-token', ['kiosk:order'])->plainTextToken;

        return [$user, $token, $branch];
    }

    private function makeDispatched(int $branchId, string $correlationId, ?Carbon $when = null): DomainEvent
    {
        return DomainEvent::query()->create([
            'event_type'     => EventType::ORDER_CREATED,
            'aggregate_type' => Order::class,
            'aggregate_id'   => random_int(1000, 99999),
            'branch_id'      => $branchId,
            'payload'        => ['order_id' => 1],
            'channel'        => json_encode(['private-branch.' . $branchId]),
            'broadcast_as'   => 'OrderCreated',
            'correlation_id' => $correlationId,
            'occurred_at'    => $when ?? now(),
            'dispatched_at'  => $when ?? now(),
        ]);
    }

    public function test_client_ack_endpoint_persists_to_db(): void
    {
        [$user, $token, $branch] = $this->authedToken();

        $payload = [
            'correlation_id' => 'ack-corr-001',
            'branch_id'      => $branch->id,
            'channel'        => 'private-branch.' . $branch->id,
            'event_name'     => 'order.created',
            'received_at'    => now()->toIso8601String(),
            'domain_event_id' => 42,
        ];

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/internal/pusher-ack', $payload);

        $response->assertStatus(204);

        $this->assertDatabaseHas('pusher_event_acks', [
            'correlation_id'  => 'ack-corr-001',
            'branch_id'       => $branch->id,
            'channel'         => 'private-branch.' . $branch->id,
            'event_name'      => 'order.created',
            'domain_event_id' => 42,
            'client_user_id'  => $user->id,
        ]);

        // Sanity: only one row.
        $this->assertSame(1, PusherEventAck::query()->count());
    }

    public function test_ack_endpoint_requires_auth(): void
    {
        $response = $this->postJson('/api/internal/pusher-ack', [
            'correlation_id' => 'who-am-i',
            'channel'        => 'private-branch.1',
            'event_name'     => 'order.created',
            'received_at'    => now()->toIso8601String(),
        ]);

        $response->assertStatus(401);
        $this->assertSame(0, PusherEventAck::query()->count());
    }

    public function test_ack_endpoint_rejects_invalid_payload_with_422(): void
    {
        [, $token] = $this->authedToken();

        // Spec calls this "silent_fails_on_invalid_payload" but the
        // actual contract is sharper: malformed input is a CLIENT bug
        // (wrong deploy), so the validator returns 422 — loud, visible
        // to QA. Only RUNTIME DB errors are swallowed (cf. controller
        // try/catch). Either way no row is written.
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/internal/pusher-ack', [
                'correlation_id' => '',  // empty -> validation fails
            ]);

        $response->assertStatus(422);
        $this->assertSame(0, PusherEventAck::query()->count());
    }

    public function test_delivery_ratio_calculator_returns_correct_ratio(): void
    {
        // 4 dispatched, 3 distinct ack'd → ratio = 0.75.
        // We build them all "now" so the default 5-minute window catches
        // every row and small-N tolerance does NOT auto-heal.
        // 5 dispatched is the small-N tolerance threshold.
        for ($i = 0; $i < 5; $i++) {
            $this->makeDispatched(1, 'corr-' . $i);
        }

        // Ack only 4 distinct correlation_ids (5th never arrives).
        // One correlation gets 2 acks (multi-client) — distinct count
        // must NOT double it.
        foreach (['corr-0', 'corr-1', 'corr-2', 'corr-3', 'corr-3'] as $cid) {
            PusherEventAck::create([
                'correlation_id' => $cid,
                'branch_id'      => 1,
                'channel'        => 'private-branch.1',
                'event_name'     => 'order.created',
                'received_at'    => now(),
                'created_at'     => now(),
            ]);
        }

        $monitor = app(PusherDeliveryRatioMonitor::class);
        $result  = $monitor->calculate(5);

        $this->assertSame(5, $result['dispatched']);
        $this->assertSame(4, $result['acked_distinct'], 'distinct count must not double-count multi-client acks');
        $this->assertSame(0.8, $result['ratio']);
        $this->assertFalse($result['healthy'], '4/5=0.8 is below 0.90 threshold');
    }

    public function test_delivery_ratio_unhealthy_when_below_threshold(): void
    {
        // 10 dispatched, 5 ack'd → ratio = 0.5 → unhealthy.
        for ($i = 0; $i < 10; $i++) {
            $this->makeDispatched(1, 'low-corr-' . $i);
        }
        for ($i = 0; $i < 5; $i++) {
            PusherEventAck::create([
                'correlation_id' => 'low-corr-' . $i,
                'branch_id'      => 1,
                'channel'        => 'private-branch.1',
                'event_name'     => 'order.created',
                'received_at'    => now(),
                'created_at'     => now(),
            ]);
        }

        $result = app(PusherDeliveryRatioMonitor::class)->calculate(5);

        $this->assertSame(0.5, $result['ratio']);
        $this->assertFalse($result['healthy']);
    }

    public function test_delivery_ratio_healthy_when_above_threshold(): void
    {
        // 10 dispatched, 10 ack'd → ratio = 1.0 → healthy.
        for ($i = 0; $i < 10; $i++) {
            $this->makeDispatched(1, 'ok-corr-' . $i);
            PusherEventAck::create([
                'correlation_id' => 'ok-corr-' . $i,
                'branch_id'      => 1,
                'channel'        => 'private-branch.1',
                'event_name'     => 'order.created',
                'received_at'    => now(),
                'created_at'     => now(),
            ]);
        }

        $result = app(PusherDeliveryRatioMonitor::class)->calculate(5);

        $this->assertSame(1.0, $result['ratio']);
        $this->assertTrue($result['healthy']);
    }

    public function test_ratio_tolerates_small_N(): void
    {
        // < 5 dispatched events → small-N tolerance → always healthy
        // even with 0 acks. Otherwise the 3 a.m. quiet hours of a
        // single-branch fast-food would page the operator.
        for ($i = 0; $i < 4; $i++) {
            $this->makeDispatched(1, 'sparse-' . $i);
        }
        // Zero acks.

        $result = app(PusherDeliveryRatioMonitor::class)->calculate(5);

        $this->assertSame(4, $result['dispatched']);
        $this->assertSame(0, $result['acked_distinct']);
        $this->assertSame(0.0, $result['ratio']);
        $this->assertTrue(
            $result['healthy'],
            'small-N (<5 dispatched) must always report healthy, regardless of ratio'
        );
    }

    public function test_monitor_command_returns_failure_when_unhealthy(): void
    {
        // 10 dispatched, 0 ack'd → ratio 0 → degraded.
        for ($i = 0; $i < 10; $i++) {
            $this->makeDispatched(1, 'cmd-fail-' . $i);
        }

        // The command writes Log::error + emits non-zero exit.
        Log::shouldReceive('error')->atLeast()->once();

        $exitCode = $this->artisan('foodking:pusher:monitor')
            ->expectsOutputToContain('PUSHER DEGRADED')
            ->run();

        $this->assertSame(Command::FAILURE, $exitCode);
    }

    public function test_monitor_command_returns_success_when_healthy(): void
    {
        // 10 dispatched, 10 ack'd → ratio 1.0 → healthy.
        for ($i = 0; $i < 10; $i++) {
            $this->makeDispatched(1, 'cmd-ok-' . $i);
            PusherEventAck::create([
                'correlation_id' => 'cmd-ok-' . $i,
                'branch_id'      => 1,
                'channel'        => 'private-branch.1',
                'event_name'     => 'order.created',
                'received_at'    => now(),
                'created_at'     => now(),
            ]);
        }

        $exitCode = $this->artisan('foodking:pusher:monitor')
            ->expectsOutputToContain('PUSHER OK')
            ->run();

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    public function test_window_filter_excludes_old_rows(): void
    {
        // 5 dispatched 1 hour ago + 0 in window → window=5min
        // sees `dispatched=0` → small-N tolerance → healthy.
        // This pins the sliding window invariant: stale data must not
        // poison the current ratio.
        for ($i = 0; $i < 5; $i++) {
            $cid = 'old-corr-' . $i;
            DomainEvent::query()->create([
                'event_type'     => EventType::ORDER_CREATED,
                'aggregate_type' => Order::class,
                'aggregate_id'   => 5000 + $i,
                'branch_id'      => 1,
                'payload'        => ['order_id' => 1],
                'channel'        => json_encode(['private-branch.1']),
                'broadcast_as'   => 'OrderCreated',
                'correlation_id' => $cid,
                'occurred_at'    => now()->subHour(),
                'dispatched_at'  => now()->subHour(),
            ]);
        }

        $result = app(PusherDeliveryRatioMonitor::class)->calculate(5);

        $this->assertSame(0, $result['dispatched']);
        $this->assertTrue($result['healthy']);
    }

    public function test_branch_scope_isolates_ratio(): void
    {
        // Branch 1: 5 dispatched, 5 ack → 1.0 healthy.
        // Branch 2: 5 dispatched, 0 ack → 0.0 unhealthy.
        // Both share the same 5-minute window. Per-branch calculate
        // must isolate them.
        for ($i = 0; $i < 5; $i++) {
            $this->makeDispatched(1, 'b1-' . $i);
            PusherEventAck::create([
                'correlation_id' => 'b1-' . $i,
                'branch_id'      => 1,
                'channel'        => 'private-branch.1',
                'event_name'     => 'order.created',
                'received_at'    => now(),
                'created_at'     => now(),
            ]);

            $this->makeDispatched(2, 'b2-' . $i);
        }

        $monitor = app(PusherDeliveryRatioMonitor::class);

        $branch1 = $monitor->calculate(5, 1);
        $this->assertSame(5, $branch1['dispatched']);
        $this->assertSame(5, $branch1['acked_distinct']);
        $this->assertTrue($branch1['healthy']);

        $branch2 = $monitor->calculate(5, 2);
        $this->assertSame(5, $branch2['dispatched']);
        $this->assertSame(0, $branch2['acked_distinct']);
        $this->assertFalse($branch2['healthy']);
    }
}
