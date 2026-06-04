<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * @FK-ID Z6-P1-WGS — withoutGlobalScopes() plural audit sentinel (Wave M)
 * @source RED-Z6-branchscope.md P1-Z6-03 — 17×→25 sites audit + heal
 * @sprint Wave M Z6 P1 2026-05-19
 * @severity P1 — Plural `withoutGlobalScopes()` kills BOTH BranchScope AND
 *               SoftDeletingScope. Each site must declare intent.
 *
 * BACKGROUND
 * ----------
 * `$model::withoutGlobalScopes()` (zero-arg, plural) removes every registered
 * global scope on the query. For models with both `BranchScope` and
 * `SoftDeletingScope`, this silently leaks soft-deleted rows. The
 * CleanupStalePendingKioskOrders job documents the trap at
 * `app/Jobs/CleanupStalePendingKioskOrders.php:23-30`:
 *
 *     `withoutGlobalScopes()` would ALSO drop SoftDeletingScope, risking
 *      the auto-rejection of orders that were already soft-deleted.
 *      Drop only BranchScope (multi-tenant by design) and keep the
 *      soft-delete guard intact.
 *
 * The safe pattern is :
 *   - `Model::withoutGlobalScope(BranchScope::class)` — bypass branch fence only
 *   - `Model::withoutGlobalScope(BranchScope::class)->withTrashed()` — both, explicit
 *
 * AUDIT 2026-05-19 (Wave M Z6 P1)
 * ------------------------------
 * Pre-heal: 25 plural occurrences across 15 files. Each was classified:
 *   - Cat A (KEEP plural — restore semantics, finds soft-deleted rows by design)
 *     Annotated inline with `// [GlobalScopes:keep-both]` + reason.
 *   - Cat B (HEAL to singular — only BranchScope bypass needed)
 *     Replaced with `withoutGlobalScope(BranchScope::class)`.
 *   - Cat C (HEAL to singular+withTrashed — NF525 needs both off, made explicit)
 *     Replaced with `withoutGlobalScope(BranchScope::class)->withTrashed()`.
 *
 * Post-heal allowlist (Category A — KEEP plural):
 *   - app/Console/Commands/EnsureAdminLoginCommand.php (4 sites: 56,63,70,99)
 *       — admin restore command, finds soft-deleted admin to restore + verifies
 *         no email-conflict among trashed rows
 *   - app/Console/Commands/EnsurePosOperatorLoginCommand.php (1 site: 55)
 *       — POS operator restore command (deleted_at = null later)
 *   - app/Console/Commands/EnsureChefLoginCommand.php (1 site: 53)
 *       — Chef restore command (deleted_at = null later)
 *   - app/Http/Controllers/Auth/GuestSignupController.php (1 site: 98)
 *       — Already chains `->withTrashed()` explicitly; intent is to find ALL
 *         phone matches including soft-deleted guests for safe restore
 *
 * Total allowlist: 7 sites. Any other plural usage = sentinel fail.
 *
 * PURPOSE OF THIS SENTINEL
 * ------------------------
 * Drift-lock the plural-form audit:
 *   1) Count of `withoutGlobalScopes()` plural occurrences in `app/` MUST match
 *      the allowlist count (7) — adding ANY new occurrence triggers CI fail.
 *   2) Each occurrence MUST appear in the file:line allowlist OR carry an
 *      inline `// [GlobalScopes:keep-both]` annotation on the same line —
 *      otherwise sentinel fails.
 *   3) The sentinel walks `app/` with PHP Finder, so renames/moves are caught.
 *
 * @group sentinel
 * @group multitenant
 */
class WithoutGlobalScopesAuditSentinelTest extends TestCase
{
    /**
     * Allowlist of file:line tuples where plural `withoutGlobalScopes()` is
     * intentional (Category A — see class docblock).
     *
     * MAINTENANCE: if a Cat A site changes line, update here. If a Cat A site
     * is removed/refactored, remove from list. NEVER add a new entry without
     * documenting the reason in the class docblock.
     *
     * @var array<string,array<int,int>> file path (relative to app/) → line numbers
     */
    private const ALLOWLIST = [
        'Console/Commands/EnsureAdminLoginCommand.php'        => [56, 63, 70, 99],
        'Console/Commands/EnsurePosOperatorLoginCommand.php'  => [55],
        'Console/Commands/EnsureChefLoginCommand.php'         => [53],
        'Http/Controllers/Auth/GuestSignupController.php'     => [98],
    ];

