<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * [PRE-PROD HARDENING / POS-2 / P1-8]
 *
 * Force-close `cash_drawer_sessions` left open beyond a threshold (24h
 * by default) and emit a `Log::warning` per closure so ops can alert.
 *
 * **Why this exists.** F-003 (cash reconciliation) requires a cashier
 * to explicitly `close()` their drawer at end-of-shift, computing a
 * variance against expected_cash. If a cashier forgets, the session
 * stays `status=1 (open)` indefinitely and the day's Z report cannot
 * aggregate that drawer's variance, leaving cash physically un-reconciled
 * outside the Z window. NF525 + French accounting need a sealed daily
 * journal — silent open sessions break that invariant.
 *
 * **What this does NOT do.** It does not compute a real variance — the
 * actual cash count requires the cashier on premises. Instead, it
 * marks the session `status=3 (force_closed)` with `variance=null`
 * and `variance_reason="auto_close_stale_{N}h"`, which the Z report
 * aggregation must treat as "untrusted, surface to ops". The financial
 * reconciliation is then a manual ops follow-up, not a silent zero.
 *
 * **Frozen-zone discipline.** This command does NOT instantiate the
 * `CashDrawerSession` Eloquent model nor call `CashDrawerSessionService`
 * (both F-003 agent territory). It manipulates `cash_drawer_sessions`
 * via `DB::table()` only, which keeps the surface narrow: this branch
 * does not even have the table yet, but production cron will continue
 * to work after F-003 ships and the Eloquent layer lands.
 *
 * **Safety guards.**
 *  - `Schema::hasTable()` early-exit when the migration hasn't shipped
 *    yet (this branch right now). The cron prints a benign skip and
 *    returns SUCCESS, so the daily 3am tick doesn't crash supervisors.
 *  - `--dry-run` for ops to inspect what would close before scheduling.
 *  - `--threshold-hours` configurable; defaults to 24h, the operational
 *    window the audit (PRE_PROD_DEEP_RISK_AUDIT POS-2) defined.
 *  - Per-session try/catch so one bad row doesn't abort the batch; the
 *    failure is logged with the offending session id.
 */
class CloseStaleCashDrawerSessions extends Command
{
    /**
     * Status enum mirrors F-003 plan §2.1:
     *   1 = open, 2 = closed, 3 = force_closed
     * Kept as integers (no model constants reachable from this branch).
     */
    private const STATUS_OPEN = 1;
    private const STATUS_FORCE_CLOSED = 3;

    protected $signature = 'foodking:cash:close-stale-sessions
        {--threshold-hours=24 : Hours after which an open session is force-closed}
        {--dry-run : Show what would be done without persisting}';

    protected $description = 'Force-close cash drawer sessions left open beyond threshold (POS-2 / P1-8)';

    public function handle(): int
    {
        $thresholdHours = max(1, (int) $this->option('threshold-hours'));
        $dryRun = (bool) $this->option('dry-run');

        // Guard: F-003 migration may not have shipped on this environment yet.
        // Returning SUCCESS keeps the scheduled tick green; the table will
        // appear once the agent's migrations run.
        if (! Schema::hasTable('cash_drawer_sessions')) {
            $this->info('[SKIP] cash_drawer_sessions table not yet provisioned (F-003 not merged).');
            return self::SUCCESS;
        }

        $cutoff = now()->subHours($thresholdHours);

        $stale = DB::table('cash_drawer_sessions')
            ->where('status', self::STATUS_OPEN)
            ->where('opened_at', '<', $cutoff)
            ->get();

        if ($stale->isEmpty()) {
            $this->info("[OK] No stale cash drawer sessions (threshold {$thresholdHours}h).");
            return self::SUCCESS;
        }

        $closed = 0;
        foreach ($stale as $session) {
            if ($dryRun) {
                $this->warn("[DRY-RUN] Would force-close session #{$session->id} (branch {$session->branch_id}, cashier {$session->cashier_user_id}, opened {$session->opened_at})");
                continue;
            }

            try {
                DB::table('cash_drawer_sessions')
                    ->where('id', $session->id)
                    ->update([
                        'status' => self::STATUS_FORCE_CLOSED,
                        'closed_at' => now(),
                        // variance left NULL on purpose: actual cash count
                        // requires a cashier on premises, so we surface the
                        // unknown to ops rather than fabricate a zero.
                        'variance' => null,
                        'variance_reason' => "auto_close_stale_{$thresholdHours}h",
                        'updated_at' => now(),
                    ]);

                Log::warning('[CashDrawer] Force-closed stale session', [
                    'session_id' => $session->id,
                    'branch_id' => $session->branch_id,
                    'cashier_user_id' => $session->cashier_user_id,
                    'opened_at' => $session->opened_at,
                    'closed_after_hours' => $thresholdHours,
                ]);

                $closed++;
            } catch (\Throwable $e) {
                $this->error("[ERROR] Failed to close session #{$session->id}: {$e->getMessage()}");
                Log::error('[CashDrawer] Force-close failed', [
                    'session_id' => $session->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $msg = $dryRun
            ? "[DRY-RUN] Would close {$stale->count()} stale sessions"
            : "[CLOSED] Force-closed {$closed}/{$stale->count()} stale sessions";
        $this->info($msg);

        // Failure exit only when we tried and failed: dry-run is always success.
        if ($dryRun) {
            return self::SUCCESS;
        }

        return $closed === $stale->count() ? self::SUCCESS : self::FAILURE;
    }
}
