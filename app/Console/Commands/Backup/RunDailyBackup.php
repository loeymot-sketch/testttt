<?php

namespace App\Console\Commands\Backup;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * [Q14 BDD auto-backup — owner decision 2026-05-21]
 *
 * NF525-compliant daily DB backup with multi-tier retention.
 *
 * ## Why this is a Laravel command (not a pure cron of backup.sh)
 *
 * The existing `scripts/db/backup.sh` is the **pre-migration** safety net
 * (called by `scripts/db/rehearsal.sh`, fail-closed, requires
 * `--i-understand-backup`, writes timestamped artifacts + manifest to
 * `storage/app/migration-backups/`). That tool is intentionally manual
 * and stays untouched. This command is the **automated daily lane** with
 * its own naming, dir, rotation, and sanity checks — a different concern.
 *
 * ## Retention strategy
 *
 *   storage/backups/db-daily/daily-YYYY-MM-DD.sql.gz      — 30 days
 *   storage/backups/db-monthly/monthly-YYYY-MM.sql.gz     — 12 months (copied on day-1)
 *   storage/backups/db-quarterly/quarterly-YYYY-QN.sql.gz — 24 quarters (copied on Jan/Apr/Jul/Oct day-1)
 *
 * Quarterly × 24 = 72 months = 6 years. This satisfies NF525 fiscal
 * retention (audit_logs + z_reports, see CLAUDE.md §8) at the DB-dump
 * level. fiscal:archive (Kernel.php:216) is the per-day signed ZIP+JSON
 * archive — separate, complementary, not replaced by this command.
 *
 * ## Frozen-zone discipline (CLAUDE.md §7)
 *
 * This command READS the entire DB via `mysqldump --single-transaction`
 * (snapshot-consistent, no locks on InnoDB). It NEVER writes to
 * audit_logs / z_reports / migrations. The DB triggers BEFORE DELETE
 * on audit_logs + z_reports remain in effect — mysqldump SELECT-only.
 *
 * ## Atomicity
 *
 * mysqldump writes to `<final>.partial`; on success we `rename()` to
 * `<final>`. A crash mid-dump leaves only a `.partial` (cleaned up on
 * next run via prepareDailyTarget()), never a corrupted "valid-named"
 * file that fools the rotation logic.
 *
 * ## Failure marker
 *
 * On any failure path we write `storage/backups/.last-failure` with a
 * timestamped reason. Monitoring (existing fiscal observability lane
 * or a future ops tool) tails this. We also Log::channel('observability').
 *
 * ## Sanity threshold
 *
 * Hard fail if final gzip < 1 KB (clear truncation). Soft warning if
 * < 1 MB (suspicious for a real restaurant DB but plausible on dev /
 * fresh local). Threshold overridable via --min-bytes for tests.
 *
 * ## sqlite fallback
 *
 * If config('database.default')==='sqlite' (CI / some dev installs),
 * we cp+gzip the .sqlite file instead. The rotation logic is identical.
 *
 * @see scripts/db/CRONTAB_SETUP.md       — owner setup
 * @see scripts/db/RESTORE_DRILL_2026-05-21.md — proof of restorability
 */
class RunDailyBackup extends Command
{
    protected $signature = 'foodking:backup-daily'
        . ' {--min-bytes=1024 : Hard-fail floor for the final gzip size (sanity check)}'
        . ' {--warn-bytes=1048576 : Soft-warn floor (logs but does not fail)}'
        . ' {--dry-run : Print plan + paths without executing mysqldump}'
        . ' {--no-rotate : Skip monthly/quarterly snapshot + cleanup steps}'
        . ' {--keep-daily=30 : Days of daily backups to retain}'
        . ' {--keep-monthly=12 : Months of monthly archives to retain}'
        . ' {--keep-quarterly=24 : Quarters of quarterly archives to retain (24 = NF525 6y)}';

    protected $description = 'NF525-compliant daily DB backup with 30d daily + 12mo monthly + 24q quarterly retention.';

    /** @var string */
    private $backupsRoot;

    /** @var string */
    private $failureMarker;

