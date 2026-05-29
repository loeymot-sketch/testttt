<?php

namespace App\Console;

use App\Enums\Status;
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

        // [Sprint H3 P1-Z8-02 2026-05-17] Webhook DLQ — re-run failed
        // webhook_events whose provider retry window expired. Hourly
        // cadence matches outbox:retry-failed (operationally consistent).
        // Wave Z 5C scheduled this command but it didn't exist — the
        // implementation lands in this sprint. See CLAUDE.md §9.
        $schedule->command('foodking:webhook:retry-failed --since=24h')
            ->hourly()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('webhook-retry-failed')
            ->description('Retry failed webhook_events (Stripe/SenangPay) within last 24h');

        // [GOAL-2026-05-29 V3 / NF525 P0 #1 detect-only] Daily Z-membership
        // reconciliation: the read-only fiscal:verify-z-membership detector flags any
        // fiscally-numbered order at risk of appearing in NO signed Z (cross-Z-window
        // settlement orphan — a numbered receipt absent from every Z = NF525 gap-free
        // risk). The command exits non-zero on candidates; the onFailure hook raises a
        // pageable Log::error. The full cross-window policy (reject-late vs
        // counter-entry) remains an owner decision, but the orphan can no longer go
        // unsurfaced. Read-only — no writes, no fiscal mutation.
        $schedule->command('fiscal:verify-z-membership')
            ->dailyAt('06:05')
            ->withoutOverlapping()
            ->onOneServer()
            ->name('fiscal-z-membership')
            ->description('NF525: alarm if any numbered order is absent from every signed Z (cross-Z-window orphan).')
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error(
                    '[fiscal:verify-z-membership] cross-Z-window orphan candidate(s) detected — '
                    . 'a numbered receipt may be absent from every signed Z (NF525 gap-free risk). '
                    . 'Investigate before close. See reports/audit/massive-validation-2026-05-29/ESCALATION_NO_GO.md (P0 #1).'
                );
            });

        $schedule->job(new CleanupStalePendingKioskOrders())
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        // [GAP-HUNT 2026-05-25 Phase A.1 / OPS-GATE-1]
        // Passive heartbeat lane. `healthz:check` mirrors `GET /api/healthz`
        // and writes one line every 5 minutes to storage/logs/heartbeat.log.
        // External uptime probe (UptimeRobot) is the primary paging path;
        // this lane is the on-host fallback the owner can `tail -f` when
        // debugging a cron lag with no internet. Exit 0 (ok+degraded) /
        // 1 (fail) — matches command contract. See
        // scripts/deploy/UPTIMEROBOT_SETUP.md for the external monitor
        // setup instructions.
        $schedule->command('healthz:check')
            ->everyFiveMinutes()
            ->name('healthz-heartbeat')
            ->description('OPS-GATE-1: 5-min heartbeat → storage/logs/heartbeat.log')
            ->appendOutputTo(storage_path('logs/heartbeat.log'))
            ->onOneServer()
            ->withoutOverlapping();

        // [MEGA 2.G / F-11] Purge POS parked-order snapshots older than 24h (TTL).
        $schedule->command('pos:purge-parked-orders --older-than-hours=24')
            ->dailyAt('03:15')
            ->withoutOverlapping()
            ->onOneServer();

        // [Q14 BDD auto-backup — owner decision 2026-05-21]
        // NF525-compliant daily DB backup with 30d daily + 12mo monthly + 24q
        // quarterly (6y) retention. Runs at 03:00 (before purge-parked-orders
        // at 03:15 and fiscal-chain-monitor at 03:30, so the dump captures a
        // clean pre-housekeeping state). Output: storage/backups/db-{daily,
        // monthly,quarterly}/. Companion of `fiscal:archive` (02:00) which
        // produces the per-day signed ZIP+JSON archive — this lane is the
        // full-DB safety net, fiscal:archive is the fiscal-specific lane.
        // See app/Console/Commands/Backup/RunDailyBackup.php docblock.
        // withoutOverlapping prevents double-execution if a previous run hangs;
        // onOneServer mirrors all other daily lanes in this file.
        $schedule->command('foodking:backup-daily')
            ->dailyAt('03:00')
            ->name('foodking-backup-daily')
            ->description('NF525 daily DB backup with multi-tier 6y retention')
            ->withoutOverlapping(60)
            ->onOneServer();

        // [RED-team P0 / Outbox unbounded growth — 2026-05-17]
        // domain_events grows unbounded without this prune. NF525 6y retention
        // applies to audit_logs + z_reports ONLY (CLAUDE.md §8), NOT to this
        // operational outbox. 90d default = far past the staleness monitor /
        // retry-failed window (24h), so any row matched here is provably
        // terminal. Daily cadence at 04:00 (off-peak, after fiscal archive
        // 02:00). Mutex + onOneServer mirror the outbox:rescue/monitor lanes.
        $schedule->command('foodking:outbox:prune --older-than-days=90')
            ->dailyAt('04:00')
            ->name('outbox-prune')
            ->description('Prune dispatched + terminally-failed domain_events older than 90d')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();

        // [RED-team P0 / webhook_events unbounded growth — 2026-05-17]
        // Mirror of outbox:prune for the payment-provider webhook ledger.
        // Only processed + duplicate rows are eligible; pending/failed are
        // owned by the DLQ retry lane (foodking:webhook:retry-failed). 04:15
        // staggers the lock window vs outbox:prune.
        // [P1 V1 Cloud-Prep insights 2026-05-18] 90d → 180d bump matches the
        // command default (PCI dispute lookback window). See command class
        // PruneWebhookEventsCommand docblock for rationale.
        $schedule->command('foodking:webhook:prune --older-than-days=180')
            ->dailyAt('04:15')
            ->name('webhook-prune')
            ->description('Prune processed + duplicate webhook_events older than 180d (PCI dispute window)')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();

        // [GOAL-I2-HEAL-04 2026-05-24] Phase I.7 R6 P2: vendor command
        // sanctum:prune-expired was NEVER scheduled. Compound risk: relogin-
        // revoke only touches the active token name, so expired rows of all
        // OTHER token names accumulate forever. Over NF525 6-year horizon =
        // storage bloat.
        //
        // Schedule daily 04:30 (after backup 03:00, archive 02:00, chain
        // monitor 03:30, parked-orders purge 03:15, outbox-prune 04:00,
        // webhook-prune 04:15 — see CRONTAB_PROD.md). Retention --hours=24
        // = keep expired-tokens for 24h grace period before delete (allows
        // forensic if needed).
        $schedule->command('sanctum:prune-expired', ['--hours=24'])
            ->dailyAt('04:30')
            ->timezone('Europe/Paris')
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/sanctum-prune-expired.log'));

        // [GOAL-K2-HEAL-05 2026-05-24] Phase K.8 K8-F-01 P1.
        //
        // Stripe `charge.succeeded` webhook stages CapturePaymentNotification
        // and immediately returns 200; Order.payment_status flip is deferred
        // to Stripe::success which only runs when the customer browser visits
        // payment.success. Browser death (kiosk crash, customer walks away,
        // network drop) leaves a stranded CPN + Stripe-charged-but-order-UNPAID
        // with NO recovery path — the DLQ retry lane re-fires the webhook
        // handler which is a no-op (CPN already exists), so the order sits
        // forever PENDING.
        //
        // Every 5 min covers the next kiosk-restart cycle without thrashing
        // legitimate browser flows (--older-than-minutes=5 means an in-flight
        // browser redirect has at least 5 minutes to complete before we drain).
        // Europe/Paris for parity with the NF525 quartet lanes (#8/#15/#16/#17).
        // onOneServer prevents cross-host double-drain when V2 scales out;
        // withoutOverlapping prevents same-host re-entry if a previous run
        // hangs on a slow Stripe API call. runInBackground + appendOutputTo
        // keep stdout from polluting schedule.log.
        $schedule->command('stripe:drain-stranded-cpn', ['--older-than-minutes=5'])
            ->everyFiveMinutes()
            ->timezone('Europe/Paris')
            ->name('stripe-drain-stranded-cpn')
            ->description('Drain stranded Stripe CPN rows whose browser never flushed payment_status (K.8 K8-F-01 P1)')
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/stripe-drain-cpn.log'));

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

        // [Wave 3 P1 FISCAL-ADV3-03 2026-05-18 / Wave 3b FISCAL-ADV3B-01]
        // NF525 dual-chain monitor — re-walks audit_logs + z_reports
        // HMAC chains daily for EVERY active branch (not just branch=1).
        //
        // Wave 3b FISCAL-ADV3B-01: the previous single-branch cron
        // (`--branch=1` hardcoded) left branches >=2 silently unmonitored,
        // asymmetric with `fiscal:archive` (below) which iterates active
        // branches. Pattern now uses self::activeBranchIds() — see method
        // PHPDoc for status-drift rationale (Wave 2d FISCAL-ADV3C-01).
        //
        // 03:30 staggers between fiscal:archive (02:00) and outbox-prune
        // (04:00). onOneServer prevents duplicate runs at scale.
        $schedule->call(function () {
            try {
                self::activeBranchIds()
                    ->each(function ($branchId) {
                        try {
                            $exit = Artisan::call('fiscal:verify-chain', [
                                '--branch' => (int) $branchId,
                            ]);
                            if ($exit !== 0) {
                                Log::channel('fiscal')->error('NF525 chain verify non-zero exit', [
                                    'event'     => 'fiscal.chain.monitor.failure',
                                    'branch_id' => (int) $branchId,
                                    'exit_code' => $exit,
                                ]);
                            }
                        } catch (\Throwable $e) {
                            Log::channel('fiscal')->error('NF525 chain verify branch crashed', [
                                'event'     => 'fiscal.chain.monitor.branch_error',
                                'branch_id' => (int) $branchId,
                                'message'   => $e->getMessage(),
                            ]);
                        }
                    });
            } catch (\Throwable $e) {
                Log::channel('fiscal')->error('NF525 chain verify scheduler crashed', [
                    'event'   => 'fiscal.chain.monitor.scheduler_error',
                    'message' => $e->getMessage(),
                    'trace'   => substr($e->getTraceAsString(), 0, 1000),
                ]);
            }
        })
            ->dailyAt('03:30')
            ->name('fiscal-chain-monitor-all-branches')
            ->withoutOverlapping()
            ->onOneServer();

        // [W8.C-P2 / P-MEGA-22 Pilier 2] NF525 fiscal archive scheduling
        // D4=A 02:00 quotidien ; D5=A toutes branches actives ; D6=A local + S3 nightly géré par command env ; D7=A ZIP+JSON géré par command
        // [Wave 2d FISCAL-ADV3C-01 2026-05-18] Mirror of fiscal-chain-monitor:
        // both schedulers now share self::activeBranchIds() so a future
        // status-data migration (status=1 → status=5) does not silently
        // no-op either cron lane.
        $schedule->call(function () {
            $yesterday = now()->subDay()->format('Y-m-d');
            try {
                self::activeBranchIds()
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

        // [GOAL-G2-HEAL-06 2026-05-23] NF525 Z-close safety-net at 23:55 Paris.
        //
        // Phase G.6 audit (P1 operational) caught a cumulative gap: Z-close
        // has NO production trigger today — no UI button (V1.0.X owner-gate
        // proposal), no cron, no documented runbook. F.10 verified the
        // SERVICE-LAYER invariants (13/13 GREEN) but the operational layer
        // was missing — an owner forgetting to run the close every night
        // would silently leak transactions into the next business_date.
        //
        // 23:55 Paris = after the cashier's effective shift end (most
        // restaurants close by 23:00) but BEFORE midnight, so the close
        // happens on the SAME business_date as the transactions — required
        // by NF525 same-day semantics. Staggered after lane #16 (fiscal
        // archive 02:00) and #15 (chain monitor 03:30) so the archive of
        // J-1 always sees a closed Z (no half-open chain in archives).
        //
        // Double-close-safe: the command does a STATUS_OPEN pre-check per
        // branch and `info`-logs (not error) when no open Z exists. So a
        // cashier who already manually closed earlier in the evening sees
        // no false-alarm pager from this cron. Per-branch isolation: one
        // branch crash never halts the rest. Mirrors fiscal-chain-monitor
        // pattern (this file, lane #15 above).
        //
        // ZReportService FROZEN §7 — this lane only CALLS service.close(),
        // it does not modify the service. Frozen-zone diff = 0.
        // [GAP-HUNT 2026-05-25 PROPOSAL-Z-LOOP-GAP Path A] compress dead zone
        // 10 min → 10 sec. Was 23:55 close + 00:05 open (10 min orphan window
        // where fiscal_sequence_no could land in no Z). Now 23:59:55 close +
        // 00:00:05 open = ~10s residual window only (orphan risk reduced
        // ~99.97%). Path B (business_date SSOT) deferred V1.0.X with LOCK.
        $schedule->command('fiscal:close-all-active-branches')
            ->dailyAt('23:59')
            ->timezone('Europe/Paris')
            ->name('foodking-z-close-safety-net')
            ->description('NF525 Z-close safety-net per active branch (before midnight Paris)')
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/fiscal-close-safety-net.log'));

        // [GOAL-L2-HEAL-07 2026-05-24] NF525 Z-OPEN safety-net at 00:05 Paris.
        //
        // Phase L11.1 P0-03 + K.7 FIND-1 (cron miss recovery audit) confirmed
        // a missing companion to lane #17 above: G2-HEAL-06 added the
        // Z-CLOSE safety-net at 23:55 Paris but no Z-OPEN cron. If a cashier
        // never manually opens the next Z after the midnight close, every
        // business day after that = silent skip = the Z chain is no longer
        // extended. NF525 segregation breaks (any transaction recorded after
        // the silent close has nowhere to land except via the
        // `fiscal_alloc_error_at` flag + retry cron, which is a degraded
        // path that owners must monitor by hand).
        //
        // 00:05 Paris = a few minutes AFTER the 23:55 close so the new
        // business_date starts with an OPEN Z ready to absorb morning-shift
        // transactions. The 10-minute gap also leaves room for the close
        // command to fully finish (Cache::lock + chain verify + audit
        // logging are all O(seconds), well under 10 min). Same timezone
        // as the close lane to keep operational/audit semantics aligned.
        //
        // Idempotent: the command does a STATUS_OPEN pre-check per branch
        // and `info`-logs (not error) when a Z is already open. So a
        // cashier who opened manually for the early shift never triggers
        // a false-alarm pager. Per-branch isolation: one branch crash
        // never halts the rest. Mirrors lane #17 G2-HEAL-06 pattern
        // exactly so the close + open pair stays symmetric.
        //
        // ZReportService FROZEN §7 — this lane only CALLS service.open(),
        // it does not modify the service. Frozen-zone diff = 0.
        //
        // Loop: 23:55 close (lane #17) + 00:05 open (this lane) = continuous
        // Z chain extension even if the cashier is absent (V1 LOCAL Le
        // Cayenne operational floor until the optional UI button ships).
        // [GAP-HUNT 2026-05-25 PROPOSAL-Z-LOOP-GAP Path A] open at 00:01 Paris
        // (was 00:05). With close at 23:59 Paris, the orphan-window shrinks
        // ~10 min → ~2 min residual. Path B (business_date SSOT) deferred.
        $schedule->command('fiscal:open-all-active-branches')
            ->dailyAt('00:01')
            ->timezone('Europe/Paris')
            ->name('foodking-z-open-safety-net')
            ->description('NF525 Z-open safety-net per active branch (just after midnight Paris)')
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/fiscal-open-safety-net.log'));
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

    /**
     * [Wave 2d FISCAL-ADV3C-01 2026-05-18]
     *
     * Canonical "active branch" lookup for fiscal cron lanes.
     *
     * The branches table straddles two "active" sentinels in this codebase:
     *  - legacy literal `1` (BranchFactory, prod seed pre-enum)
     *  - canonical `App\Enums\Status::ACTIVE = 5` (BranchService, controllers)
     *
     * The owner-flagged data migration `UPDATE branches SET status=5
     * WHERE status=1` is pending. The previous `where('status', 1)`
     * pattern in both fiscal schedulers would have silently no-op'd
     * every NF525 chain monitor + daily archive once that migration
     * runs — recreating the exact "silent skip" class FISCAL-ADV3B-01
     * was opened to close.
     *
     * `whereIn` accepts both literals so we're safe pre- and post-migration.
     * Pattern mirrors PersistCatalogChangedToOutbox.php:39.
     *
     * Static + Collection-typed return so a future test can substitute
     * a fake Branch model and assert iteration semantics without
     * touching the scheduler closure (which Laravel does not run from
     * Schedule::events() in a unit-testable way).
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public static function activeBranchIds(): \Illuminate\Support\Collection
    {
        return Branch::query()
            ->whereIn('status', [Status::ACTIVE, 1])
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);
    }
}
