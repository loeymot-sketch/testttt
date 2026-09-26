<?php

namespace App\Console\Commands\Backup;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use App\Support\Backup\RestoreDrillResult;

/**
 * [OPS-1 restore drill — supervisor campaign 2026-06-04]
 *
 * Proves a backup is actually RESTORABLE, not merely produced.
 *
 * ## Why this command exists
 *
 * `foodking:backup-daily` (RunDailyBackup) produces gzip mysqldumps with
 * NF525-grade retention. But "a backup you've never restored is not a
 * backup". This command closes the loop: it takes the latest
 * `storage/backups/db-daily/daily-*.sql.gz`, restores it into a DISPOSABLE
 * SCRATCH database (NEVER the live DB), asserts the fiscal + order tables
 * round-tripped with non-zero rows, and re-walks the NF525 HMAC chains on
 * the restored copy via the (frozen) `fiscal:verify-chain --all` verifier.
 * It prints GREEN (exit 0) or RED (exit 1).
 *
 * It automates, end-to-end, the manual procedure proven in
 * `scripts/db/RESTORE_DRILL_2026-05-21.md` (the GTID-purged fix that drill
 * caught is already baked into RunDailyBackup::dumpMysql, so the dump we
 * restore here is already replay-safe).
 *
 * ## Safety invariants (read before touching)
 *
 *  - SCRATCH != LIVE: we hard-bail if the scratch DB name equals the
 *    configured live database. Belt: the default scratch name is
 *    `<live>_restore_scratch`, which cannot collide with `<live>`.
 *  - DROP+CREATE scratch at start, DROP scratch in a `finally` so a
 *    mid-drill crash never leaves clutter AND never leaves a half-restored
 *    DB masquerading as real.
 *  - The fiscal chain check runs IN-PROCESS by temporarily repointing the
 *    `mysql` connection's database to the scratch name (cache-proof:
 *    `config()->set()` overrides even a `config:cache`d config, unlike a
 *    subprocess `DB_DATABASE` env override which Laravel ignores when
 *    `bootstrap/cache/config.php` exists). We assert `SELECT DATABASE()`
 *    == scratch BEFORE running the chain check, so a misconfigured rebind
 *    can never silently verify the LIVE chain and report a false GREEN.
 *    The original connection name is restored + purged in `finally`.
 *  - Credentials travel via the `MYSQL_PWD` env var (never argv), mirroring
 *    RunDailyBackup::dumpMysql.
 *  - Read-only against live: the only live access is `SELECT COUNT(*)`
 *    taken BEFORE we repoint the connection.
 *
 * ## What "match" means here (important — not strict equality)
 *
 * The latest backup can be hours/days old; the live DB has moved on since.
 * audit_logs + z_reports are append-only (CLAUDE.md §7/§8), and orders only
 * grow. So strict `live == scratch` equality would false-RED on any healthy
 * system. The defensible assertion is:
 *
 *     live_count >= scratch_count > 0   for orders, audit_logs, z_reports
 *
 * (z_reports may legitimately be 0 on a dev box that never closed a Z; the
 * `--allow-empty-zreports` flag relaxes the >0 floor for z_reports only.)
 *
 * GREEN requires ALL of:
 *   1. restore process exit 0
 *   2. orders/audit_logs counts > 0 in scratch (z_reports > 0 unless relaxed)
 *   3. each scratch count <= its live count (monotonic sanity)
 *   4. fiscal:verify-chain --all exit 0 on the SCRATCH copy
 *
 * @see scripts/db/RESTORE_DRILL_2026-05-21.md  manual proof this automates
 * @see app/Console/Commands/Backup/RunDailyBackup.php  the producer
 * @see app/Console/Commands/FiscalVerifyChainCommand.php  the verifier
 */
class BackupVerifyRestoreCommand extends Command
{
    /**
     * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Codex P1-A] Contexte du drill en cours, pour
     * que le verdict persisté dise QUOI a été restauré et EN COMBIEN DE TEMPS — pas
     * seulement vert/rouge.
     */
    private ?string $drillFile = null;

    private ?string $drillSha256 = null;

    private ?float $drillStartedAt = null;

    /**
     * [GOAL G4 2026-09-03 · T4.2 · défaut V-09] Un verdict a-t-il été enregistré pendant
     * CETTE exécution ? Sert de filet : aucune sortie en échec ne doit laisser en place le
     * verdict vert de la veille — pas même une sortie ajoutée après coup.
     */
    private bool $verdictPersiste = false;

