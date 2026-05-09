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
        $events = DomainEvent::query()
            ->stale(2)
            ->where('attempts', '<', 5)
            ->get();

        foreach ($events as $event) {
            // [Audit Claude NEW-03 B7] Queue lane SSOT = job constructor.
            DispatchDomainEventsJob::dispatch($event->id);
        }

        $this->info('Re-queued ' . $events->count() . ' stale domain events.');

        return self::SUCCESS;
    }
}