    public function handle(): int
    {
        $this->backupsRoot = storage_path('backups');
        $this->failureMarker = $this->backupsRoot . DIRECTORY_SEPARATOR . '.last-failure';

        $dryRun = (bool) $this->option('dry-run');
        $minBytes = max(0, (int) $this->option('min-bytes'));
        $warnBytes = max($minBytes, (int) $this->option('warn-bytes'));

        $driver = (string) config('database.default');
        $now = Carbon::now(config('app.timezone'));

        $dailyDir = $this->backupsRoot . DIRECTORY_SEPARATOR . 'db-daily';
        $monthlyDir = $this->backupsRoot . DIRECTORY_SEPARATOR . 'db-monthly';
        $quarterlyDir = $this->backupsRoot . DIRECTORY_SEPARATOR . 'db-quarterly';

        foreach ([$dailyDir, $monthlyDir, $quarterlyDir] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return $this->reportFailure("cannot create backup directory: {$dir}", 2);
            }
        }

        $dateStamp = $now->format('Y-m-d');
        $dailyTarget = $dailyDir . DIRECTORY_SEPARATOR . "daily-{$dateStamp}.sql.gz";
        $partialTarget = $dailyTarget . '.partial';

        $this->info(sprintf('[backup-daily] driver=%s target=%s', $driver, basename($dailyTarget)));

        if (file_exists($dailyTarget) && !$dryRun) {
            // Already ran today. Idempotent: log + continue to rotation (which is also idempotent).
            $this->warn("Today's backup already exists: {$dailyTarget}. Re-running rotation only.");
            Log::channel('observability')->info('backup.daily.skip_existing', [
                'event' => 'backup.daily.skip_existing',
                'file' => $dailyTarget,
                'size_bytes' => @filesize($dailyTarget) ?: 0,
            ]);
        } else {
            // Clean any orphan .partial from a previous crash.
            if (file_exists($partialTarget)) {
                @unlink($partialTarget);
            }

            if ($dryRun) {
                $this->line(sprintf(
                    '[dry-run] would dump %s → %s',
                    $driver === 'sqlite' ? 'sqlite file' : 'mysqldump',
                    $dailyTarget
                ));
            } else {
                $result = $driver === 'sqlite'
                    ? $this->dumpSqlite($partialTarget)
                    : $this->dumpMysql($partialTarget);

                if ($result !== 0) {
                    @unlink($partialTarget);
                    return $this->reportFailure(
                        "dump exit code {$result} (driver={$driver})",
                        $result
                    );
                }

                if (!file_exists($partialTarget)) {
                    return $this->reportFailure('dump produced no output file', 3);
                }

                $bytes = (int) filesize($partialTarget);
                if ($bytes < $minBytes) {
                    @unlink($partialTarget);
                    return $this->reportFailure(
                        sprintf('dump too small: %d bytes < min %d (truncation suspected)', $bytes, $minBytes),
                        4
                    );
                }

                if ($bytes < $warnBytes) {
                    $this->warn(sprintf(
                        'dump under warn-bytes threshold (%d < %d) — plausible on fresh local DB but verify',
                        $bytes,
                        $warnBytes
                    ));
                    Log::channel('observability')->warning('backup.daily.size_low', [
                        'event' => 'backup.daily.size_low',
                        'size_bytes' => $bytes,
                        'warn_bytes' => $warnBytes,
                    ]);
                }

                // Atomic publish: only now does the "real" filename exist.
                if (!@rename($partialTarget, $dailyTarget)) {
                    @unlink($partialTarget);
                    return $this->reportFailure("atomic rename failed: {$partialTarget} → {$dailyTarget}", 5);
                }

                Log::channel('observability')->info('backup.daily.ok', [
                    'event' => 'backup.daily.ok',
                    'file' => $dailyTarget,
                    'size_bytes' => $bytes,
                    'driver' => $driver,
                ]);
                $this->info(sprintf('OK — wrote %s (%d bytes)', basename($dailyTarget), $bytes));
            }
        }

        if (!$this->option('no-rotate') && !$dryRun) {
            $this->rotate($now, $dailyDir, $monthlyDir, $quarterlyDir);
        } else {
            $this->line('[rotation skipped]');
        }

        // Clear the failure marker on a fully successful run.
        if (file_exists($this->failureMarker)) {
            @unlink($this->failureMarker);
        }

