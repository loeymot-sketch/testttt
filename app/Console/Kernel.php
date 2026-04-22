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

        $schedule->job(new CleanupStalePendingKioskOrders())
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->job(new SloEvaluatorJob())
            ->everyFiveMinutes()
            ->withoutOverlapping(5)
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
