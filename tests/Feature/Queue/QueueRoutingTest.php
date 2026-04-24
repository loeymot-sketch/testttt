<?php

namespace Tests\Feature\Queue;

use App\Jobs\DispatchDomainEventsJob;
use App\Jobs\SendFcmNotificationJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * [F-NEW03 / Lot NEW-03] Verifies that latency-critical jobs and best-effort
 * jobs land on the correct queues, so a slow FCM send cannot starve the
 * outbox dispatcher (POS/KDS/Borne real-time sync).
 *
 * Topology contract (SSOT: docs/operations/QUEUE_TOPOLOGY.md):
 * - DispatchDomainEventsJob → 'high'           (SLO p95 < 2s)
 * - SendFcmNotificationJob  → 'notifications'  (best effort, 30s OK)
 *
 * Worker order (Supervisor): high,notifications,default — see topology doc §2.
 */
class QueueRoutingTest extends TestCase
{
    public function test_dispatch_domain_events_job_targets_high_queue(): void
    {
        Queue::fake();

        DispatchDomainEventsJob::dispatch(123);

        Queue::assertPushedOn('high', DispatchDomainEventsJob::class);
    }

    public function test_send_fcm_notification_job_targets_notifications_queue(): void
    {
        Queue::fake();

        SendFcmNotificationJob::dispatch(
            'token-abc',
            null,
            'Order ready',
            'Your order is ready for pickup',
            ['order_id' => 1],
            'default'
        );

        Queue::assertPushedOn('notifications', SendFcmNotificationJob::class);
    }

    public function test_send_fcm_via_topic_also_targets_notifications_queue(): void
    {
        Queue::fake();

        SendFcmNotificationJob::dispatch(
            null,
            'branch-1',
            'New order',
            'A new order arrived',
            [],
            'default'
        );

        Queue::assertPushedOn('notifications', SendFcmNotificationJob::class);
    }

    /**
     * Defense-in-depth: a misconfigured caller chaining ->onQueue('default')
     * MUST be able to override (edge case for ad-hoc tooling). We assert the
     * default routing is preserved for the standard dispatch() path; explicit
     * override is a separate concern intentionally NOT blocked by this test.
     */
    public function test_dispatch_domain_events_default_queue_property(): void
    {
        $job = new DispatchDomainEventsJob(42);

        $this->assertSame('high', $job->queue);
    }

    public function test_send_fcm_default_queue_property(): void
    {
        $job = new SendFcmNotificationJob('token', null, 'title', 'body');

        $this->assertSame('notifications', $job->queue);
    }

    /**
     * [Backoff curve guard — NEW-03 / Audit T G2]
     * The 5-step backoff [1,5,15,60,300] is paired with $tries=6 so the 300s
     * trailing entry is actually consumed (Laravel reuses the last value when
     * tries > count(backoff)). This yields a worst-case ~6.4 min retry window
     * that outlasts a typical Pusher/Soketi restart (1-3 min). Bumping tries
     * to 6 was the explicit fix for Audit T G2 — previously tries=5 made the
     * 300s entry unreachable and the documented intent unattainable.
     */
    public function test_dispatch_domain_events_backoff_curve_outlasts_pusher_restart(): void
    {
        $job = new DispatchDomainEventsJob(1);

        $this->assertSame([1, 5, 15, 60, 300], $job->backoff);
        $this->assertSame(6, $job->tries);
        $this->assertGreaterThan(
            count($job->backoff),
            $job->tries,
            'tries MUST exceed backoff length so the last 300s delay is actually consumed (Audit T G2).'
        );

        // Assert the worst-case retry window is at least 6 minutes (Pusher restart SLA buffer).
        $totalDelay = array_sum($job->backoff);
        $this->assertGreaterThanOrEqual(360, $totalDelay, 'Backoff sum must give >= 6 min coverage.');
    }

    /**
     * [Backoff curve guard — NEW-03]
     * FCM has its own server-side retry; we keep client-side $tries=3 to avoid
     * amplifying a Google-throttling 503-storm.
     */
    public function test_send_fcm_backoff_is_short_to_avoid_amplifying_throttling(): void
    {
        $job = new SendFcmNotificationJob('token', null, 'title', 'body');

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 30], $job->backoff());
    }

    /**
     * [T-MISS-D / Audit T] Dispatch-time `->onQueue(...)` override MUST win
     * over the constructor-default routing. This is intentional Laravel
     * behavior; we lock it as a documented escape hatch (e.g. for ad-hoc
     * tooling that wants to drain a slow lane without polluting prod queues).
     * This test makes the precedence explicit and prevents accidental
     * "constructor wins" regressions during future Laravel upgrades.
     */
    public function test_dispatch_time_queue_override_wins_over_constructor_default(): void
    {
        Queue::fake();

        SendFcmNotificationJob::dispatch('token-xyz', null, 'title', 'body')->onQueue('default');

        Queue::assertPushedOn('default', SendFcmNotificationJob::class);
    }
}
