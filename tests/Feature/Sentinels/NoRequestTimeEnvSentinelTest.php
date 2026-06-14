<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * UNI-03 / AUTHZ-CFG-01 (ultra-review 2026-06-14) — no request-time env().
 *
 * `php artisan config:cache` (run by the OVH deploy) makes env() return NULL at
 * request time. Any env() read OUTSIDE config/, console commands, or boot-time
 * providers is a latent prod bug — it silently loses the configured value (the
 * live failure was env('CURRENCY') rendering a null currency on FIXED-tax items).
 * This sentinel fails if a NEW request-time env() read is introduced into app/,
 * forcing it through a config() file instead.
 *
 * ALLOWLIST (intentional, documented):
 *   - app/Services/Fiscal/AuditLogService.php — FROZEN §7 (per-branch secret
 *     override). Single-branch V1 never hits the dynamic path; the env→config
 *     migration here is gated behind a deferred LOCK (G-AUDITLOG-SECRET).
 */
class NoRequestTimeEnvSentinelTest extends TestCase
{
    /** Files allowed to read env() at request time (file path => reason). */
    private const ALLOWLIST = [
        'app/Services/Fiscal/AuditLogService.php', // FROZEN §7 — deferred LOCK G-AUDITLOG-SECRET
    ];

    public function test_no_request_time_env_reads_in_app(): void
    {
        $root = base_path();
        $offenders = [];

        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($rii as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');

            // Boot-time / CLI contexts where env() is legitimate (not cached-request path).
            if (str_starts_with($rel, 'app/Console/') || str_starts_with($rel, 'app/Providers/')) {
                continue;
            }
            if (in_array($rel, self::ALLOWLIST, true)) {
                continue;
            }

            foreach (file($file->getPathname()) as $n => $line) {
                $trimmed = ltrim($line);
                // Skip comment lines (the only legit place 'env(' may appear as prose).
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                // Match a real env() config-key read: env('KEY') / env("KEY").
                // (The quote requirement avoids matching prose like "...in .env (...)".)
                if (preg_match('/\benv\s*\(\s*[\'"]/', $line)) {
                    $offenders[] = $rel . ':' . ($n + 1) . ' → ' . trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Request-time env() reads found (break under config:cache — move to a config/ file):\n" . implode("\n", $offenders)
        );
    }
}
