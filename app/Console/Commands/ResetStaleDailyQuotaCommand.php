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
        // [WH-3 bug_003 2026-05-19] `daily_reset_at` is a DATE column
        // (`$table->date(...)` in migration 2026_04_15_230100). DATE columns
        // store plain Y-m-d strings with NO timezone semantics in MySQL.
        // The predicate `whereDate('daily_reset_at', '<', $today)` is a
        // lexical Y-m-d compare on both driver families (MySQL + SQLite).
        //
        // Earlier Wave 3c heal applied TIMESTAMP-style TZ conversion
        // (`->setTimezone('UTC')`) to the predicate side while keeping a
        // plain Paris Y-m-d on the write side. That inversion shifted the
        // predicate literal back one day (Paris '2026-01-15' → UTC
        // '2026-01-14 23:00' → Eloquent formats with INSTANCE TZ → bound
        // '2026-01-14'), so the cron at 00:05 Paris on day-N+1 failed to
        // pick up rows whose `daily_reset_at='2026-N'` (yesterday Paris).
        // Real-world impact: items hitting `max_daily_qty` stay
        // 86-rupture-flagged ONE FULL BUSINESS DAY longer than intended.
        //
        // Heal: resolve "today" once in app.timezone (Paris) as a plain
        // Y-m-d string and use the SAME variable for predicate AND write.
        // Mirrors the canonical pattern in
        // `AvailabilityService::decrementForOrder()` and ::toggle().
        // Sentinel: tests/Feature/Sentinels/ResetStaleDailyQuotaTzCorrectSentinelTest.php
        $today = Carbon::today(config('app.timezone'))->toDateString();
        $dryRun = (bool) $this->option('dry-run');

        $query = DB::table('item_branch_availability')
            ->whereDate('daily_reset_at', '<', $today)
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

        // [SELF-AUDIT R5 P2 2026-07-05 — article 86'd à VIE] Le reset zéro-tait daily_consumed_qty mais ne
        // REMETTAIT PAS is_available=true → un article auto-86'd par le quota (is_available=false,
        // unavailable_reason='out_of_stock') restait EN RUPTURE chaque jour APRÈS le premier (le compteur
        // repart de 0 mais l'article reste marqué indisponible). On ré-active UNIQUEMENT les 86 auto-quota
        // ('out_of_stock') du set périmé — les 86 MANUELS (supplier_issue…) et cron ('stock_rupture') sont
        // préservés (miroir du garde AvailabilityService). Fait AVANT le reset du compteur (le scope stale
        // dépend de daily_reset_at < today, que le reset va justement changer).
        $staleScope = fn () => DB::table('item_branch_availability')
            ->whereDate('daily_reset_at', '<', $today)
            ->whereNotNull('daily_reset_at');

        $reenabled = $staleScope()
            ->where('is_available', false)
            ->where('unavailable_reason', 'out_of_stock')
            ->update([
                'is_available' => true,
                'unavailable_reason' => null,
                'unavailable_since' => null,
                'updated_at' => now(),
            ]);

        $updated = $staleScope()->update([
            'daily_consumed_qty' => 0,
            'daily_reset_at' => $today,
            'updated_at' => now(),
        ]);

        if ($reenabled > 0) {
            Log::info('foodking.availability.reset_reenabled_quota_86', ['rows_reenabled' => $reenabled, 'reset_at' => $today]);
        }

        Log::info('foodking.availability.reset_stale_quota', [
            'rows_reset' => $updated,
            'reset_at' => $today,
        ]);

        $this->info("Reset {$updated} stale daily quota rows.");

        return self::SUCCESS;
    }
}
