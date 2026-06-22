<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * [OPS-2 2026-06-04] storage:cleanup — bounded-disk maintenance.
 *
 * The box hit 100% disk twice. Root causes: an unbounded `laravel.log`
 * (default channel was the `single` driver — no rotation ceiling) and a
 * `storage/debugbar` directory that grew to hundreds of MB because debug
 * request snapshots were persisted. There was NO command to reclaim it.
 *
 * This command purges, in order of disk impact:
 *   1. storage/debugbar/*           — debug request snapshots (always safe).
 *   2. storage/logs/laravel*.log    — only files older than --days.
 *   3. storage/framework/cache/data — application cache files (rebuilt lazily).
 *   4. storage/framework/views/*    — compiled Blade views (rebuilt lazily).
 *
 * It is wired into the central scheduler by the orchestrator (this command
 * deliberately does NOT self-schedule; see app/Console/Kernel.php).
 *
 * ─────────────────────────── NF525 SAFETY ───────────────────────────
 * The log purge is SCOPED to `laravel*.log` ONLY. It must NEVER touch the
 * fiscal/security/observability/hardware channels — those carry legally
 * mandated audit evidence with their own long retentions (fiscal=400d,
 * security/observability=90d, hardware=30d) that Monolog's `daily` driver
 * self-prunes. Deleting `fiscal.log` would destroy NF525 evidence.
 *
 * It also NEVER deletes:
 *   - any `.gitignore` (keeps the dirs tracked).
 *   - `storage/framework/sessions/*` (would log every user out mid-shift).
 */
class StorageCleanupCommand extends Command
{
    protected $signature = 'storage:cleanup
        {--days=14 : Delete laravel*.log files older than this many days}
        {--dry-run : Report what WOULD be deleted without deleting}
        {--json : Emit a machine-readable JSON summary}';

    protected $description = 'Purge debugbar snapshots, stale laravel*.log files, and rebuildable framework caches to keep disk bounded.';

    /** Channels whose logs are legally retained and MUST NOT be purged here. */
    private const PROTECTED_LOG_PREFIXES = ['fiscal', 'security', 'observability', 'hardware'];

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days)->getTimestamp();

        $report = [
            'dry_run'         => $dryRun,
            'days'            => $days,
            'debugbar'        => $this->purgeDebugbar($dryRun),
            'laravel_logs'    => $this->purgeLaravelLogs($cutoff, $dryRun),
            'framework_cache' => $this->purgeDir(storage_path('framework/cache/data'), $dryRun),
            'compiled_views'  => $this->purgeCompiledViews($dryRun),
        ];

        $report['total_files']      = (int) collect($report)
            ->filter(fn ($v) => is_array($v))
            ->sum(fn ($v) => $v['files'] ?? 0);
        $report['total_bytes']      = (int) collect($report)
            ->filter(fn ($v) => is_array($v))
            ->sum(fn ($v) => $v['bytes'] ?? 0);
        $report['total_human']      = $this->humanBytes($report['total_bytes']);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $verb = $dryRun ? 'Would reclaim' : 'Reclaimed';
        $this->info("storage:cleanup — {$verb} {$report['total_files']} file(s), {$report['total_human']}.");
        $this->line('  debugbar       : ' . $this->summary($report['debugbar']));
        $this->line('  laravel*.log   : ' . $this->summary($report['laravel_logs']) . " (>{$days}d)");
        $this->line('  framework cache: ' . $this->summary($report['framework_cache']));
        $this->line('  compiled views : ' . $this->summary($report['compiled_views']));

        return self::SUCCESS;
    }

    /**
     * Purge every file under storage/debugbar except .gitignore.
     *
     * @return array{files:int, bytes:int}
     */
    private function purgeDebugbar(bool $dryRun): array
    {
        return $this->purgeDir(storage_path('debugbar'), $dryRun);
    }

    /**
     * Purge ONLY laravel*.log files older than the cutoff. Protected fiscal /
     * security / observability / hardware channels are explicitly skipped.
     *
     * @return array{files:int, bytes:int}
     */
    private function purgeLaravelLogs(int $cutoff, bool $dryRun): array
    {
        $dir = storage_path('logs');
        $files = 0;
        $bytes = 0;

        if (! File::isDirectory($dir)) {
            return ['files' => 0, 'bytes' => 0];
        }

        foreach (File::files($dir) as $file) {
            $name = $file->getFilename();

            // Only the application's own laravel*.log (e.g. laravel.log,
            // laravel-2026-06-04.log). Never anything else in the dir.
            if (! preg_match('/^laravel[-.].*\.log$|^laravel\.log$/', $name)) {
                continue;
            }

            // Defence-in-depth: never delete a protected channel even if a
            // future rename collides with the laravel* glob.
            if ($this->isProtectedLog($name)) {
                continue;
            }

            if ($file->getMTime() >= $cutoff) {
                continue;
            }

            $size = $file->getSize();
            if (! $dryRun) {
                @unlink($file->getPathname());
            }
            $files++;
            $bytes += $size;
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    private function isProtectedLog(string $name): bool
    {
        foreach (self::PROTECTED_LOG_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compiled Blade views live as *.php files at the top of framework/views.
     * Rebuilt lazily on next render. .gitignore is preserved.
     *
     * @return array{files:int, bytes:int}
     */
    private function purgeCompiledViews(bool $dryRun): array
    {
        return $this->purgeDir(storage_path('framework/views'), $dryRun, ['gitignore']);
    }

    /**
     * Recursively delete the CONTENTS of $dir (files + subdirs), preserving
     * the directory itself and any file named .gitignore (or extra skips).
     *
     * @param  array<int,string>  $skipExtensions  e.g. ['gitignore']
     * @return array{files:int, bytes:int}
     */
    private function purgeDir(string $dir, bool $dryRun, array $skipExtensions = []): array
    {
        $files = 0;
        $bytes = 0;

        if (! File::isDirectory($dir)) {
            return ['files' => 0, 'bytes' => 0];
        }

        foreach (File::allFiles($dir, true) as $file) {
            $name = $file->getFilename();

            if ($name === '.gitignore') {
                continue;
            }
            if (in_array($file->getExtension(), $skipExtensions, true)) {
                continue;
            }

            $size = $file->getSize();
            if (! $dryRun) {
                @unlink($file->getPathname());
            }
            $files++;
            $bytes += $size;
        }

        // Remove now-empty subdirectories (but keep the root $dir).
        if (! $dryRun) {
            foreach (array_reverse(File::directories($dir)) as $sub) {
                $this->removeIfEmpty($sub);
            }
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    private function removeIfEmpty(string $dir): void
    {
        foreach (File::directories($dir) as $sub) {
            $this->removeIfEmpty($sub);
        }
        if (count(File::allFiles($dir, true)) === 0 && count(File::directories($dir)) === 0) {
            @rmdir($dir);
        }
    }

    /** @param array{files:int, bytes:int} $r */
    private function summary(array $r): string
    {
        return "{$r['files']} file(s), " . $this->humanBytes($r['bytes']);
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $value, $units[$i]);
    }
}
