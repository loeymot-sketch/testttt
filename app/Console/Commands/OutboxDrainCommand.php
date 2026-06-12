<?php

namespace App\Console\Commands;

use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use App\Services\Outbox\OutboxQuarantineService;
use Illuminate\Console\Command;

/**
 * [W-REM R1 T-R1.1 — 2026-06-12] Operator-facing outbox drain.
 *
 * `foodking:outbox:drain` = the SAFE way to empty a stale outbox backlog:
 *   1. QUARANTINE (default, always first): pending rows older than
 *      --cutoff-hours are marked dispatched_at=now +
 *      last_error='expired:quarantined' WITHOUT broadcast — a day+ of
 *      OrderCreated/StatusChanged history must never replay into
 *      KDS/notification listeners (8 405-row backlog observed).
 *   2. RE-QUEUE ONLY THE FRESH: pending rows within the cutoff window
 *      (and past the 2-min worker grace) are re-queued, bounded by
 *      --limit, deterministic id order.
 *
 * `--quarantine-only` pushes ZERO jobs (step 1 only) — the mode used for
 * the production/e2e backlog purge where nothing may reach redis.
 * `--dry-run` reports both counts without writing anything.
 *
 * NF525: domain_events is an operational outbox; broadcasts are advisory.
 * No fiscal table is touched.
 */
class OutboxDrainCommand extends Command
{
    protected $signature = 'foodking:outbox:drain
                            {--cutoff-hours=24 : Pending rows older than this are quarantined (no broadcast)}
                            {--limit=500 : Max fresh pending rows re-queued}
                            {--quarantine-only : Only quarantine expired rows; push NOTHING to the queue}
                            {--dry-run : Report counts without writing}';

    protected $description = 'Quarantine expired pending domain events (no broadcast) then re-queue ONLY fresh pending rows';

    public function handle(OutboxQuarantineService $quarantine): int
    {
        $cutoffHours = max(1, (int) $this->option('cutoff-hours'));
        $limit = max(1, (int) $this->option('limit'));
        $expiryCutoff = now()->subHours($cutoffHours);
        $pendingStaleCutoff = now()->subMinutes(2);

        $freshQuery = fn () => DomainEvent::query()
            ->whereNull('dispatched_at')
            ->where('created_at', '<', $pendingStaleCutoff)
            ->where('created_at', '>=', $expiryCutoff)
            ->where('attempts', '<', 5);

        if ($this->option('dry-run')) {
            $expiredCount = (int) DomainEvent::query()
                ->whereNull('dispatched_at')
                ->where('created_at', '<', $expiryCutoff)
                ->count();
            $freshCount = (int) $freshQuery()->count();

            $this->info(sprintf(
                '[dry-run] %d expired pending row(s) would be quarantined (no broadcast); %d fresh pending row(s) eligible for re-queue (limit=%d).',
                $expiredCount,
                $freshCount,
                $limit
            ));

            return self::SUCCESS;
        }

        $quarantined = $quarantine->quarantineExpired($expiryCutoff);
        $this->info('Quarantined ' . $quarantined . ' expired domain events (no broadcast).');

        if ($this->option('quarantine-only')) {
            $this->info('Re-queued 0 fresh domain events (quarantine-only).');

            return self::SUCCESS;
        }

        $events = $freshQuery()
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($events as $event) {
            // [Audit Claude NEW-03 B7] Queue lane SSOT = job constructor.
            DispatchDomainEventsJob::dispatch($event->id);
        }

        $this->info('Re-queued ' . $events->count() . ' fresh domain events.');

        return self::SUCCESS;
    }
}
