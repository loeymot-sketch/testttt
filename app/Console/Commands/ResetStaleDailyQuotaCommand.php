<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [iter13 P1 STOCK 2026-05-09]
 *
 * Reset daily quota counters when `daily_reset_at` is older than today.
 *
 * Background : the lazy reset path in
 * `AvailabilityService::decrementForOrder()` only runs when an order is
 * placed. If a branch has no orders for several days, the
 * `daily_consumed_qty` counter stays frozen at the last day's value and
 * the row is permanently flagged unavailable when `consumed >= max_daily_qty`.
 * This cron resets all rows whose `daily_reset_at` is in the past so the
 * day-N+1 state is correct even with zero traffic.
 *
 * Schedule (registered in Console/Kernel.php) : `dailyAt('00:05')` per
 * branch timezone. Idempotent : the WHERE clause filters to past-dated
 * rows only ; same-day re-runs are no-ops.
 */
class ResetStaleDailyQuotaCommand extends Command
{
    protected $signature = 'foodking:availability:reset-stale-quota
                            {--dry-run : show row count without mutating}';

    protected $description = '[iter13 P1] Reset daily_consumed_qty and daily_reset_at on item_branch_availability rows where daily_reset_at < today.';

    public function handle(): int
    {
        // [Wave 3c KDS-ADV3C-03 P1 2026-05-18] Carbon::today() resolves in
        // app.timezone='Europe/Paris' but MySQL session TZ is UTC. The cron
        // runs at `0 0 * * *` Paris; if Carbon resolves Paris-today midnight
        // while the SQL session interprets that literal as UTC, the cutover
        // creates a 1-2h window where rows are reset but the `<` predicate
        // excludes them. Heal mirrors Wave 2b pattern (148dbebce): convert
        // Paris-local "today" to UTC for predicate binding, store the
        // Paris-local date (Y-m-d) for the column write because
        // `daily_reset_at` is column TYPE DATE (no TZ context — Paris-day
        // semantics is correct since reset is a business-day concept).
        $appTz = config('app.timezone');
        $todayForQuery = Carbon::today($appTz)->setTimezone('UTC');
        $todayForWrite = Carbon::today($appTz)->toDateString();
        $dryRun = (bool) $this->option('dry-run');

        $query = DB::table('item_branch_availability')
            ->whereDate('daily_reset_at', '<', $todayForQuery)
            ->whereNotNull('daily_reset_at');

        $count = (int) $query->count();

        if ($count === 0) {
            $this->info('No stale daily quota rows found.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("[dry-run] Would reset {$count} stale daily quota rows.");

            return self::SUCCESS;
        }

        $updated = $query->update([
            'daily_consumed_qty' => 0,
            'daily_reset_at'     => $todayForWrite,
            'updated_at'         => now(),
        ]);

        Log::info('foodking.availability.reset_stale_quota', [
            'rows_reset' => $updated,
            'reset_at'   => $todayForWrite,
        ]);

        $this->info("Reset {$updated} stale daily quota rows.");

        return self::SUCCESS;
    }
}
