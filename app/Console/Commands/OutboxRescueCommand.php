<?php

namespace App\Console\Commands;

use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Console\Command;

class OutboxRescueCommand extends Command
{
    protected $signature = 'foodking:outbox:rescue';

    protected $description = 'Re-queue stale pending domain events';

    public function handle(): int
    {
        // [HEAL B.4 2026-05-19] Two-lane rescue:
        //   (A) classic pending lane — pending() + created_at < now()-2min
        //       (legacy `stale(2)` semantics, unchanged behaviour).
        //   (B) crash-claimed lane — dispatched_at NOT NULL but older than 10
        //       minutes. Closes the orphan window when a worker is killed
        //       between Phase 1 (set dispatched_at=now, ++attempts) and
        //       Phase 3b (reset dispatched_at on throw) in
        //       DispatchDomainEventsJob.php:65-165. Without this lane, the
        //       row's dispatched_at sticks at the crash timestamp forever:
        //       rescue / MonitorOutboxStaleness / RetryFailed all filter on
        //       whereNull('dispatched_at') → silent loss.
        //   10-min threshold > worst-case worker backoff curve (1+5+15+60+300
        //   = 381s ≈ 6.4 min, see DispatchDomainEventsJob.php:30-32) + worst
        //   observed Pusher/Soketi broadcast hang (~30-60s, no explicit HTTP
        //   timeout in config/broadcasting.php). attempts<5 cap keeps this
        //   lane disjoint from RetryFailed's `failed(5)` scope (attempts>=5).
        //   Source: reports/audit/v1-sync-deep-audit-2026-05-19/HEAL-PLAN-B-outbox-sync.md §Heal-4
        $crashRecoveryCutoff = now()->subMinutes(10);
        $pendingStaleCutoff = now()->subMinutes(2);

        $events = DomainEvent::query()
            ->where(function ($q) use ($pendingStaleCutoff, $crashRecoveryCutoff) {
                $q->where(function ($pending) use ($pendingStaleCutoff) {
                    $pending->whereNull('dispatched_at')
                        ->where('created_at', '<', $pendingStaleCutoff);
                })->orWhere(function ($crashed) use ($crashRecoveryCutoff) {
                    $crashed->whereNotNull('dispatched_at')
                        ->where('dispatched_at', '<', $crashRecoveryCutoff);
                });
            })
            ->where('attempts', '<', 5)
            // [PERF SYNC 2026-07-31] Cap symetrique a OutboxRetryFailedCommand::BATCH_CAP (500). En
            // regime nominal = 0 ligne ; apres une panne worker, evite d'enfiler des milliers de jobs
            // d'un coup sur la file high — l'overflow draine au tick minute suivant (lissage recovery).
            ->take(500)
            ->get();

        foreach ($events as $event) {
            // [HEAL B.4 2026-05-19] For crash-claimed rows, release the stuck
            // claim BEFORE the new job's Phase 1 lockForUpdate guard re-evaluates.
            // Without this, DispatchDomainEventsJob.php:75-77 sees
            // dispatched_at != null and returns silent-skip, defeating recovery.
            // Row-level lockForUpdate at DispatchDomainEventsJob.php:65-86
            // serialises this release against any straggler worker still in
            // Phase 1 — worst case is one extra broadcast (visible re-render,
            // no fiscal corruption — broadcasts are advisory).
            if ($event->dispatched_at !== null) {
                $event->forceFill(['dispatched_at' => null])->save();
            }

            // [Audit Claude NEW-03 B7] Queue lane SSOT = job constructor.
            DispatchDomainEventsJob::dispatch($event->id);
        }

        $this->info('Re-queued ' . $events->count() . ' stale domain events.');

        return self::SUCCESS;
    }
}
