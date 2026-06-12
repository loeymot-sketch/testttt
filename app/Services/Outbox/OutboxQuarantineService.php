<?php

namespace App\Services\Outbox;

use App\Models\DomainEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * [W-REM R1 T-R1.1a — 2026-06-12] QUARANTINE BY DEFAULT for expired outbox rows.
 *
 * Problem (production-observed): 8 405 `domain_events` rows sat pending
 * (dispatched_at NULL) for days. The pre-fix rescue lane A
 * (`pending + created_at < now()-2min + attempts<5`, NO upper age bound)
 * would replay ALL of them the moment a worker came back — re-broadcasting
 * a day+ of OrderCreated / StatusChanged history into KDS boards and
 * notification listeners.
 *
 * Contract:
 *   - pending rows older than the cutoff (default 24h) are marked
 *     `dispatched_at = now()` + `last_error = 'expired:quarantined'`.
 *   - NO broadcast, NO queue push, NO side-effect — a pure bounded UPDATE.
 *   - covers RED-SHARED-01: the (pending, attempts>=5, age>24h) hole that
 *     fell through rescue (attempts<5 cap) AND retry-failed (--since=24h).
 *   - quarantined rows are terminal: excluded from every replay lane
 *     (dispatched_at set) and from the MonitorOutboxStaleness crash-claimed
 *     alarm (marker exclusion) ; reclaimed by `foodking:outbox:prune`
 *     (clause A: dispatched_at < cutoff-90d).
 *
 * NF525: `domain_events` is an OPERATIONAL outbox, NOT a fiscal table.
 * audit_logs / z_reports are never touched. Broadcasts are advisory.
 */
class OutboxQuarantineService
{
    /**
     * Forensic marker — greppable, namespaced under `expired:` so monitoring
     * can distinguish intentional quarantine from runtime failures.
     */
    public const QUARANTINE_ERROR = 'expired:quarantined';

    /**
     * Pending rows older than this are quarantined instead of replayed.
     * 24h aligns with the retry-failed cron window (--since=24h): anything
     * older has already been declared out of the automatic recovery surface.
     */
    public const DEFAULT_CUTOFF_HOURS = 24;

    /**
     * Chunked UPDATE bound (lock-window control, mirrors PruneOutboxCommand).
     */
    public const UPDATE_CHUNK = 1000;

    /**
     * Per-run ceiling so a pathological backlog cannot pin a cron tick.
     * Overflow drains on the next run (every-minute rescue lane).
     */
    public const MAX_ROWS_PER_RUN = 50000;

    /**
     * Quarantine all expired pending rows. Returns the number of rows
     * quarantined. Pure UPDATE — never enqueues, never broadcasts.
     */
    public function quarantineExpired(?CarbonInterface $cutoff = null): int
    {
        $cutoff ??= now()->subHours(self::DEFAULT_CUTOFF_HOURS);

        $total = 0;

        do {
            $affected = (int) DomainEvent::query()
                ->whereNull('dispatched_at')
                ->where('created_at', '<', $cutoff)
                ->limit(self::UPDATE_CHUNK)
                ->update([
                    'dispatched_at' => now(),
                    'last_error' => self::QUARANTINE_ERROR,
                ]);

            $total += $affected;
        } while ($affected === self::UPDATE_CHUNK && $total < self::MAX_ROWS_PER_RUN);

        if ($total > 0) {
            Log::info('[OutboxQuarantine] Quarantined expired pending domain events (no broadcast).', [
                'event' => 'outbox.quarantine.expired',
                'quarantined' => $total,
                'cutoff' => $cutoff->toIso8601String(),
                'marker' => self::QUARANTINE_ERROR,
            ]);
        }

        return $total;
    }
}