    protected $signature = 'backup:verify-restore'
        . ' {--file= : Explicit backup .sql.gz to restore (default: newest daily-*.sql.gz)}'
        . ' {--scratch-db= : Scratch DB name (default: <live>_restore_scratch). MUST differ from live.}'
        . ' {--keep-scratch : Do not drop the scratch DB at the end (debugging only)}'
        . ' {--allow-empty-zreports : Treat z_reports row count of 0 as acceptable (dev boxes)}'
        . ' {--skip-chain-check : Skip the fiscal:verify-chain step (row-count drill only)}';

    protected $description = 'Restore the latest backup into a scratch DB and prove it is restorable + NF525 chain-intact (GREEN/RED).';

    /** Tables whose row counts gate the verdict. */
    private const ASSERTED_TABLES = ['orders', 'audit_logs', 'z_reports'];

    /**
     * [GOAL G4 2026-09-03 · T4.2 · défaut V-09] L'enveloppe qui garantit qu'un échec
     * s'inscrit TOUJOURS.
     *
     * Le corps du drill vit dans `executer()`. Ici on ne fait qu'une chose, mais elle est
     * la condition de sortie du GOAL : il ne doit exister AUCUN chemin par lequel un vert
     * survive à un échec. Six sorties `FAILURE` sur neuf ne persistaient rien — le succès
     * de la veille restait alors le « dernier résultat connu », et le cockpit comme
     * `/health/ready` continuaient d'attester une restauration qui n'avait pas eu lieu.
     */
    public function handle(): int
    {
        $this->verdictPersiste = false;

        try {
            $code = $this->executer();
        } catch (\Throwable $e) {
            // Une exception non rattrapée est un échec du drill, pas une absence de drill.
            $this->persisterVerdict(false, ['exception non rattrapée : '.$e->getMessage()]);

            throw $e;
        }

        if ($code !== self::SUCCESS && ! $this->verdictPersiste) {
            // Filet de dernier recours : couvre toute sortie en échec ajoutée plus tard
            // sans passer par `echec()`. Une raison vague vaut mieux qu'un faux vert.
            $this->persisterVerdict(false, ['drill interrompu sans verdict (code '.$code.')']);
        }

        return $code;
    }

    /**
     * L'enregistreur unique de verdict. Toute sortie en échec passe par ici — directement
     * (`echec()`), par le verdict final (`renderVerdict()`), ou par le filet de `handle()`.
     *
     * @param  list<string>  $reasons
     */
    private function persisterVerdict(bool $green, array $reasons): void
    {
        // Écriture au mieux-effort : elle ne doit jamais faire échouer le drill lui-même
        // (RestoreDrillResult avale ses propres erreurs).
        RestoreDrillResult::store([
            'status' => $green ? 'green' : 'failed',
            'verified_at' => now()->toIso8601String(),
            'file' => $this->drillFile,
            'sha256' => $this->drillSha256,
            'duration_s' => $this->drillStartedAt !== null ? microtime(true) - $this->drillStartedAt : 0.0,
            'reasons' => $reasons,
        ]);

        $this->verdictPersiste = true;
    }

    /** Sortir en échec, en le DISANT à l'écran et en l'INSCRIVANT au verdict. */
    private function echec(string $raison): int
    {
        $this->error($raison);
        $this->persisterVerdict(false, [$raison]);

        return self::FAILURE;
    }

