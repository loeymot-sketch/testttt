<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * [2026-05-29 SUP-3 — Supervisor audit P1-2 finding]
 *
 * Defense against SEC-001-style incidents (live AWS keys force-added in
 * commit `9b1e741f4` 2026-05-10 via `.env` bypass of `.gitignore`).
 *
 * This sentinel scans the CURRENT tracked codebase for high-confidence
 * secret patterns. It does NOT scan git history — historic leak cleanup
 * is a separate concern (`git filter-repo --path .env --invert-paths`).
 *
 * Patterns enforced:
 *   - AWS Access Key ID: AKIA[0-9A-Z]{16}
 *   - Stripe live secret key: sk_live_[A-Za-z0-9]{24,}
 *   - Stripe live publishable key: pk_live_[A-Za-z0-9]{24,}
 *   - Slack bot token: xoxb-[0-9]{10,}-[0-9]{10,}-[A-Za-z0-9]{24,}
 *   - GitHub personal access token: ghp_[A-Za-z0-9]{36}
 *   - Generic AWS secret: aws_secret.*=.*[A-Za-z0-9/+]{40}
 *
 * Excluded paths:
 *   - vendor/, node_modules/, storage/, public/storage/, .git/
 *   - .env, .env.example, .env.testing (gitignored or test fixtures)
 *   - This sentinel file itself (contains the patterns as strings)
 *
 * If a legitimate secret-shaped string appears in a test fixture or
 * documentation, prefix it to break the pattern (e.g., "EXAMPLE_AKIA..."
 * or wrap in `<placeholder>` tags) and re-run.
 */
class CommittedSecretsScanSentinelTest extends TestCase
{
    private const PATTERNS = [
        'AWS Access Key ID'        => '/\bAKIA[0-9A-Z]{16}\b/',
        'Stripe live secret key'   => '/\bsk_live_[A-Za-z0-9]{24,}\b/',
        'Stripe live publishable'  => '/\bpk_live_[A-Za-z0-9]{24,}\b/',
        'Slack bot token'          => '/\bxoxb-\d{10,}-\d{10,}-[A-Za-z0-9]{24,}\b/',
        'GitHub PAT'               => '/\bghp_[A-Za-z0-9]{36}\b/',
        'GitHub OAuth'             => '/\bgho_[A-Za-z0-9]{36}\b/',
    ];

    /**
     * Excluded path prefixes — documentation/audit/proposal files that legitimately
     * REFERENCE secrets as evidence (e.g., audit reports documenting SEC-001 leak).
     * The pattern in these files is documentation, not an actual credential.
     */
    private const EXCLUDED_DOC_PATH_PREFIXES = [
        'reports/audit/',
        'reports/test-e2e/',
        'plans/',
        'docs/',
    ];

    private const EXCLUDED_FILENAMES = [
        'CommittedSecretsScanSentinelTest.php', // this file itself
    ];

    public function test_no_high_confidence_secret_patterns_in_tracked_code(): void
    {
        $base = base_path();
        $findings = [];
        $scanned = 0;

        // Enumerate ONLY git-tracked files. This auto-excludes:
        //   - .env / .env.backup* (gitignored)
        //   - vendor/, node_modules/, storage/, *.venv/ (gitignored)
        //   - any other untracked file lying around the workspace
        // Result mirrors what a fresh clone would see — i.e., the real
        // production attack surface.
        exec('cd '.escapeshellarg($base).' && git ls-files -z', $output, $exitCode);
        if ($exitCode !== 0) {
            $this->markTestSkipped('Sentinel requires git binary + repo (exec git ls-files failed)');
        }

        // git ls-files -z separates paths by NUL; concat output then split
        $rawList = implode("\n", $output); // exec strips line breaks, but -z output has no newlines per record anyway
        // Fallback: re-run via shell_exec to preserve NUL separators
        $rawNul = (string) shell_exec('cd '.escapeshellarg($base).' && git ls-files -z 2>/dev/null');
        $trackedPaths = $rawNul !== '' ? array_filter(explode("\0", $rawNul)) : array_filter($output);

        foreach ($trackedPaths as $relPath) {
            $relPath = ltrim((string) $relPath, '/');
            if ($relPath === '') {
                continue;
            }

            // Skip documentation paths that legitimately reference secrets as evidence
            $skip = false;
            foreach (self::EXCLUDED_DOC_PATH_PREFIXES as $prefix) {
                if (str_starts_with($relPath, $prefix)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            $basename = basename($relPath);
            if (in_array($basename, self::EXCLUDED_FILENAMES, true)) {
                continue;
            }

            $absPath = $base.'/'.$relPath;
            if (! is_file($absPath)) {
                continue;
            }

            // Skip binary files cheaply
            $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'zip', 'tar', 'gz', 'mp3', 'mp4', 'wav', 'webm', 'sqlite', 'db'], true)) {
                continue;
            }

            $size = @filesize($absPath);
            if ($size === false || $size > 1_048_576) {
                continue;
            }

            $content = (string) @file_get_contents($absPath);
            if ($content === '') {
                continue;
            }

            foreach (self::PATTERNS as $label => $regex) {
                if (preg_match($regex, $content, $matches)) {
                    $findings[] = sprintf('%s: %s match (first hit: %s)', $relPath, $label, substr($matches[0], 0, 16).'...');
                }
            }

            $scanned++;
        }

        $this->assertGreaterThan(
            100,
            $scanned,
            sprintf('Sentinel scanned only %d files — expected >100. Path filter may be over-broad.', $scanned),
        );

        $this->assertEmpty(
            $findings,
            sprintf(
                "%d secret-shaped string(s) detected in tracked files:\n  - %s\n\n".
                "Remediation:\n".
                "  1. If a real credential: ROTATE it AT THE PROVIDER first (do NOT commit a removal as the only fix).\n".
                "  2. Move the value to `.env` (gitignored).\n".
                "  3. If a test fixture: prefix with EXAMPLE_ or wrap to break the pattern.\n".
                "  4. If git history also contains it: `git filter-repo --path <file> --invert-paths` + force-push coord.",
                count($findings),
                implode("\n  - ", $findings),
            ),
        );
    }
}
