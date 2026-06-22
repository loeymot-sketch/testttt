<?php

namespace App\Console\Commands;

use App\Services\PosParkedOrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class PosPurgeParkedOrders extends Command
{
    protected $signature = 'pos:purge-parked-orders {--older-than-hours=24}';

    protected $description = 'Purge parked POS orders older than the configured number of hours';

    public function handle(PosParkedOrderService $service): int
    {
        $hours = max(1, (int) $this->option('older-than-hours'));

        // [CAISSE-S2] This runs unattended via the scheduler (dailyAt 03:15). Convert an unhandled
        // throw (DB hiccup / lock timeout) into a domain-tagged log + clean FAILURE exit so the
        // operator/scheduler gets a signal instead of a silent skip. Serialize the full exception so
        // the stack trace the framework would have surfaced is not lost. Happy path is unchanged.
        try {
            $deleted = $service->purgeOlderThanHours($hours);
        } catch (Throwable $e) {
            Log::error('pos:purge-parked-orders failed', ['hours' => $hours, 'exception' => $e]);
            $this->error(sprintf('Purge of parked POS orders failed: %s', $e->getMessage()));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Purged %d parked POS order(s) older than %d hour(s).',
            $deleted,
            $hours
        ));

        return self::SUCCESS;
    }
}