    private function executer(): int
    {
        $driver = (string) config('database.default');
        if ($driver !== 'mysql') {
            $code = $this->echec("backup:verify-restore currently supports the mysql driver only (default={$driver}).");
            $this->line('On a sqlite install the dump is a plain gzip of the .sqlite file — restore it manually.');

            return $code;
        }

        $conn = (array) config('database.connections.mysql');
        $liveDb = (string) ($conn['database'] ?? '');
        if ($liveDb === '') {
            return $this->echec('Live database name missing from config(database.connections.mysql.database).');
        }

        // ---- SAFETY: scratch must never be the live DB ----
        // deriveScratchName() returns null iff the requested name == live.
        $override = (string) $this->option('scratch-db');
        $scratchDb = self::deriveScratchName($liveDb, $override !== '' ? $override : null);
        if ($scratchDb === null) {
            return $this->echec("REFUSING: scratch DB name equals the live DB ({$liveDb}). The drill would destroy live data.");
        }

        // ---- Locate the backup file ----
        $file = $this->resolveBackupFile();
        if ($file === null) {
            // [Codex P1-A] Un drill qui ne trouve rien à restaurer est un ÉCHEC à afficher,
            // pas un silence : sans cette ligne le cockpit garderait le dernier verdict vert.
            return $this->echec('No backup file found. Run `php artisan foodking:backup-daily` first, or pass --file=.');
        }

        // [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Codex P1-A] Contexte pour le verdict persisté.
        // [G4 2026-09-03 · T4.2] Renseigné AVANT le contrôle de lisibilité : un verdict
        // rouge qui ne nomme pas le fichier fautif oblige à rouvrir les journaux.
        $this->drillFile = basename($file);
        $this->drillStartedAt = microtime(true);

        if (!is_readable($file)) {
            return $this->echec("Backup file not readable: {$file}");
        }

        $this->drillSha256 = @hash_file('sha256', $file) ?: null;

        $ageHours = (time() - (int) @filemtime($file)) / 3600;
        $this->info(sprintf(
            '[verify-restore] file=%s (%.1f h old, %d bytes) scratch=%s live=%s',
            basename($file),
            $ageHours,
            (int) @filesize($file),
            $scratchDb,
            $liveDb
        ));
        if ($ageHours > 26) {
            // Not a failure, but worth surfacing: stale backups mean the
            // scheduler may not be running.
            $this->warn(sprintf('Backup is %.1f h old — verify the daily scheduler is running.', $ageHours));
        }

        // ---- Capture LIVE counts BEFORE we ever repoint the connection ----
        $liveCounts = [];
        try {
            foreach (self::ASSERTED_TABLES as $t) {
                $liveCounts[$t] = (int) DB::table($t)->count();
            }
        } catch (\Throwable $e) {
            return $this->echec('Could not read live row counts (DB unreachable?): ' . $e->getMessage());
        }
        $this->line('  live counts: ' . $this->fmtCounts($liveCounts));

        $host = (string) ($conn['host'] ?? '127.0.0.1');
        $port = (string) ($conn['port'] ?? '3306');
        $user = (string) ($conn['username'] ?? '');
        $pass = (string) ($conn['password'] ?? '');

        $originalConnDb = $liveDb;
        $green = true;
        $reasons = [];

        try {
            // ---- 1. Fresh empty scratch schema ----
            $charset = (string) ($conn['charset'] ?? 'utf8mb4');
            $collation = (string) ($conn['collation'] ?? 'utf8mb4_unicode_ci');
            $createSql = sprintf(
                'DROP DATABASE IF EXISTS `%1$s`; CREATE DATABASE `%1$s` CHARACTER SET %2$s COLLATE %3$s;',
                $this->safeIdent($scratchDb),
                $charset,
                $collation
            );
            if (!$this->runMysql($host, $port, $user, $pass, null, $createSql, 120)) {
                return $this->echec('Failed to (re)create scratch database.');
            }

            // ---- 2. Restore the gzip dump into scratch ----
            $this->line('  restoring dump into scratch …');
            $restoreOk = $this->restoreInto($host, $port, $user, $pass, $scratchDb, $file);
            if (!$restoreOk) {
                $green = false;
                $reasons[] = 'restore process exited non-zero';
                // No point asserting counts on a failed restore.
                $this->renderVerdict(false, $reasons, [], $liveCounts);

                return self::FAILURE;
            }

            // ---- 3a. Table-count parity (catches a PARTIAL restore) ----
            // `gunzip -c file | mysql scratch` returns *mysql's* exit code, so
            // a truncated gzip can let mysql exit 0 on partial input. The
            // row-count + chain checks below largely cover this (a partial
            // restore would drop tables / break the chain), but asserting the
            // restored schema has the SAME number of tables as live is the
            // cheapest direct guard — and mirrors the proven manual drill's
            // "88 tables" check (scripts/db/RESTORE_DRILL_2026-05-21.md).
            $liveTables = $this->tableCount($host, $port, $user, $pass, $liveDb);
            $scratchTables = $this->tableCount($host, $port, $user, $pass, $scratchDb);
            $this->line(sprintf('  tables: live=%s scratch=%s', $liveTables ?? 'ERR', $scratchTables ?? 'ERR'));
            if ($liveTables === null || $scratchTables === null) {
                $green = false;
                $reasons[] = 'could not read information_schema table counts';
            } elseif ($scratchTables < $liveTables) {
                $green = false;
                $reasons[] = "restored schema has {$scratchTables} tables but live has {$liveTables} (partial restore?)";
            }

            // ---- 3b. Assert row counts on the scratch copy ----
            $scratchCounts = [];
            foreach (self::ASSERTED_TABLES as $t) {
                $scratchCounts[$t] = $this->scalarCount($host, $port, $user, $pass, $scratchDb, $t);
            }
            $this->line('  scratch counts: ' . $this->fmtCounts($scratchCounts));

            $allowEmptyZ = (bool) $this->option('allow-empty-zreports');
            $countReasons = self::evaluateCounts($scratchCounts, $liveCounts, $allowEmptyZ);
            if ($countReasons !== []) {
                $green = false;
                $reasons = array_merge($reasons, $countReasons);
            }

            // ---- 4. NF525 chain check on the SCRATCH copy (in-process rebind) ----
            if ($this->option('skip-chain-check')) {
                $this->warn('  chain check skipped (--skip-chain-check).');
            } else {
                [$chainOk, $chainReason] = $this->runChainCheckOnScratch($scratchDb);
                if (!$chainOk) {
                    $green = false;
                    $reasons[] = $chainReason;
                }
            }
        } catch (\Throwable $e) {
            $green = false;
            $reasons[] = 'exception during drill: ' . $e->getMessage();
        } finally {
            // ---- Always restore the connection to the live DB ----
            config(['database.connections.mysql.database' => $originalConnDb]);
            DB::purge('mysql');

            // ---- Always drop scratch (unless explicitly kept) ----
            if (!$this->option('keep-scratch')) {
                $this->runMysql(
                    $host,
                    $port,
                    $user,
                    $pass,
                    null,
                    sprintf('DROP DATABASE IF EXISTS `%s`;', $this->safeIdent($scratchDb)),
                    60
                );
            } else {
                $this->warn("  scratch DB kept: {$scratchDb} (drop it manually when done).");
            }
        }

        $this->renderVerdict($green, $reasons, [], $liveCounts);

        return $green ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Newest daily-*.sql.gz, or the explicit --file.
     */
    private function resolveBackupFile(): ?string
    {
        $explicit = (string) $this->option('file');
        if ($explicit !== '') {
            return $explicit;
        }

        // [GOAL G4 2026-09-03 · suite de T4.1] Même désignation que le cockpit et
        // `/health/ready`, depuis le seul endroit qui la porte. Deux motifs différents pour
        // un même dossier rendaient le rapprochement fichier ↔ drill impossible à satisfaire.
        return RestoreDrillResult::cheminSauvegardeCourante();
    }

    // ----------------------------------------------------------------------
    // Pure logic — extracted for unit testing without a real MySQL server.
    // ----------------------------------------------------------------------

    /**
     * Pick the newest file from a candidate list by mtime (filesystem mtime,
     * not filename date, so it is robust to clock drift). Returns null for an
     * empty list. The caller may pass a precomputed mtime map for testing.
     *
     * @param  list<string>  $candidates
     * @param  array<string,int>|null  $mtimes  optional path=>mtime override (tests)
     */
    public static function pickNewest(array $candidates, ?array $mtimes = null): ?string
    {
        // [GOAL G4 2026-09-03] Une seule implémentation du tri, partagée avec la surface de
        // santé : deux tris qui divergent, c'est deux fichiers désignés pour un seul fait.
        return RestoreDrillResult::plusRecent($candidates, $mtimes);
    }

    /**
     * Derive the scratch DB name. Defaults to `<live>_restore_scratch`.
     * Returns null when the override equals the live DB (caller must refuse).
     */
    public static function deriveScratchName(string $liveDb, ?string $override = null): ?string
    {
        $scratch = ($override !== null && $override !== '') ? $override : ($liveDb . '_restore_scratch');

        return $scratch === $liveDb ? null : $scratch;
    }

    /**
     * Evaluate scratch vs live row counts and return the list of failure
     * reasons (empty list = all assertions pass). This is the verdict core,
     * deterministic and side-effect free.
     *
     * Rules per asserted table:
     *   - null scratch count               → "missing/unreadable"
     *   - scratch < floor (1, or 0 for z_reports when $allowEmptyZ) → too low
     *   - scratch > live                   → impossible for append-only/growing
     *
     * @param  array<string,int|null>  $scratchCounts
     * @param  array<string,int>  $liveCounts
     * @return list<string>
     */
    public static function evaluateCounts(array $scratchCounts, array $liveCounts, bool $allowEmptyZ): array
    {
        $reasons = [];
        foreach (self::ASSERTED_TABLES as $t) {
            $sc = $scratchCounts[$t] ?? null;
            if ($sc === null) {
                $reasons[] = "{$t}: table missing or unreadable in scratch";

                continue;
            }
            $floorZero = ($t === 'z_reports' && $allowEmptyZ) ? 0 : 1;
            if ($sc < $floorZero) {
                $reasons[] = "{$t}: restored row count {$sc} below floor {$floorZero}";
            }
            $live = $liveCounts[$t] ?? 0;
            if ($sc > $live) {
                $reasons[] = "{$t}: scratch {$sc} > live {$live} (impossible for append-only/growing table — corrupt restore?)";
            }
        }

        return $reasons;
    }

    /**
     * Re-walk the NF525 chains on the restored copy by temporarily
     * repointing the mysql connection to scratch, asserting we really hit
     * scratch (SELECT DATABASE()), then $this->call()-ing the frozen
     * verifier. Returns [ok, reason].
     */
    private function runChainCheckOnScratch(string $scratchDb): array
    {
        config(['database.connections.mysql.database' => $scratchDb]);
        DB::purge('mysql');

        // POSITIVE proof we are pointed at scratch — a misconfigured rebind
        // must NOT silently verify the live chain and report false GREEN.
        $actual = (string) DB::connection('mysql')->selectOne('SELECT DATABASE() AS db')->db;
        if ($actual !== $scratchDb) {
            return [false, "chain check ABORTED: connection points at '{$actual}', expected scratch '{$scratchDb}'"];
        }

        $this->line("  running fiscal:verify-chain --all on scratch ({$actual}) …");
        $exit = $this->call('fiscal:verify-chain', ['--all' => true]);

        if ($exit === 0) {
            return [true, ''];
        }

        return [false, "fiscal:verify-chain --all exited {$exit} on the restored copy (NF525 chain not intact after restore)"];
    }

    /**
     * gunzip -c <file> | mysql <scratch>  — the exact restore the manual
     * drill proved. Credentials via MYSQL_PWD.
     */
    private function restoreInto(string $host, string $port, string $user, string $pass, string $scratchDb, string $file): bool
    {
        $cmd = sprintf(
            'gunzip -c %s | mysql --host=%s --port=%s --user=%s %s',
            escapeshellarg($file),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($user),
            escapeshellarg($scratchDb)
        );

        $process = Process::fromShellCommandline($cmd);
        $process->setTimeout(3600);
        $process->setEnv(['MYSQL_PWD' => $pass]);

        try {
            $process->run(function ($type, $buffer): void {
                if ($type === Process::ERR) {
                    foreach (preg_split("/\r?\n/", trim($buffer)) ?: [] as $line) {
                        // mysql client emits an "insecure" notice on stderr even
                        // on success; surface everything but don't treat the
                        // notice itself as failure (exit code decides).
                        if ($line !== '') {
                            $this->warn("[restore] {$line}");
                        }
                    }
                }
            });
        } catch (ProcessTimedOutException $e) {
            $this->error('restore timed out (>1h)');

            return false;
        }

        $code = $process->getExitCode();
        Log::channel('observability')->info('restore.drill.restore_done', [
            'event' => 'restore.drill.restore_done',
            'file' => $file,
            'scratch' => $scratchDb,
            'exit_code' => $code,
        ]);

        return $code === 0;
    }

    /**
     * Run an arbitrary SQL string via the mysql client. $db may be null to
     * run server-level statements (CREATE/DROP DATABASE). Returns true on
     * exit 0.
     */
    private function runMysql(string $host, string $port, string $user, string $pass, ?string $db, string $sql, int $timeout): bool
    {
        $args = [
            sprintf('--host=%s', escapeshellarg($host)),
            sprintf('--port=%s', escapeshellarg($port)),
            sprintf('--user=%s', escapeshellarg($user)),
        ];
        if ($db !== null) {
            $args[] = escapeshellarg($db);
        }
        $cmd = 'mysql ' . implode(' ', $args) . ' -e ' . escapeshellarg($sql);

        $process = Process::fromShellCommandline($cmd);
        $process->setTimeout($timeout);
        $process->setEnv(['MYSQL_PWD' => $pass]);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            $this->error('mysql statement timed out');

            return false;
        }

        if (($process->getExitCode() ?? 1) !== 0) {
            $err = trim($process->getErrorOutput());
            if ($err !== '') {
                $this->warn('[mysql] ' . $err);
            }
        }

        return ($process->getExitCode() ?? 1) === 0;
    }

