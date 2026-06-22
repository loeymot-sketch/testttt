<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * [2026-05-29 SUP-3 — Supervisor audit P1-3 finding]
 *
 * Enforces CLAUDE.md §7 frozen-zone protection at the CI level.
 *
 * Previously, frozen-zone discipline relied SOLELY on the local git-hook
 * `.cursor/hooks/safety-check.sh`. A fresh clone without the hook installed
 * left the 14+1 §7 files unprotected. This sentinel makes the protection
 * authoritative + CI-enforced.
 *
 * Update procedure (CLAUDE.md §10 human-gate required):
 *   1. Owner countersigns an LOCK_*.md doc authorizing the frozen-zone touch.
 *   2. Apply the patch on a dedicated branch.
 *   3. Re-run this test → it fails with the new SHA-256.
 *   4. Update tests/Feature/Sentinels/frozen-zone-sha256-baseline.json with
 *      the new hash, in the SAME commit that lands the patch (link the LOCK
 *      doc in the commit message).
 *   5. Owner final review of the diff before push.
 *
 * Anti-bypass:
 *   - Do NOT update baseline.json without a corresponding LOCK_*.md owner
 *     countersign. The reviewer's gate is: "is there a LOCK doc with owner
 *     signature explaining this touch?". If no, REJECT the PR.
 */
class FrozenZoneSha256BaselineSentinelTest extends TestCase
{
    private const BASELINE_FILE = 'tests/Feature/Sentinels/frozen-zone-sha256-baseline.json';

    public function test_frozen_zone_files_match_sha256_baseline(): void
    {
        $baselinePath = base_path(self::BASELINE_FILE);
        $this->assertFileExists(
            $baselinePath,
            sprintf('Frozen-zone baseline missing at %s. Recreate via the audit procedure.', self::BASELINE_FILE),
        );

        $baseline = json_decode((string) file_get_contents($baselinePath), true);
        $this->assertIsArray($baseline, 'Baseline file is not valid JSON');

        $drift = [];
        $checked = 0;

        foreach ($baseline as $relPath => $expectedSha) {
            // Skip _meta and any other underscore-prefixed metadata keys.
            if (str_starts_with($relPath, '_')) {
                continue;
            }

            $absPath = base_path($relPath);

            if (! file_exists($absPath)) {
                $drift[] = sprintf('%s: MISSING (file deleted or moved)', $relPath);
                continue;
            }

            $actualSha = hash_file('sha256', $absPath);
            if ($actualSha !== $expectedSha) {
                $drift[] = sprintf(
                    "%s:\n      baseline=%s\n      actual  =%s",
                    $relPath,
                    $expectedSha,
                    $actualSha,
                );
            }
            $checked++;
        }

        // Defense: ensure we actually checked at least 14 files (CLAUDE.md §7
        // count). A neutered baseline (entries deleted) should also fail.
        $this->assertGreaterThanOrEqual(
            14,
            $checked,
            sprintf('Frozen-zone baseline has only %d entries — expected ≥14 per CLAUDE.md §7.', $checked),
        );

        $this->assertEmpty(
            $drift,
            sprintf(
                "Frozen-zone SHA-256 drift detected on %d file(s):\n  - %s\n\n".
                "If this drift is INTENTIONAL with an owner-countersigned LOCK_*.md doc:\n".
                "  1. Update %s with the new hash(es) in the SAME commit that lands the patch.\n".
                "  2. Reference the LOCK doc path in the commit message.\n\n".
                "If this drift is UNINTENTIONAL (silent frozen-zone violation):\n".
                "  1. Revert the touch immediately.\n".
                "  2. Open an LOCK_*.md draft per CLAUDE.md §10 human-gate.\n".
                "  3. Get owner countersign before re-attempting.",
                count($drift),
                implode("\n  - ", $drift),
                self::BASELINE_FILE,
            ),
        );
    }
}
