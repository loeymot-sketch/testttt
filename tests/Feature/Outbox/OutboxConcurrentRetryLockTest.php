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
}