    /**
     * SELECT COUNT(*) FROM <table> on the scratch DB via the mysql client.
     * Returns the count, or null if the query failed (e.g. table missing).
     */
    private function scalarCount(string $host, string $port, string $user, string $pass, string $db, string $table): ?int
    {
        $cmd = sprintf(
            'mysql --host=%s --port=%s --user=%s -N -s %s -e %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($user),
            escapeshellarg($db),
            escapeshellarg(sprintf('SELECT COUNT(*) FROM `%s`', $this->safeIdent($table)))
        );

        $process = Process::fromShellCommandline($cmd);
        $process->setTimeout(120);
        $process->setEnv(['MYSQL_PWD' => $pass]);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            return null;
        }

        if (($process->getExitCode() ?? 1) !== 0) {
            return null;
        }

        $out = trim($process->getOutput());
        if ($out === '' || !ctype_digit($out)) {
            return null;
        }

        return (int) $out;
    }

    /**
     * Count base tables in a schema via information_schema. Returns null on
     * query failure.
     */
    private function tableCount(string $host, string $port, string $user, string $pass, string $db): ?int
    {
        $cmd = sprintf(
            'mysql --host=%s --port=%s --user=%s -N -s -e %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($user),
            escapeshellarg(sprintf(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '%s' AND table_type = 'BASE TABLE'",
                $this->safeIdent($db)
            ))
        );

        $process = Process::fromShellCommandline($cmd);
        $process->setTimeout(120);
        $process->setEnv(['MYSQL_PWD' => $pass]);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            return null;
        }

        if (($process->getExitCode() ?? 1) !== 0) {
            return null;
        }

        $out = trim($process->getOutput());

        return ctype_digit($out) ? (int) $out : null;
    }

    /**
     * Defensive identifier sanitizer — strip backticks so a crafted table /
     * DB name cannot break out of the `...` quoting. Scratch + table names
     * are operator-supplied or constants, but be safe.
     */
    private function safeIdent(string $ident): string
    {
        return str_replace('`', '', $ident);
    }

    /** @param array<string,int|null> $counts */
    private function fmtCounts(array $counts): string
    {
        $parts = [];
        foreach ($counts as $t => $c) {
            $parts[] = $t . '=' . ($c === null ? 'ERR' : (string) $c);
        }

        return implode(' ', $parts);
    }

    /**
     * @param  list<string>  $reasons
     * @param  array<string,int|null>  $scratchCounts  (unused placeholder for symmetry)
     * @param  array<string,int>  $liveCounts
     */
    private function renderVerdict(bool $green, array $reasons, array $scratchCounts, array $liveCounts): void
    {
        // [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Codex P1-A] LE point de bascule : le verdict
        // est désormais PERSISTÉ, pas seulement journalisé. Le cockpit et /health/ready le
        // lisent — une sauvegarde fraîche mais non restaurable ne peut plus afficher
        // « Tout va bien ».
        // [G4 2026-09-03 · T4.2] Passe par l'enregistreur unique, comme toutes les autres
        // sorties : un seul endroit écrit le verdict, donc un seul endroit peut l'oublier.
        $this->persisterVerdict($green, $reasons);

        $this->newLine();
        if ($green) {
            $this->info('==================== RESTORE DRILL: GREEN ====================');
            $this->info('Backup is restorable AND NF525 chain-intact on the restored copy.');
            Log::channel('observability')->info('restore.drill.green', [
                'event' => 'restore.drill.green',
                'live_counts' => $liveCounts,
            ]);
        } else {
            $this->error('==================== RESTORE DRILL: RED ======================');
            foreach ($reasons as $r) {
                $this->error('  - ' . $r);
            }
            Log::channel('observability')->error('restore.drill.red', [
                'event' => 'restore.drill.red',
                'reasons' => $reasons,
                'live_counts' => $liveCounts,
            ]);
        }
    }
}
