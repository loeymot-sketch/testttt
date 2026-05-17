<?php

namespace Tests\Feature\Sync;

use App\Jobs\ProcessWebhookEventJob;
use App\Models\WebhookEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * [Sprint H3 P1-Z8-02 2026-05-17] Webhook DLQ — failed webhook_events
 * rows within the --since window must be re-dispatched via
 * ProcessWebhookEventJob, complementing Wave Z 5C's outbox retry-failed
 * lane. Mirrors `OutboxRetryFailedScheduleTest` discipline.
 *
 * CLAUDE.md §9 — webhook idempotency ledger + DLQ recovery is the gap.
 * Wave Z 5C scheduled `foodking:webhook:retry-failed` but the command
 * did not exist; this sentinel pins:
 *
 *  1. Failed-in-window rows are picked + dispatched.
 *  2. Out-of-window rows are NOT touched (staleness monitor owns those).
 *  3. Already-processed rows are NOT touched.
 *  4. Kernel registers the schedule hourly with mutex + onOneServer.
 */
class WebhookDLQRetryFailedTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_failed_picks_up_failed_rows_within_window(): void
    {
        Bus::fake();

        // Failed within window — must be picked up + dispatched.
        $recent = WebhookEvent::create([
            'provider'   => WebhookEvent::PROVIDER_STRIPE,
            'webhook_id' => 'evt_test_recent',
            'event_type' => 'charge.succeeded',
            'payload'    => ['id' => 'evt_test_recent'],
            'status'     => WebhookEvent::STATUS_FAILED,
            'attempts'   => 3,
            'received_at' => now()->subHours(2),
        ]);
        $recent->forceFill([
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ])->save();

        // Failed outside window (>24h) — must NOT be picked.
        $stale = WebhookEvent::create([
            'provider'   => WebhookEvent::PROVIDER_STRIPE,
            'webhook_id' => 'evt_test_stale',
            'event_type' => 'charge.succeeded',
            'payload'    => ['id' => 'evt_test_stale'],
            'status'     => WebhookEvent::STATUS_FAILED,
            'attempts'   => 3,
            'received_at' => now()->subHours(48),
        ]);
        $stale->forceFill([
            'created_at' => now()->subHours(48),
            'updated_at' => now()->subHours(48),
        ])->save();

        // Already processed — must NOT be picked.
        $done = WebhookEvent::create([
            'provider'   => WebhookEvent::PROVIDER_STRIPE,
            'webhook_id' => 'evt_test_done',
            'event_type' => 'charge.succeeded',
            'payload'    => ['id' => 'evt_test_done'],
            'status'     => WebhookEvent::STATUS_PROCESSED,
            'attempts'   => 1,
            'received_at' => now()->subHours(2),
        ]);
        $done->forceFill([
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ])->save();

        $exit = Artisan::call('foodking:webhook:retry-failed', ['--since' => '24h']);
        $this->assertSame(0, $exit);

        // Recent row was reset to pending so a re-failure flips cleanly.
        $recent->refresh();
        $this->assertSame(WebhookEvent::STATUS_PENDING, $recent->status);

        // Stale + done untouched.
        $stale->refresh();
        $this->assertSame(WebhookEvent::STATUS_FAILED, $stale->status);
        $done->refresh();
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $done->status);

        // Job dispatched only for the recent row.
        Bus::assertDispatched(ProcessWebhookEventJob::class, function (ProcessWebhookEventJob $job) use ($recent): bool {
            return $job->webhookEventId === $recent->id;
        });
        Bus::assertDispatchedTimes(ProcessWebhookEventJob::class, 1);
    }

    public function test_kernel_registers_webhook_retry_failed_hourly(): void
    {
        $events = collect($this->app->make(Schedule::class)->events());

        $entry = $events->first(function ($event) {
            return str_contains((string) $event->command, 'foodking:webhook:retry-failed');
        });

        $this->assertNotNull(
            $entry,
            'foodking:webhook:retry-failed must be scheduled (Sprint H3 P1-Z8-02). '
                . 'Without this schedule, failed webhook_events never recover after the provider stops retrying.'
        );

        $command = (string) $entry->command;
        $this->assertStringContainsString('--since=24h', $command, '24h window flag must be present to bound recovery scope.');

        $this->assertSame(
            '0 * * * *',
            (string) $entry->expression,
            'webhook retry-failed must run hourly (cron `0 * * * *`).'
        );

        $this->assertNotEmpty(
            $entry->mutex,
            'withoutOverlapping required to prevent concurrent retry runs.'
        );

        $this->assertTrue(
            (bool) $entry->onOneServer,
            'onOneServer required to prevent multi-node double-reset of the same batch.'
        );
    }
}
