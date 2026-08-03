<?php

namespace App\Console\Commands;

use App\Services\PosParkedOrderService;
use Illuminate\Console\Command;

class PosPurgeParkedOrders extends Command
{
    protected $signature = 'pos:purge-parked-orders {--older-than-hours=24}';

    protected $description = 'Purge parked POS orders older than the configured number of hours';

    public function handle(PosParkedOrderService $service): int
    {
        $hours = max(1, (int) $this->option('older-than-hours'));
        $deleted = $service->purgeOlderThanHours($hours);

        $this->info(sprintf(
            'Purged %d parked POS order(s) older than %d hour(s).',
            $deleted,
            $hours
        ));

        return self::SUCCESS;
    }
}
