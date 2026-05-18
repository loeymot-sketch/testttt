<?php

namespace App\Console\Commands;

use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use App\Services\Fiscal\AuditLogService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class OutboxRetryFailedCommand extends Command
{
    protected $signature = 'foodking:outbox:retry-failed {--since=1h}';

    protected $description = 'Reset and retry failed domain events';

    /**
     * [Wave 3b SYNC-ADV3B-06 — P1 concurrency — 2026-05-18]
     * [Wave 3c SYNC-ADV3C-04 — P1 latent — 2026-05-18]
     * Cache::lock key for the outbox retry concurrency guard. Two admins
     * (or cron + manual) firing this command in the same minute would
     * otherwise both grab the same `failed` rows and double-write
     * audit_logs + double-dispatch events.
     *
     * TTL raised 60→300s (Wave 3c) because audit-chain Cache::lock
     * contention under DLQ surge could push a single batch past the old
     * 60s window, vacating the key mid-handle and re-introducing the
     * double-dispatch defect. 5 min covers worst-case observed wall-clock
     * even at the 500-row batch cap below.
     *
     * BATCH_CAP bounds wall-clock for the LOCK_TTL window. Combined with
     * the hourly cron at app/Console/Kernel.php, an overflowing DLQ
     * drains within `ceil(N/500)` cron iterations — preferable to a
     * single-batch lock-overrun race.
     *
     * The lock is released in `finally` so an early throw never strands
     * the key.
     */
    private const LOCK_KEY = 'outbox.retry-failed.lock';

    private const LOCK_TTL_SECONDS = 300;

    private const BATCH_CAP = 500;

    public function handle(): int
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            $this->warn('Another outbox:retry-failed run in progress. Skipping.');
            Log::channel('fiscal')->info('outbox.retry_failed.lock_contended', [
                'event' => 'outbox_retry_failed_lock_contended',
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

        // [Wave 3c SYNC-ADV3C-04 — P1 latent — 2026-05-18]
        // BATCH_CAP bounds the single-run wall-clock so the LOCK_TTL
        // window can never expire mid-handle. Overflow drains on the
        // next hourly cron tick (Kernel.php:65).
        $events = DomainEvent::query()
            ->failed(5)
            ->where('created_at', '>=', $cutoff)
            ->orderBy('id') // deterministic order ⇒ overflow is the same tail each run
            ->take(self::BATCH_CAP)
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
            } catch (\Throwable $e) {
                Log::channel('fiscal')->error('Outbox replay audit_log write failed', [
                    'event_id' => (int) $event->id,
                    'error' => $e->getMessage(),
                ]);
                continue; // skip this event — do NOT dispatch without audit trail
            }

            try {
                $event->forceFill([
                    'attempts' => 0,
                    'last_error' => null,
                    'dispatched_at' => null,
                ])->save();

                // [Audit Claude NEW-03 B7] Queue lane SSOT = job constructor.
                DispatchDomainEventsJob::dispatch($event->id);
            } catch (\Throwable $e) {
                Log::channel('fiscal')->error('Outbox replay dispatch failed (audit row exists)', [
                    'event_id' => (int) $event->id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
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
