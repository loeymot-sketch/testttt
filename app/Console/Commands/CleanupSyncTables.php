<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupSyncTables extends Command
{
    protected $signature = 'foodking:sync:cleanup {--days=30}';

    protected $description = 'Delete pusher_event_acks and dispatched domain_events older than --days (default 30).';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $acks = DB::table('pusher_event_acks')
            ->where('received_at', '<', $cutoff)
            ->delete();

        $events = DB::table('domain_events')
            ->whereNotNull('dispatched_at')
            ->where('dispatched_at', '<', $cutoff)
            ->delete();

        $this->info(sprintf(
            'Deleted %d pusher_event_acks and %d dispatched domain_events older than %s (cutoff=%s).',
            $acks,
            $events,
            $days . 'd',
            $cutoff->toDateTimeString()
        ));

        return self::SUCCESS;
    }
}
