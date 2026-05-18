<?php

namespace App\Console\Commands;

use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use App\Services\Fiscal\AuditLogService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use InvalidArgumentException;

class OutboxRetryFailedCommand extends Command
{
    protected $signature = 'foodking:outbox:retry-failed {--since=1h}';

    protected $description = 'Reset and retry failed domain events';

    public function handle(): int
    {
        $cutoff = $this->resolveCutoff((string) $this->option('since'));

        $events = DomainEvent::query()
            ->failed(5)
            ->where('created_at', '>=', $cutoff)
            ->get();

        $auditLog = app(AuditLogService::class);

        foreach ($events as $event) {
            $event->forceFill([
                'attempts' => 0,
                'last_error' => null,
                'dispatched_at' => null,
            ])->save();

            // [Audit Claude NEW-03 B7] Queue lane SSOT = job constructor.
            DispatchDomainEventsJob::dispatch($event->id);

            // [Wave 1 SYNC-RED-03 — NF525-adjacent — 2026-05-18]
            // Manual DLQ replay re-broadcasts domain events (ORDER_*,
            // PAYMENT_*) potentially triggering side-effects on KDS / OSS
            // / fiscal flows. Append a tamper-evident audit_logs row per
            // replayed event for post-incident traceability.
            $auditLog->write([
                'branch_id' => (int) ($event->branch_id ?? 0),
                'user_id' => null,
                'action' => 'outbox.replay',
                'resource' => 'domain_event',
                'resource_id' => (int) $event->id,
                'payload' => [
                    'command' => 'foodking:outbox:retry-failed',
                    'event_id' => (int) $event->id,
                    'event_type' => (string) $event->event_type,
                    'aggregate_type' => (string) ($event->aggregate_type ?? ''),
                    'aggregate_id' => (int) ($event->aggregate_id ?? 0),
                    'correlation_id' => (string) ($event->correlation_id ?? ''),
                ],
            ]);
        }

        $this->info('Reset and re-queued ' . $events->count() . ' failed domain events.');

        return self::SUCCESS;
    }

    private function resolveCutoff(string $since): Carbon
    {
        $normalized = strtolower(trim($since));

        if (preg_match('/^(?<value>\d+)(?<unit>[smhd])$/', $normalized, $matches) === 1) {
            $value = (int) $matches['value'];

            return match ($matches['unit']) {
                's' => now()->subSeconds($value),
                'm' => now()->subMinutes($value),
                'h' => now()->subHours($value),
                'd' => now()->subDays($value),
                default => throw new InvalidArgumentException('Unsupported --since unit.'),
            };
        }

        try {
            return Carbon::parse($normalized);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('Invalid --since value. Use formats like 30m, 1h, 2d, or a date.', 0, $exception);
        }
    }
}
