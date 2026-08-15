<?php

namespace Tests\Feature\Deploy;

use Tests\TestCase;

/**
 * [GOAL-H2-HEAL-03 sentinel — Phase H.4 REC-1, 2026-05-24]
 *
 * CI guardrail: `scripts/deploy/deploy.sh` MUST invoke
 * `scripts/db/backup.sh` BEFORE running `php artisan migrate --force`,
 * and a backup failure MUST abort the deploy (exit 1).
 *
 * ## Why this sentinel exists
 *
 * Phase H.4 migration audit caught deploy.sh running `migrate --force`
 * with NO prior backup, even though `scripts/db/backup.sh` exists
 * explicitly as the pre-migration safety net (per docblock of
 * `app/Console/Commands/Backup/RunDailyBackup.php` L12-23 :
 * "The existing scripts/db/backup.sh is the **pre-migration** safety
 * net"). A schema regression mid-migrate without backup = unrecoverable
 * data loss on the production single-box (Le Cayenne LOCAL).
 *
 * ## What this sentinel asserts (3 invariants)
 *
 *   1. deploy.sh references `scripts/db/backup.sh` somewhere.
 *   2. The backup invocation appears BEFORE the first `migrate --force`
 *      (byte-offset comparison — bash executes top-down).
 *   3. The backup invocation is wired to abort the deploy on failure
 *      (the file uses `set -e` AND the invocation has an explicit
 *      `exit 1` guard). Both are checked because `set -e` interacts
 *      poorly with pipelines + sudo, so the explicit `|| { exit 1; }`
 *      pattern is the belt-and-braces signal.
 *
 * ## What this sentinel does NOT assert
 *
 *   - Does NOT accept `foodking:backup-daily --pre-migrate` as a
 *     substitute. That flag does NOT exist on RunDailyBackup
 *     (only --min-bytes, --warn-bytes, --dry-run, --no-rotate,
 *     --keep-*). Accepting it would let a broken deploy pass CI.
 *   - Does NOT exec the deploy. This is a static-string check —
 *     deploy.sh is a root-privileged production-only script and
 *     cannot run inside CI.
 *
 * @group sentinel
 * @group deploy
 */
class DeployScriptBackupBeforeMigrateSentinelTest extends TestCase
{
    /**
     * Invariant 1+2+3 — fold into one test to make any failure a single
     * red signal; the diagnostics distinguish which clause tripped.
     */
    public function test_deploy_script_runs_backup_before_migrate_with_abort_on_failure(): void
    {
        $path = base_path('scripts/deploy/deploy.sh');
        $this->assertFileExists(
            $path,
            'scripts/deploy/deploy.sh missing — repo skeleton broken'
        );

        $deploy = (string) file_get_contents($path);

        // Strip comments and string-quoted lines so we look ONLY at the
        // executable bash. Comments (`#`) and echo strings can carry the
        // word "migrate" or the path "backup.sh" without actually being
        // invocations — for example our own pre-migrate comment block.
        // We do a per-line filter, not a full bash parser: that's enough
        // for static intent-checks. Each surviving line is a candidate
        // command. The byte-offset of the line is what we compare.
        $lines = preg_split('/\R/', $deploy) ?: [];
        $byteOffset = 0;
        $execBackupPos = null;
        $execMigratePos = null;
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            $isComment = $trimmed === '' || $trimmed[0] === '#';
            $isEcho = (bool) preg_match('/^\s*(echo|printf|cat)\b/', $line);
            if (!$isComment && !$isEcho) {
                if ($execBackupPos === null && strpos($line, 'scripts/db/backup.sh') !== false) {
                    $execBackupPos = $byteOffset;
                }
                // Match `artisan` followed (after an optional close-quote
                // and whitespace) by `migrate --force`. The real call
                // uses `php "$APP_DIR/artisan" migrate --force` so a `"`
                // sits between the words and would defeat a plain `\s+`.
                if ($execMigratePos === null && preg_match('/artisan["\']?\s+migrate\s+--force/', $line)) {
                    $execMigratePos = $byteOffset;
                }
            }
            $byteOffset += strlen($line) + 1; // +1 for the consumed newline
        }

        // -- Invariant 1: backup.sh actually invoked ---------------------
        $this->assertNotNull(
            $execBackupPos,
            'deploy.sh must INVOKE scripts/db/backup.sh (not just mention '
            . 'it in a comment) before `artisan migrate --force` — '
            . 'Phase H.4 REC-1, GOAL-H2-HEAL-03.'
        );

        // -- Invariant 2: backup BEFORE migrate --------------------------
        $this->assertNotNull(
            $execMigratePos,
            'deploy.sh does not call `artisan migrate --force` — this '
            . 'sentinel was written under the assumption it does; if '
            . 'the deploy pipeline has changed, update this sentinel.'
        );

        $this->assertLessThan(
            $execMigratePos,
            $execBackupPos,
            sprintf(
                'backup invocation (offset %d) MUST appear BEFORE `artisan '
                . 'migrate --force` (offset %d) — bash executes top-down. '
                . 'Phase H.4 REC-1 (GOAL-H2-HEAL-03).',
                $execBackupPos,
                $execMigratePos
            )
        );

        // -- Invariant 3: failure aborts deploy --------------------------
        $this->assertMatchesRegularExpression(
            '/^\s*set\s+-[a-z]*e[a-z]*/m',
            $deploy,
            'deploy.sh must enable `set -e` (or `set -euo pipefail`) so any '
            . 'unhandled non-zero exit aborts the deploy. Phase H.4 REC-1.'
        );

        // The backup snippet itself MUST also carry an explicit exit-1
        // guard — `set -e` alone is unreliable through `sudo -u` +
        // multi-line continuations, hence the belt-and-braces pattern.
        $snippetWindow = substr($deploy, $execBackupPos, $execMigratePos - $execBackupPos);
        $this->assertMatchesRegularExpression(
            '/\|\|\s*\{[^}]*exit\s+1[^}]*\}/',
            $snippetWindow,
            'backup invocation must be followed by an explicit '
            . '`|| { ... exit 1; ... }` guard so backup failure aborts the '
            . 'deploy even if `set -e` is bypassed by sudo/pipeline. '
            . 'Phase H.4 REC-1 (GOAL-H2-HEAL-03).'
        );
    }
}
