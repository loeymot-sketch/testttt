<?php

namespace App\Console\Commands;

use App\Jobs\ProcessWebhookEventJob;
use App\Models\WebhookEvent;
use App\Services\Fiscal\AuditLogService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * [Sprint H3 P1-Z8-02 2026-05-17] Webhook DLQ — reset and re-dispatch
 * `webhook_events` rows flipped to status=failed within the recovery
 * window. Mirrors `OutboxRetryFailedCommand` discipline.
 *
 * Usage:
 *   php artisan foodking:webhook:retry-failed --since=24h
 *
 * `--since` accepts intervals like `30m`, `1h`, `24h`, `7d`, or any
 * `Carbon::parse()`-compatible absolute date. Default is `24h` so the
 * command can be scheduled hourly without arguments.
 *
 * Operational contract:
 *  - Rows older than the window are NOT touched (staleness monitor
 *    pages a human for triage instead of indefinite re-queueing).
 *  - Rows are reset to `status=pending` BEFORE dispatch so a re-failure
 *    can flip them back to `failed` cleanly (the ledger always reflects
 *    the most recent attempt).
 *  - The `attempts` counter is intentionally NOT reset — webhook
 *    providers have their own retry budget and the application-side
 *    counter is informational only.
 *
 * Companion job: `app/Jobs/ProcessWebhookEventJob.php`.
 */
class OutboxWebhookRetryFailedCommand extends Command
{
    protected $signature = 'foodking:webhook:retry-failed {--since=24h}';

    protected $description = 'Reset and re-dispatch failed webhook_events within the recovery window.';

    /**
     * [Wave 3b SYNC-ADV3B-06 — P1 concurrency — 2026-05-18]
     * Distinct lock key from the domain-event outbox so the two retry
     * commands can run concurrently when scheduled together. 60s TTL +
     * `finally`-released to prevent an early throw from stranding the
     * key.
     */
    private const LOCK_KEY = 'outbox.webhook-retry-failed.lock';

    private const LOCK_TTL_SECONDS = 60;

    public function handle(): int
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            $this->warn('Another webhook:retry-failed run in progress. Skipping.');
            Log::channel('fiscal')->info('webhook.dlq.retry_failed_lock_contended', [
                'event' => 'webhook_dlq_retry_failed_lock_contended',
                'lock_key' => self::LOCK_KEY,
            ]);

            return self::SUCCESS;
        }

        try {
            return $this->runHandle();
        } finally {
            $lock->release();
        }
    }

    private function runHandle(): int
    {
        $cutoff = $this->resolveCutoff((string) $this->option('since'));

        $events = WebhookEvent::query()
            ->where('status', WebhookEvent::STATUS_FAILED)
            ->where('created_at', '>=', $cutoff)
            ->get();

        $auditLog = app(AuditLogService::class);

        foreach ($events as $event) {
            // [Wave 3 SYNC-ADV3-04 — NF525-adjacent — 2026-05-18]
            // Write-then-dispatch ordering: the tamper-evident audit row
            // MUST exist before we re-broadcast the event. If audit write
            // throws (chain-lock timeout, UNIQUE violation, secret
            // misconfig), we skip THIS event but `continue` so the rest
            // of the batch still replays. Guarantees: audit row exists
            // IFF dispatch was attempted, AND batch continuity is preserved.
            try {
                $auditLog->write([
                    'branch_id' => 0,
                    'user_id' => null,
                    'action' => 'outbox.replay',
                    'resource' => 'webhook_event',
                    'resource_id' => (int) $event->id,
                    'payload' => [
                        'command' => 'foodking:webhook:retry-failed',
                        'event_id' => (int) $event->id,
                        'webhook_id' => (string) $event->webhook_id,
                        'provider' => (string) $event->provider,
                        'event_type' => (string) $event->event_type,
                        'attempts' => (int) $event->attempts,
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::channel('fiscal')->error('Outbox replay audit_log write failed', [
                    'event_id' => (int) $event->id,
                    'error' => $e->getMessage(),
                ]);
                continue; // skip this event — do NOT dispatch without audit trail
            }

            try {
                // Reset to pending BEFORE dispatch so re-failure flips
                // cleanly. Attempts counter intentionally preserved.
                $event->forceFill([
                    'status'        => WebhookEvent::STATUS_PENDING,
                    'error_message' => null,
                ])->save();

                ProcessWebhookEventJob::dispatch($event->id);
            } catch (\Throwable $e) {
                Log::channel('fiscal')->error('Outbox replay dispatch failed (audit row exists)', [
                    'event_id' => (int) $event->id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        $msg = sprintf(
            '[Webhook DLQ] Reset and re-queued %d failed webhook events since %s.',
            $events->count(),
            $cutoff->toIso8601String()
        );
        $this->info($msg);
        Log::channel('fiscal')->info('webhook.dlq.retry_failed_run', [
            'event'  => 'webhook_dlq_retry_failed_run',
            'count'  => $events->count(),
            'cutoff' => $cutoff->toIso8601String(),
        ]);

        return self::SUCCESS;
    }

    private function resolveCutoff(string $since): Carbon
    {
        $normalized = strtolower(trim($since));

        if (preg_match('/^(?<value>\d+)(?<unit>[smhd])$/', $normalized, $matches) === 1) {
            $value = (int) $matches['value'];

            return match ($matches['unit']) {
                's'     => now()->subSeconds($value),
                'm'     => now()->subMinutes($value),
                'h'     => now()->subHours($value),
                'd'     => now()->subDays($value),
                default => throw new InvalidArgumentException('Unsupported --since unit.'),
            };
        }

        try {
            return Carbon::parse($normalized);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException(
                'Invalid --since value. Use formats like 30m, 1h, 24h, 2d, or a date.',
                0,
                $exception
            );
        }
    }
}
