<?php

namespace App\Console;

use App\Jobs\CleanupStalePendingKioskOrders;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

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

        $schedule->command('foodking:outbox:rescue')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule->job(new CleanupStalePendingKioskOrders())
            ->everyFiveMinutes()
            ->withoutOverlapping();

        // [PRE-PROD HARDENING / SYN-2 / P0-7] Pusher delivery ratio alarm.
        // Compares dispatched envelopes vs. distinct client acks on a
        // sliding 5-minute window; logs an error + non-zero exit when
        // the ratio drops below 90% (clients silently missing events).
        $schedule->command('foodking:pusher:monitor')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        // [PRE-PROD HARDENING / POS-2 / P1-8] Force-close stale cash
        // drawer sessions. Restaurants close around 02:00; the 03:00
        // tick lands in the dead zone where no cashier should still be
        // legitimately open. Sessions still flagged status=open beyond
        // 24h are auto-flipped to status=force_closed with
        // variance=null and a reason tag, surfaced to ops via Log::warning.
        $schedule->command('foodking:cash:close-stale-sessions')
            ->dailyAt('03:00')
            ->withoutOverlapping();
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