    public function test_no_unannotated_plural_withoutGlobalScopes_in_app(): void
    {
        $appPath = base_path('app');
        $this->assertTrue(is_dir($appPath), "app/ directory missing at {$appPath}");

        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appPath, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var array<int, array{file:string, line:int, code:string}> $hits */
        $hits = [];

        foreach ($rii as $file) {
            if (!$file->isFile() || !str_ends_with((string) $file->getFilename(), '.php')) {
                continue;
            }

            $relPath = ltrim(str_replace($appPath, '', (string) $file->getRealPath()), DIRECTORY_SEPARATOR);
            $contents = file_get_contents((string) $file->getRealPath());
            if ($contents === false) {
                continue;
            }

            $lines = preg_split('/\r\n|\r|\n/', $contents);
            foreach ($lines as $idx => $line) {
                // Match the bare plural form `withoutGlobalScopes()` only.
                // Excludes:
                //   - `withoutGlobalScopes([X::class, ...])` (explicit array arg)
                //   - `withoutGlobalScope(X::class)` (singular)
                if (!preg_match('/withoutGlobalScopes\(\)/', $line)) {
                    continue;
                }

                // Skip comments (mentions of the function in docblocks).
                $trim = ltrim($line);
                if (str_starts_with($trim, '//')
                    || str_starts_with($trim, '*')
                    || str_starts_with($trim, '/*')
                    || str_starts_with($trim, '#')
                ) {
                    continue;
                }

                $hits[] = [
                    'file' => $relPath,
                    'line' => $idx + 1,
                    'code' => trim($line),
                ];
            }
        }

        // Flatten allowlist into a Set<string> of "file:line".
        $allowedSet = [];
        foreach (self::ALLOWLIST as $relFile => $lineNumbers) {
            foreach ($lineNumbers as $lineNum) {
                $allowedSet[$relFile . ':' . $lineNum] = true;
            }
        }

        $unannotated = [];
        $offAllowlistButAnnotated = [];

        foreach ($hits as $hit) {
            $key = $hit['file'] . ':' . $hit['line'];
            $hasInlineMarker = str_contains($hit['code'], '[GlobalScopes:keep-both]');

            if (isset($allowedSet[$key])) {
                // Allowlisted — accept.
                continue;
            }

            if ($hasInlineMarker) {
                // Off-allowlist but explicitly annotated — accept but track for
                // visibility. Future tightening could require allowlist-only.
                $offAllowlistButAnnotated[] = $key;
                continue;
            }

            $unannotated[] = $hit;
        }

        if (count($unannotated) > 0) {
            $lines = array_map(
                static fn (array $h) => sprintf('  - %s:%d  %s', $h['file'], $h['line'], $h['code']),
                $unannotated
            );

            $this->fail(
                "Z6-P1-WGS — Found "
                . count($unannotated)
                . " unannotated plural `withoutGlobalScopes()` call site(s).\n\n"
                . "Each must EITHER be added to ALLOWLIST in this sentinel\n"
                . "(with reason documented in the class docblock) OR carry an\n"
                . "inline `// [GlobalScopes:keep-both]` marker explaining why\n"
                . "BOTH BranchScope and SoftDeletingScope must be bypassed.\n\n"
                . "Preferred heal: replace with\n"
                . "    withoutGlobalScope(\\App\\Models\\Scopes\\BranchScope::class)\n"
                . "or, if soft-deleted rows are genuinely needed:\n"
                . "    withoutGlobalScope(\\App\\Models\\Scopes\\BranchScope::class)->withTrashed()\n\n"
                . "Offenders:\n"
                . implode("\n", $lines)
            );
        }
    }

    public function test_allowlist_count_matches_actual_count(): void
    {
        $expectedTotal = 0;
        foreach (self::ALLOWLIST as $lines) {
            $expectedTotal += count($lines);
        }

        $appPath = base_path('app');
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appPath, \FilesystemIterator::SKIP_DOTS)
        );

        $actual = 0;
        $found = [];
        foreach ($rii as $file) {
            if (!$file->isFile() || !str_ends_with((string) $file->getFilename(), '.php')) {
                continue;
            }
            $contents = file_get_contents((string) $file->getRealPath());
            if ($contents === false) {
                continue;
            }
            $lines = preg_split('/\r\n|\r|\n/', $contents);
            foreach ($lines as $idx => $line) {
                if (!preg_match('/withoutGlobalScopes\(\)/', $line)) {
                    continue;
                }
                $trim = ltrim($line);
                if (str_starts_with($trim, '//')
                    || str_starts_with($trim, '*')
                    || str_starts_with($trim, '/*')
                    || str_starts_with($trim, '#')
                ) {
                    continue;
                }
                // Count actual code occurrences (annotated and allowlisted).
                $actual++;
                $rel = ltrim(str_replace($appPath, '', (string) $file->getRealPath()), DIRECTORY_SEPARATOR);
                $found[] = $rel . ':' . ($idx + 1);
            }
        }

        // The total includes Cat A allowlist AND any Cat C `[GlobalScopes:keep-both]`
        // annotated sites that are NF525-justified (e.g. fiscal MAX, archive).
        // Wave M post-heal expected total = 7 allowlist (Cat A) + 0 annotated
        // (all NF525 sites were healed to singular+withTrashed, not annotated).
        // If this count drifts, either ALLOWLIST is out of date, an annotated
        // site appeared/disappeared, or a Cat B regression was introduced.
        $this->assertSame(
            $expectedTotal,
            $actual,
            "Z6-P1-WGS count drift — expected {$expectedTotal} plural occurrences "
            . "(all allowlisted Cat A), found {$actual}. Sites found:\n  - "
            . implode("\n  - ", $found)
        );
    }
}
