<?php

namespace Tests\Feature\Outbox;

use App\Console\Commands\MonitorOutboxStaleness;
use App\Enums\EventType;
use App\Models\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * @FK-ID         FK-CV1-AUDIT-F015-QUEUE-CONFIG-2026-05-08
 * @source        F-015 — Production blocker queue config (audit ultra-review 2026-05-07)
 * @plan          plans/PLAN_AUDIT_F015_QUEUE_CONFIG_PROD_2026-05-07.md
 *
 * Production blocker safety net for the outbox pattern + queue worker contract.
 *
 * Why this test exists
 * --------------------
 * Since the outbox refactor (`app/Listeners/PersistOrderCreatedToOutbox.php`,
 * `app/Jobs/DispatchDomainEventsJob.php`), broadcasting domain events REQUIRES
 * a queue worker processing the `high` lane. If an operator deploys with
 * `QUEUE_CONNECTION=redis` but forgets to start `php artisan queue:work
 * --queue=high`, every `domain_events` row stays `dispatched_at = NULL` and
 * KDS / OSS / POS receive nothing in realtime — only the polling 30s safety
 * net masks the silent failure cosmetically.
 *
 * This test pins three invariants:
 *   1. The `/api/health/ready` endpoint flips to 503 when more than 10 stale
 *      `domain_events` rows accumulate (queue worker likely down or lagging).
 *   2. The `/api/health/ready` endpoint flips to 503 in production when a
 *      misconfigured broadcast (`null`/`log`) or queue (`sync`) driver is
 *      detected — i.e. the trap from F-015 is closed at runtime.
 *   3. The `foodking:outbox:monitor` console command exits non-zero and logs
 *      an error when the staleness threshold is exceeded.
 *
 * Note about test environment
 * ---------------------------
 * `phpunit.xml` sets `APP_ENV=testing` so `app()->environment('production')`
 * is false by default. Tests covering the production-only gate explicitly
 * coerce the environment via `app()->detectEnvironment(fn () => 'production')`
 * to exercise the gate. The staleness count gate (>10) has no environment
 * guard and works in any env.
 */
class OutboxDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * AC3 (plan F-015) — `/api/health/ready` returns 503 when more than 10
     * `domain_events` rows are stale (older than 30s, dispatched_at null).
     * Worker likely down — operators must NOT see a ready=200.
     */
    public function test_ready_endpoint_flips_to_503_when_too_many_stale_outbox_events(): void
    {
        // Arrange — insert 11 stale events created > 30s ago, all undispatched.
        $this->seedStaleDomainEvents(11);

        // Act
        $response = $this->getJson('/api/health/ready');

        // Assert
        $response->assertStatus(503);
        $response->assertJsonPath('status', 'degraded');
        $response->assertJsonPath('subsystems.queue_worker.status', 'error');
        $this->assertSame(
            11,
            $response->json('subsystems.queue_worker.stale_count'),
            'Stale count exposed in subsystems must reflect the seeded rows so operators see the magnitude.'
        );
    }

    /**
     * AC3 inverse — staleness BELOW threshold keeps ready=200.
     * Guards against a regression where the staleness probe is too aggressive.
     */
    public function test_ready_endpoint_stays_200_when_stale_count_below_threshold(): void
    {
        // Only 3 stale events — below the 10-event alert threshold.
        $this->seedStaleDomainEvents(3);

        $response = $this->getJson('/api/health/ready');

        $response->assertStatus(200);
        $response->assertJsonPath('subsystems.queue_worker.status', 'ok');
    }

    /**
     * AC4 (plan F-015) — In production, `QUEUE_CONNECTION=sync` is incompatible
     * with the outbox pattern. The probe must fail loud at /ready.
     */
    public function test_ready_endpoint_flips_to_503_in_production_when_queue_is_sync(): void
    {
        $this->forceProductionEnv();
        config(['queue.default' => 'sync']);
        config(['broadcasting.default' => 'pusher']); // valid broadcast

        $response = $this->getJson('/api/health/ready');

        $response->assertStatus(503);
        $response->assertJsonPath('subsystems.broadcast_config.status', 'error');
        $this->assertStringContainsString(
            'sync',
            (string) $response->json('subsystems.broadcast_config.message'),
            'Operator-facing message must name the offending driver so the fix is unambiguous.'
        );
    }

    /**
     * AC4 sibling — In production with `BROADCAST_DRIVER=null` or `log`,
     * realtime broadcasts are silently disabled. /ready must surface this.
     */
    public function test_ready_endpoint_flips_to_503_in_production_when_broadcast_driver_disabled(): void
    {
        $this->forceProductionEnv();
        config(['queue.default' => 'redis']); // valid queue
        config(['broadcasting.default' => 'log']); // disabled broadcast

        $response = $this->getJson('/api/health/ready');

        $response->assertStatus(503);
        $response->assertJsonPath('subsystems.broadcast_config.status', 'error');
        $this->assertStringContainsString(
            'log',
            (string) $response->json('subsystems.broadcast_config.message'),
        );
    }

    /**
     * AC8-style guard — Outside production, `sync` queue is acceptable
     * (dev / CI default). Probe must NOT 503.
     * Defends against a regression that would break local dev + CI.
     */
    public function test_ready_endpoint_does_not_flip_in_non_production_with_sync_queue(): void
    {
        // APP_ENV=testing by default in phpunit.xml
        config(['queue.default' => 'sync']);
        config(['broadcasting.default' => 'log']);

        $response = $this->getJson('/api/health/ready');

        $response->assertStatus(200);
        $response->assertJsonPath('subsystems.broadcast_config.status', 'ok');
    }

    /**
     * AC5 (plan F-015) — `php artisan foodking:outbox:monitor` returns FAILURE
     * exit code AND emits a Log::error when staleness exceeds the threshold.
     * Cron schedule consumes the failure so alerting backends fire.
     */
    public function test_outbox_monitor_command_fails_and_logs_error_when_threshold_exceeded(): void
    {
        $this->seedStaleDomainEvents(11);

        Log::spy();

        $exitCode = Artisan::call('foodking:outbox:monitor', ['--threshold' => 10]);

        $this->assertSame(
            MonitorOutboxStaleness::FAILURE,
            $exitCode,
            'Monitor command must exit non-zero so the scheduler / supervisor surface the alert.'
        );

        Log::shouldHaveReceived('error')
            ->withArgs(function ($message, $context = null) {
                return is_string($message)
                    && str_contains($message, '[OUTBOX STALE]');
            })
            ->atLeast()->once();
    }

    /**
     * AC5 inverse — when staleness is BELOW the threshold, the command must
     * exit SUCCESS without raising error logs (avoid pager fatigue).
     */
    public function test_outbox_monitor_command_succeeds_silently_when_below_threshold(): void
    {
        $this->seedStaleDomainEvents(3);

        Log::spy();

        $exitCode = Artisan::call('foodking:outbox:monitor', ['--threshold' => 10]);

        $this->assertSame(MonitorOutboxStaleness::SUCCESS, $exitCode);

        Log::shouldNotHaveReceived('error');
    }

    /**
     * Seed N stale `domain_events` rows: created > 30s ago, dispatched_at null.
     *
     * Direct DB::insert (not Eloquent::create) so the auto-managed `created_at`
     * timestamp on DomainEvent does not overwrite our backdated value. The
     * staleness probe in HealthController + MonitorOutboxStaleness keys on
     * `created_at < now()->subSeconds(30)`, so the row MUST land in the past.
     */
    private function seedStaleDomainEvents(int $count): void
    {
        $past = now()->subMinute();
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'event_type' => EventType::ORDER_CREATED,
                'aggregate_type' => 'Order',
                'aggregate_id' => 1000 + $i,
                'branch_id' => 1,
                'payload' => json_encode(['order_id' => 1000 + $i, '_origin' => 'test']),
                'channel' => json_encode(['private-branch.1']),
                'broadcast_as' => 'OrderCreated',
                'correlation_id' => 'corr-stale-' . $i,
                'occurred_at' => $past,
                'dispatched_at' => null,
                'attempts' => 0,
                'last_error' => null,
                'created_at' => $past,
                'updated_at' => $past,
            ];
        }

        DomainEvent::query()->getQuery()->insert($rows);
    }

    /**
     * Coerce the runtime environment to `production` so `app()->environment('production')`
     * gates inside HealthController fire. Mirrors the pattern used in
     * `tests/Feature/Fiscal/...` for production-only NF525 guards.
     */
    private function forceProductionEnv(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        // Sanity assertion — protects future Laravel upgrades that change
        // detectEnvironment semantics from silently weakening the gate.
        $this->assertTrue(
            $this->app->environment('production'),
            'Test setup must successfully coerce APP_ENV=production for the gate to be exercised.'
        );
    }
}
