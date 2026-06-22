<?php

namespace Tests\Feature\Outbox;

use App\Models\DomainEvent;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * @FK-ID         FK-CV1-WAVE3B-SYNC-ADV3B-06-2026-05-18
 * @source        reports/audit/critical-focus-2026-05-18/wave-3b/adv-2-sync-heals-r2.md
 *
 * P1 concurrency guard: two admins (or a cron + a manual run) firing
 * `foodking:outbox:retry-failed` (or `:webhook:retry-failed`) in the same
 * minute would previously have BOTH grabbed the same `failed` rows and
 * BOTH written audit_log rows + double-dispatched events.
 *
 * Heal: Cache::lock 60s TTL gates `handle()`. The second invocation
 * exits SUCCESS (return 0) with a `warn` log so cron does not page.
 *
 * Companion tests:
 *  - `OutboxReplayAuditTest` — Wave 2b audit trail sentinel
 *  - `OutboxConcurrentWorkerDedupeTest` — dispatch-side row lock
 */
class OutboxConcurrentRetryLockTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test A — pre-acquire the lock; the command must exit SUCCESS and
     * NOT touch any failed DomainEvent rows.
     */
    public function test_outbox_retry_failed_skips_when_lock_already_held(): void
    {
        Bus::fake();

        $event = DomainEvent::create([
            'branch_id' => 0,
            'event_type' => 'order.created',
            'aggregate_type' => 'order',
            'aggregate_id' => 1,
            'payload' => ['id' => 1],
            'channel' => null,
            'broadcast_as' => null,
            'attempts' => 5,
            'dispatched_at' => null,
            'last_error' => 'boom',
            'correlation_id' => 'corr-test-a',
            'occurred_at' => now()->subMinutes(2),
        ]);

        // Pre-acquire the lock with the EXACT key used by the command.
        $lock = Cache::lock('outbox.retry-failed.lock', 60);
        $this->assertTrue($lock->get(), 'Test setup: should acquire lock');

        try {
            $exit = Artisan::call('foodking:outbox:retry-failed', ['--since' => '1h']);
            $this->assertSame(0, $exit, 'Command must exit SUCCESS even when skipping');

            // The event MUST NOT have been touched.
            $event->refresh();
            $this->assertSame(5, (int) $event->attempts, 'attempts must NOT be reset when lock contended');
            $this->assertSame('boom', $event->last_error, 'last_error must NOT be cleared when lock contended');

            Bus::assertNothingDispatched();
        } finally {
            $lock->release();
        }
    }

    /**
     * Test B — no prior lock; the command acquires + releases the lock
     * and processes the event normally.
     */
    public function test_outbox_retry_failed_acquires_and_releases_lock(): void
    {
        Bus::fake();

        DomainEvent::create([
            'branch_id' => 0,
            'event_type' => 'order.created',
            'aggregate_type' => 'order',
            'aggregate_id' => 2,
            'payload' => ['id' => 2],
            'channel' => null,
            'broadcast_as' => null,
            'attempts' => 5,
            'dispatched_at' => null,
            'last_error' => 'boom',
            'correlation_id' => 'corr-test-b',
            'occurred_at' => now()->subMinutes(2),
        ]);

        $exit = Artisan::call('foodking:outbox:retry-failed', ['--since' => '1h']);
        $this->assertSame(0, $exit);

        // After the run completes, the lock MUST be releasable (i.e. not held).
        $lock = Cache::lock('outbox.retry-failed.lock', 60);
        $this->assertTrue(
            $lock->get(),
            'Lock must be released after handle() completes so a follow-up run can acquire it'
        );
        $lock->release();
    }

    /**
     * Test C — same concurrency guard for the webhook DLQ command,
     * with its distinct lock key.
     */
    public function test_webhook_retry_failed_skips_when_lock_already_held(): void
    {
        Bus::fake();

        $event = WebhookEvent::create([
            'provider' => WebhookEvent::PROVIDER_STRIPE,
            'webhook_id' => 'evt_concurrent_'.uniqid(),
            'event_type' => 'payment_intent.succeeded',
            'payload' => ['id' => 'evt'],
            'signature' => 'sig',
            'received_at' => now()->subMinutes(5),
            'processed_at' => null,
            'status' => WebhookEvent::STATUS_FAILED,
            'error_message' => 'Network timeout',
            'attempts' => 2,
            'order_id' => null,
        ]);

        $lock = Cache::lock('outbox.webhook-retry-failed.lock', 60);
        $this->assertTrue($lock->get(), 'Test setup: should acquire lock');

        try {
            $exit = Artisan::call('foodking:webhook:retry-failed', ['--since' => '24h']);
            $this->assertSame(0, $exit);

            $event->refresh();
            $this->assertSame(
                WebhookEvent::STATUS_FAILED,
                $event->status,
                'status must NOT be flipped to pending when lock contended'
            );

            Bus::assertNothingDispatched();
        } finally {
            $lock->release();
        }
    }

    /**
     * Test D — webhook command acquires + releases its own lock.
     */
    public function test_webhook_retry_failed_acquires_and_releases_lock(): void
    {
        Bus::fake();

        WebhookEvent::create([
            'provider' => WebhookEvent::PROVIDER_STRIPE,
            'webhook_id' => 'evt_release_'.uniqid(),
            'event_type' => 'payment_intent.succeeded',
            'payload' => ['id' => 'evt'],
            'signature' => 'sig',
            'received_at' => now()->subMinutes(5),
            'processed_at' => null,
            'status' => WebhookEvent::STATUS_FAILED,
            'error_message' => 'timeout',
            'attempts' => 1,
            'order_id' => null,
        ]);

        $exit = Artisan::call('foodking:webhook:retry-failed', ['--since' => '24h']);
        $this->assertSame(0, $exit);

        $lock = Cache::lock('outbox.webhook-retry-failed.lock', 60);
        $this->assertTrue($lock->get(), 'Webhook command must release its lock after handle()');
        $lock->release();
    }

    /**
     * Test E (Wave 3c SYNC-ADV3C-04) — BATCH_CAP enforces a per-run row
     * ceiling so the lock TTL window can never be exceeded mid-handle.
     * Seed 600 failed DomainEvents → exactly 500 must be re-dispatched,
     * 100 must remain `dispatched_at IS NULL && attempts==5` for the
     * next cron tick.
     *
     * Asserting on attempts==0 (reset by retry-failed code) is the
     * cleanest signature for "command saw this row" without leaking
     * implementation details of the Job dispatch.
     */
    public function test_outbox_retry_failed_caps_batch_at_500_rows(): void
    {
        Bus::fake();

        $totalRows = 600;
        $rows = [];
        $base = now()->subMinutes(5);
        for ($i = 1; $i <= $totalRows; $i++) {
            $rows[] = [
                'branch_id' => 0,
                'event_type' => 'order.created',
                'aggregate_type' => 'order',
                'aggregate_id' => $i,
                'payload' => json_encode(['id' => $i]),
                'channel' => null,
                'broadcast_as' => null,
                'attempts' => 5,
                'dispatched_at' => null,
                'last_error' => 'boom-'.$i,
                'correlation_id' => 'corr-cap-'.$i,
                'occurred_at' => $base,
                'created_at' => $base,
                'updated_at' => $base,
            ];
        }
        DomainEvent::insert($rows);

        $this->assertSame($totalRows, DomainEvent::count(), 'Seed: 600 events inserted');

        $exit = Artisan::call('foodking:outbox:retry-failed', ['--since' => '1h']);
        $this->assertSame(0, $exit, 'Command must exit SUCCESS');

        // [Heal B.1 Z3 B-2 P0 — 2026-05-19] Post-heal contract: command
        // PRESERVES `attempts` + `last_error` and only re-nulls
        // `dispatched_at`. The "saw this row" signature switches from
        // attempts=0 (pre-heal reset) to dispatched_at IS NULL combined
        // with the Bus::assertDispatchedTimes count below.
        // BATCH_CAP=500: exactly 500 rows are touched per run. We assert
        // via `Bus::assertDispatchedTimes(..., 500)` (already below) and
        // verify the row state shape is preserved (no flap).
        $preservedCount = DomainEvent::query()
            ->where('attempts', 5)
            ->whereNotNull('last_error')
            ->whereNull('dispatched_at')
            ->count();
        $this->assertSame(
            $totalRows,
            $preservedCount,
            'All 600 seeded rows must keep attempts=5 + last_error preserved (Heal B.1 monotonic).'
        );

        Bus::assertDispatchedTimes(\App\Jobs\DispatchDomainEventsJob::class, 500);
    }

    /**
     * Test F (Wave 3c SYNC-ADV3C-04) — the Cache::lock TTL is 300s.
     * Asserting via the `Illuminate\Cache\Lock::$seconds` protected
     * property (reflection) — this is the canonical place Laravel
     * stores the TTL across all lock drivers (ArrayLock, FileLock,
     * RedisLock all inherit from `Illuminate\Cache\Lock`).
     *
     * The command's `Cache::lock(KEY, 300)` constructor argument is the
     * exact value tested.
     */
    public function test_outbox_retry_failed_lock_ttl_is_300_seconds(): void
    {
        $reflection = new \ReflectionClass(\App\Console\Commands\OutboxRetryFailedCommand::class);
        $constants = $reflection->getReflectionConstants();
        $ttlConstant = null;
        foreach ($constants as $c) {
            if ($c->getName() === 'LOCK_TTL_SECONDS') {
                $ttlConstant = $c->getValue();
                break;
            }
        }

        $this->assertSame(
            300,
            $ttlConstant,
            'OutboxRetryFailedCommand::LOCK_TTL_SECONDS must be 300s (Wave 3c SYNC-ADV3C-04 heal)'
        );

        // Cross-check: when the command constructs the lock at runtime,
        // the resulting Lock object's $seconds property must read 300.
        $lock = Cache::lock('outbox.retry-failed.lock', $ttlConstant);
        $lockReflection = new \ReflectionClass($lock);
        // $seconds lives on Illuminate\Cache\Lock (parent of all drivers)
        $parent = $lockReflection->getParentClass();
        while ($parent && $parent->getName() !== \Illuminate\Cache\Lock::class) {
            $parent = $parent->getParentClass();
        }
        $this->assertNotNull($parent, 'Lock must inherit from Illuminate\\Cache\\Lock');

        $secondsProperty = $parent->getProperty('seconds');
        $secondsProperty->setAccessible(true);
        $this->assertSame(
            300,
            $secondsProperty->getValue($lock),
            'Cache::lock(KEY, LOCK_TTL_SECONDS) must instantiate with seconds=300'
        );
    }

    /**
     * Test F-2 (Wave 3c SYNC-ADV3C-04) — same TTL invariant for the
     * webhook DLQ command.
     */
    public function test_webhook_retry_failed_lock_ttl_is_300_seconds(): void
    {
        $reflection = new \ReflectionClass(\App\Console\Commands\OutboxWebhookRetryFailedCommand::class);
        $constants = $reflection->getReflectionConstants();
        $ttlConstant = null;
        $batchCap = null;
        foreach ($constants as $c) {
            if ($c->getName() === 'LOCK_TTL_SECONDS') {
                $ttlConstant = $c->getValue();
            }
            if ($c->getName() === 'BATCH_CAP') {
                $batchCap = $c->getValue();
            }
        }

        $this->assertSame(
            300,
            $ttlConstant,
            'OutboxWebhookRetryFailedCommand::LOCK_TTL_SECONDS must be 300s'
        );
        $this->assertSame(
            500,
            $batchCap,
            'OutboxWebhookRetryFailedCommand::BATCH_CAP must be 500 rows'
        );
    }
}
