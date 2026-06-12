<?php

namespace App\Console\Commands;

use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use App\Services\Outbox\OutboxQuarantineService;
use Illuminate\Console\Command;

class OutboxRescueCommand extends Command
{
    protected $signature = 'foodking:outbox:rescue
                            {--limit=500 : Max fresh pending rows re-queued per run (lane A bound)}
                            {--cutoff-hours=24 : Pending rows older than this are quarantined (no broadcast) instead of replayed}';

    protected $description = 'Quarantine expired pending domain events (no broadcast), then re-queue fresh stale pending events (bounded)';

    public function handle(OutboxQuarantineService $quarantine): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $cutoffHours = max(1, (int) $this->option('cutoff-hours'));
        $expiryCutoff = now()->subHours($cutoffHours);

        // [W-REM R1 T-R1.1a 2026-06-12] QUARANTINE BY DEFAULT (runs on the
        // every-minute cron lane). Pending rows older than the cutoff are
        // terminal: mark dispatched_at=now + last_error='expired:quarantined'
        // WITHOUT any broadcast. Replaying a day+ of OrderCreated /
        // StatusChanged history into KDS/notifications (the pre-fix
        // behaviour, observed against an 8 405-row backlog) is strictly
        // worse than dropping advisory broadcasts that nobody waited for.
        // This also covers RED-SHARED-01: (pending, attempts>=5, age>24h)
        // fell through rescue (attempts<5 cap) AND retry-failed
        // (--since=24h window) — quarantine closes the hole.
        $quarantined = $quarantine->quarantineExpired($expiryCutoff);

        if ($quarantined > 0) {
            $this->info('Quarantined ' . $quarantined . ' expired domain events (no broadcast).');
        }

        $pendingStaleCutoff = now()->subMinutes(2);

        // [W-REM R1 T-R1.1b 2026-06-12 — F-SHARED-02] Lane B (crash-claimed
        // re-queue, heal B.4 2026-05-19) REMOVED. It re-queued ANY row with
        // dispatched_at NOT NULL older than 10 min and attempts<5 — but a
        // SUCCESSFULLY dispatched row keeps dispatched_at set forever
        // (DispatchDomainEventsJob Phase 3a clears last_error, keeps the
        // claim), so every cron tick released + re-broadcast every
        // successfully dispatched event, attempts 1→5 ≈ 4 duplicate
        // broadcasts per event. Rescue now NEVER touches a claimed row:
        // crash-claimed orphans are paged by MonitorOutboxStaleness (H3
        // alarm dimension) for MANUAL re-drive.
        //
        // [W-REM R1 T-R1.1c 2026-06-12] Lane A is BOUNDED:
        //   - fresh-only window: created_at within [expiryCutoff, now-2min]
        //     (older rows belong to the quarantine above — never replayed);
        //   - deterministic orderBy(id) + LIMIT batch so a backlog surge
        //     cannot trigger an unbounded scan / queue flood from the
        //     every-minute cron. Overflow drains on the next tick.
        $events = DomainEvent::query()
            ->whereNull('dispatched_at')
            ->where('created_at', '<', $pendingStaleCutoff)
            ->where('created_at', '>=', $expiryCutoff)
            ->where('attempts', '<', 5)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($events as $event) {
            // [Audit Claude NEW-03 B7] Queue lane SSOT = job constructor.
            DispatchDomainEventsJob::dispatch($event->id);
        }

        $this->info('Re-queued ' . $events->count() . ' stale domain events.');

        return self::SUCCESS;
    }
}
