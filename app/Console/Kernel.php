<?php

namespace App\Console;

use App\Jobs\CleanupStalePendingKioskOrders;
use App\Jobs\Observability\SloEvaluatorJob;
use App\Models\Branch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // [GAP-20-4] Purge expired OTPs every 15 minutes to prevent table bloat.
        // OTPs expire after otp_expire_time minutes (default 5). This cleanup runs
        // every 15 minutes as a safety net in addition to the opportunistic cleanup
        // in OtpManagerService::otp(). Uses DB facade directly to avoid loading the
        // full OtpManagerService for a simple DELETE query.
        $schedule->call(function () {
            $expireMinutes = (int) \Smartisan\Settings\Facades\Settings::group('otp')->get('otp_expire_time') ?: 5;
            \Illuminate\Support\Facades\DB::table('otps')
                ->where('created_at', '<', now()->subMinutes($expireMinutes + 1))
                ->delete();
        })->everyFifteenMinutes()->name('purge-expired-otps')->withoutOverlapping();

        // [W9-AUDIT FIX-6] Both rescue + cleanup must run on a single application
        // node when scaled horizontally to avoid double-processing the same outbox
        // batch / stale order set across nodes. `withoutOverlapping` only prevents
        // re-entry on the SAME host; `onOneServer` adds cross-host serialization.
        $schedule->command('foodking:outbox:rescue')
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer();

        // [AUDIT-F-015] Outbox staleness alerter — complements rescue.
        // Rescue re-queues stuck events; if the queue worker is down,
        // rescue is silent. This monitor raises a Log::error + non-zero
        // exit so supervisor/pager backends fire when the pipeline is
        // degraded. See plans/PLAN_AUDIT_F015_QUEUE_CONFIG_PROD_2026-05-07.md.
        $schedule->command('foodking:outbox:monitor --threshold=10')
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer();

        // [Sprint 3B P1-SYNC-02 2026-05-16] Retry rows where attempts >= 5
        // (terminal failures past Phase 1 retries). The complementary
        // `outbox:rescue` only re-queues rows with attempts < 5, so without
        // this schedule terminal failures stay pending forever and silently
        // never broadcast. Scoped to last 24h to bound the recovery surface
        // (older rows are paged on the staleness monitor / require manual
        // triage). Command signature uses `--since=24h` for consistency with
        // the existing CLI idiom (see `OutboxRetryFailedCommand::resolveCutoff`);
        // semantically equivalent to the `--max-age-hours=24` spec note.
        $schedule->command('foodking:outbox:retry-failed --since=24h')
            ->hourly()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('outbox-retry-failed')
            ->description('Retry domain events failed after 5 attempts within last 24h');

        $schedule->job(new CleanupStalePendingKioskOrders())
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        // [MEGA 2.G / F-11] Purge POS parked-order snapshots older than 24h (TTL).
        $schedule->command('pos:purge-parked-orders --older-than-hours=24')
            ->dailyAt('03:15')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->job(new SloEvaluatorJob())
            ->everyFiveMinutes()
            ->withoutOverlapping(5)
            ->onOneServer();

        $schedule->command('stock:scan-rupture')
            ->cron(config('catalog_v15.auto_86_preventive_cron.cron_expression', '*/5 * * * *'))
            ->name('stock-scan-rupture')
            ->withoutOverlapping()
            ->onOneServer()
            ->when(fn () => (bool) config('catalog_v15.auto_86_preventive_cron.enabled', false));

        // [iter14 SPECIALIST-3 / FISCAL-ORPHAN-RETRY]
        // Retry NF525 fiscal sequence allocation for kiosk-paid orders
        // flagged with `fiscal_alloc_error_at`. Same cadence + concurrency
        // primitives as outbox:rescue / outbox:monitor — everyMinute is OK
        // because the predicate is sparse (only failed-alloc rows match)
        // and the command short-circuits on empty.
        $schedule->command('foodking:fiscal:retry-alloc')
            ->everyMinute()
            ->name('foodking-fiscal-retry-alloc')
            ->withoutOverlapping(5)
            ->onOneServer();

        // [iter13 P1 STOCK 2026-05-09] Daily quota stale reset.
        //
        // Lazy reset (in AvailabilityService::decrementForOrder) only fires
        // on next order. Branches with no traffic across day boundary stay
        // on yesterday's counter → permanent unavailable flip if quota was
        // hit yesterday. Cron runs at 00:05 to clear stale rows even without
        // traffic. Idempotent (only past-dated rows match WHERE clause).
        $schedule->command('foodking:availability:reset-stale-quota')
            ->dailyAt('00:05')
            ->name('availability-reset-stale-quota')
            ->withoutOverlapping()
            ->onOneServer();

        // [W8.C-P2 / P-MEGA-22 Pilier 2] NF525 fiscal archive scheduling
        // D4=A 02:00 quotidien ; D5=A toutes branches actives ; D6=A local + S3 nightly géré par command env ; D7=A ZIP+JSON géré par command
        $schedule->call(function () {
            $yesterday = now()->subDay()->format('Y-m-d');
            try {
                Branch::query()
                    ->where('status', 1)
                    ->whereNull('deleted_at')
                    ->pluck('id')
                    ->each(function ($branchId) use ($yesterday) {
                        $exit = Artisan::call('foodking:fiscal:archive', [
                            'branch_id' => (int) $branchId,
                            '--from'    => $yesterday,
                            '--to'      => $yesterday,
                        ]);
                        if ($exit !== 0) {
                            Log::channel('fiscal')->warning('NF525 daily archive non-zero exit', [
                                'event'     => 'fiscal.archive.daily.partial_failure',
                                'branch_id' => (int) $branchId,
                                'date'      => $yesterday,
                                'exit_code' => $exit,
                            ]);
                        }
                    });
            } catch (\Throwable $e) {
                Log::channel('fiscal')->error('NF525 daily archive scheduler crashed', [
                    'event'   => 'fiscal.archive.daily.scheduler_error',
                    'message' => $e->getMessage(),
                    'trace'   => substr($e->getTraceAsString(), 0, 1000),
                ]);
            }
        })
            ->dailyAt('02:00')
            ->name('foodking-fiscal-archive-daily')
            ->withoutOverlapping()
            ->onOneServer();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