        return 0;
    }

    /**
     * mysqldump --single-transaction --routines --triggers --events
     * piped through gzip into the partial file.
     *
     * `--single-transaction` gives a snapshot-consistent dump on InnoDB
     * without locking writers — same flag the existing scripts/db/backup.sh
     * uses, so behavior is parity. We READ everything (including audit_logs
     * + z_reports) but never write; the DB DELETE-triggers on those tables
     * are irrelevant to SELECT-only access.
     *
     * Returns the process exit code (0 = success).
     */
    private function dumpMysql(string $partialTarget): int
    {
        $conn = config('database.connections.mysql');

        $database = (string) ($conn['database'] ?? '');
        if ($database === '') {
            $this->error('database name missing from config (database.connections.mysql.database)');

            return 64; // EX_USAGE
        }

        $host = (string) ($conn['host'] ?? '127.0.0.1');
        $port = (string) ($conn['port'] ?? '3306');
        $username = (string) ($conn['username'] ?? '');
        $password = (string) ($conn['password'] ?? '');

        // Build the mysqldump | gzip pipeline via sh -c so the pipe is honored.
        // Credentials go through MYSQL_PWD env (never argv) — matches the
        // existing backup.sh approach (scripts/db/backup.sh:169).
        //
        // --set-gtid-purged=OFF: drop the `SET @@GLOBAL.GTID_PURGED` line.
        // On a GTID-enabled server (MySQL 5.7+ default in many distros), the
        // restore target's GTID_EXECUTED collides with the dump's GTID_PURGED,
        // failing with ER_GTID_PURGED_CONTAINS_EXEC. We're producing a logical
        // backup for restore (NOT a replica seed); GTID provenance is not part
        // of the contract. Verified by the 2026-05-21 restore drill that
        // failed without this flag.
        $cmd = sprintf(
            'mysqldump --single-transaction --routines --triggers --events --set-gtid-purged=OFF --host=%s --port=%s --user=%s %s | gzip -c > %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($partialTarget)
        );

        $process = Process::fromShellCommandline($cmd);
        $process->setTimeout(3600); // 1h ceiling for a full DB dump
        $process->setEnv(['MYSQL_PWD' => $password]);

        try {
            $process->run(function ($type, $buffer): void {
                // Surface mysqldump warnings (sent to stderr) but never the
                // command line (which never contained the password anyway).
                if ($type === Process::ERR) {
                    foreach (preg_split("/\r?\n/", trim($buffer)) ?: [] as $line) {
                        if ($line !== '') {
                            $this->warn("[mysqldump] {$line}");
                        }
                    }
                }
            });
        } catch (ProcessTimedOutException $e) {
            $this->error('mysqldump timed out (>1h)');

            return 124;
        }

        return $process->getExitCode() ?? 1;
    }

    /**
     * sqlite path: copy the .sqlite file and gzip it. The Laravel queue / cache
     * may still hold a handle, but `cp` + gzip on the file works because
     * sqlite uses WAL by default. For full consistency we could `.dump` via
     * the sqlite3 binary; cp is what the existing backup.sh does
     * (scripts/db/backup.sh:150), keep parity.
     */
    private function dumpSqlite(string $partialTarget): int
    {
        $conn = config('database.connections.sqlite');
        $database = (string) ($conn['database'] ?? '');
        if ($database === '' || !file_exists($database)) {
            $this->error("sqlite database file not found: {$database}");

            return 66; // EX_NOINPUT
        }

        $cmd = sprintf('gzip -c %s > %s', escapeshellarg($database), escapeshellarg($partialTarget));
        $process = Process::fromShellCommandline($cmd);
        $process->setTimeout(900); // 15min — sqlite files rarely exceed a few hundred MB

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            $this->error('sqlite cp+gzip timed out');

            return 124;
        }

        return $process->getExitCode() ?? 1;
    }

    /**
     * Multi-tier rotation:
     *  - monthly snapshot on day-1 (copy today's daily → monthly-YYYY-MM.sql.gz)
     *  - quarterly snapshot on Jan/Apr/Jul/Oct day-1 (copy → quarterly-YYYY-QN.sql.gz)
     *  - cleanup: prune daily older than 30d / monthly older than 12mo / quarterly older than 24q
     *
     * Idempotent across runs (copy is a no-op if monthly/quarterly file already
     * exists for that period; cleanup uses time-bucket comparison).
     *
     * @param  \Illuminate\Support\Carbon  $now
     * @param  string  $dailyDir
     * @param  string  $monthlyDir
     * @param  string  $quarterlyDir
     */
    private function rotate(Carbon $now, string $dailyDir, string $monthlyDir, string $quarterlyDir): void
    {
        $dateStamp = $now->format('Y-m-d');
        $sourceDaily = $dailyDir . DIRECTORY_SEPARATOR . "daily-{$dateStamp}.sql.gz";

        // Day-1 of month → monthly snapshot.
        if ($now->day === 1 && file_exists($sourceDaily)) {
            $monthStamp = $now->format('Y-m');
            $monthlyTarget = $monthlyDir . DIRECTORY_SEPARATOR . "monthly-{$monthStamp}.sql.gz";
            if (!file_exists($monthlyTarget)) {
                if (@copy($sourceDaily, $monthlyTarget)) {
                    $this->info("rotated → {$monthlyTarget}");
                    Log::channel('observability')->info('backup.monthly.created', [
                        'event' => 'backup.monthly.created',
                        'file' => $monthlyTarget,
                    ]);
                } else {
                    $this->warn("monthly copy failed: {$monthlyTarget}");
                }
            }

            // Day-1 of quarter (Jan, Apr, Jul, Oct) → quarterly snapshot.
            if (in_array($now->month, [1, 4, 7, 10], true)) {
                $quarter = (int) ceil($now->month / 3);
                $quarterStamp = $now->format('Y') . '-Q' . $quarter;
                $quarterlyTarget = $quarterlyDir . DIRECTORY_SEPARATOR . "quarterly-{$quarterStamp}.sql.gz";
                if (!file_exists($quarterlyTarget)) {
                    if (@copy($sourceDaily, $quarterlyTarget)) {
                        $this->info("rotated → {$quarterlyTarget}");
                        Log::channel('observability')->info('backup.quarterly.created', [
                            'event' => 'backup.quarterly.created',
                            'file' => $quarterlyTarget,
                        ]);
                    } else {
                        $this->warn("quarterly copy failed: {$quarterlyTarget}");
                    }
                }
            }
        }

        $this->pruneOlderThan(
            $dailyDir,
            'daily-*.sql.gz',
            $now->copy()->subDays((int) $this->option('keep-daily'))
        );
        $this->pruneOlderThan(
            $monthlyDir,
            'monthly-*.sql.gz',
            $now->copy()->subMonths((int) $this->option('keep-monthly'))
        );
        $this->pruneOlderThan(
            $quarterlyDir,
            'quarterly-*.sql.gz',
            $now->copy()->subMonths(3 * (int) $this->option('keep-quarterly'))
        );

        // Clean any orphan .partial files in daily dir (crash recovery).
        foreach (glob($dailyDir . DIRECTORY_SEPARATOR . '*.partial') ?: [] as $orphan) {
            if (filemtime($orphan) < $now->copy()->subDay()->timestamp) {
                @unlink($orphan);
                $this->warn("cleaned orphan partial: " . basename($orphan));
            }
        }
    }

    /**
     * Delete files matching $pattern in $dir whose mtime is older than $cutoff.
     * Uses filesystem mtime (not filename date) so it's robust to clock drift.
     */
    private function pruneOlderThan(string $dir, string $pattern, Carbon $cutoff): void
    {
        $cutoffTs = $cutoff->timestamp;
        $matches = glob($dir . DIRECTORY_SEPARATOR . $pattern) ?: [];
        $pruned = 0;
        foreach ($matches as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoffTs) {
                if (@unlink($file)) {
                    $pruned++;
                }
            }
        }
        if ($pruned > 0) {
            $this->info(sprintf('pruned %d file(s) from %s', $pruned, basename($dir)));
            Log::channel('observability')->info('backup.prune.ok', [
                'event' => 'backup.prune.ok',
                'dir' => $dir,
                'pruned' => $pruned,
            ]);
        }
    }

    /**
     * Write the failure marker + log + return a non-zero exit code.
     * The marker is a tiny file monitoring can tail / alert on.
     */
    private function reportFailure(string $reason, int $exitCode): int
    {
        $payload = json_encode([
            'event' => 'backup.daily.failure',
            'timestamp' => Carbon::now()->toIso8601String(),
            'reason' => $reason,
            'exit_code' => $exitCode,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        @file_put_contents($this->failureMarker, $payload);
        $this->error($reason);
        Log::channel('observability')->error('backup.daily.failure', [
            'event' => 'backup.daily.failure',
            'reason' => $reason,
            'exit_code' => $exitCode,
        ]);

        return $exitCode === 0 ? 1 : $exitCode;
    }
}
