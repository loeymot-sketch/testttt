<?php

namespace App\Console\Commands;

use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
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

        foreach ($events as $event) {
            $event->forceFill([
                'attempts' => 0,
                'last_error' => null,
                'dispatched_at' => null,
            ])->save();

            DispatchDomainEventsJob::dispatch($event->id)->onQueue('high');
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
