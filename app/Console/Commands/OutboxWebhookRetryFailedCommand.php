<?php

namespace App\Console\Commands;

use App\Jobs\ProcessWebhookEventJob;
use App\Models\WebhookEvent;
use App\Services\Fiscal\AuditLogService;
use Carbon\Carbon;
use Illuminate\Console\Command;
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

    public function handle(): int
    {
        $cutoff = $this->resolveCutoff((string) $this->option('since'));

        $events = WebhookEvent::query()
            ->where('status', WebhookEvent::STATUS_FAILED)
            ->where('created_at', '>=', $cutoff)
            ->get();

        $auditLog = app(AuditLogService::class);

        foreach ($events as $event) {
            // Reset to pending BEFORE dispatch so re-failure flips
            // cleanly. Attempts counter intentionally preserved.
            $event->forceFill([
                'status'        => WebhookEvent::STATUS_PENDING,
                'error_message' => null,
            ])->save();

            ProcessWebhookEventJob::dispatch($event->id);

            // [Wave 1 SYNC-RED-03 — NF525-adjacent — 2026-05-18]
            // Manual DLQ replay can re-trigger Stripe / SenangPay payment
            // processing → fiscal sequence allocation. Append a tamper-
            // evident audit_logs row so the financial trail survives
            // post-incident audit. webhook_events has no branch_id column
            // (provider webhooks are tenant-agnostic) — pin to chain 0
            // (system/CLI) per AuditLogService contract.
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
