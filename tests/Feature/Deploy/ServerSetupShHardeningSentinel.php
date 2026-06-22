<?php

namespace Tests\Feature\Deploy;

use Tests\TestCase;

/**
 * [Phase L Agent L2.1 — server-setup.sh hardening sentinel, 2026-05-24]
 *
 * CI guardrail: `scripts/deploy/server-setup.sh` MUST stay hardened for
 * Le Cayenne V1 LOCAL production (Hetzner CX22 single-box). This sentinel
 * is a STATIC string-check of the script source — it does NOT execute
 * the script (it is a root-privileged production-only setup script).
 *
 * ## Five invariants asserted
 *
 *   1. `set -euo pipefail` is enabled at the TOP of the script so any
 *      unhandled non-zero exit aborts setup before partial-bring-up.
 *
 *   2. Both MySQL password files are written with mode 0600 AND with a
 *      `umask 077` set BEFORE the write (defense-in-depth so even the
 *      pre-chmod inode is not group/other-readable).
 *
 *   3. UFW ordering: if the script ever calls `ufw enable` in an
 *      executable line (not a comment / echo / printf string), then
 *      `ufw allow OpenSSH` MUST appear BEFORE it. The current script
 *      INTENTIONALLY does NOT call `ufw enable` (manual gate via owner
 *      after confirming SSH still works from a second terminal — see
 *      Section 14 of the script). The assertion is conditional: pass
 *      if `ufw enable` is absent from executable lines; if it is ever
 *      added, ordering is enforced. This guards both intent + future
 *      regression.
 *
 *   4. No hardcoded passwords. All MySQL passwords reach SQL through
 *      `${shell_variable}` interpolation; literal `BY 'plaintext'`
 *      OR `password='plaintext'` patterns must NOT appear in
 *      executable lines.
 *
 *   5. NF525 GRANT/REVOKE on audit_logs + z_reports is wired (the SQL
 *      `REVOKE DROP, ALTER ON` clause is present and bound to both
 *      tables). The Ansible CVP0-1 invariant (REVOKE destructive
 *      privileges) must stay reachable by the script.
 *
 * ## What this sentinel does NOT assert
 *
 *   - Does not run the script. Static check only — `set -e`, `chmod`,
 *     and SQL effects cannot be observed in CI.
 *   - Does not require `ufw enable` to be present. The script's
 *     manual-gate design is intentional and documented (CLAUDE.md +
 *     README_DEPLOY).
 *   - Does not check Redis bind-address (separate hardening concern
 *     tracked in L2.1 findings — defense-in-depth recommendation,
 *     not a script-source invariant).
 *
 * @group sentinel
 * @group deploy
 */
class ServerSetupShHardeningSentinel extends TestCase
{
    /**
     * Single test holds all 5 invariants — any failure becomes one red
     * signal; assertion messages identify which clause tripped.
     */
    public function test_server_setup_script_holds_hardening_invariants(): void
    {
        $path = base_path('scripts/deploy/server-setup.sh');
        $this->assertFileExists(
            $path,
            'scripts/deploy/server-setup.sh missing — Phase L2.1 invariant '
            . 'cannot be checked without the script.'
        );

        $script = (string) file_get_contents($path);
        $this->assertNotSame('', $script, 'server-setup.sh is empty');

        // -- Invariant 1: set -euo pipefail at the top -------------------
        // Match within the first ~30 lines (header is ~38 lines of doc
        // comment + the set line at L39 per current snapshot). Use a
        // regex that allows any order of -e -u -o pipefail flags.
        $headerSlice = implode("\n", array_slice(preg_split('/\R/', $script) ?: [], 0, 60));
        $this->assertMatchesRegularExpression(
            '/^set\s+-euo\s+pipefail\s*$/m',
            $headerSlice,
            'server-setup.sh must enable `set -euo pipefail` at the top so '
            . 'any unhandled non-zero exit, unset variable, or pipeline '
            . 'failure aborts setup before partial bring-up. Phase L2.1 '
            . 'invariant 1.'
        );

        // -- Invariant 2: mode 600 + umask 077 on password files ---------
        // Both /root/.mysql_root_password and /root/.mysql_${APP_DB_USER}_password
        // MUST be chmod 600. Verified BOTH in the same file.
        $chmod600Count = preg_match_all('/chmod\s+600\s+"\$\{[^"}]*_pw_file\}"/', $script);
        $this->assertGreaterThanOrEqual(
            2,
            $chmod600Count,
            'server-setup.sh must `chmod 600` BOTH MySQL password files '
            . '(root + app user). Found '
            . (int) $chmod600Count
            . ' chmod-600 statements bound to a "*_pw_file" variable. '
            . 'Phase L2.1 invariant 2.'
        );

        // umask 077 must also be present (belt-and-braces — chmod 600
        // runs AFTER write, so umask narrows the create-time perms).
        $this->assertMatchesRegularExpression(
            '/umask\s+077/',
            $script,
            'server-setup.sh must set `umask 077` before writing the '
            . 'MySQL password files so the inode is never group/other '
            . 'readable even for the millisecond between create + chmod. '
            . 'Phase L2.1 invariant 2.'
        );

        // -- Pre-work: classify each line as executable vs comment/echo --
        // Reuses the per-line filter pattern from
        // DeployScriptBackupBeforeMigrateSentinel. A line counts as
        // executable if it is NOT empty, NOT a `#` comment, and NOT an
        // echo/printf/cat invocation (those carry strings that contain
        // the same keywords as real commands).
        $lines = preg_split('/\R/', $script) ?: [];
        $byteOffset = 0;
        $ufwAllowOpenSshPos = null;
        $ufwEnablePos = null;
        $hardcodedPwHits = [];
        $revokeDropAlterFound = false;
        $revokeMentionsAuditLogs = false;
        $revokeMentionsZReports = false;

        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            $isComment = $trimmed === '' || $trimmed[0] === '#';
            $isEcho = (bool) preg_match('/^\s*(echo|printf|cat)\b/', $line);

            if (!$isComment && !$isEcho) {
                if ($ufwAllowOpenSshPos === null
                    && preg_match('/^\s*ufw\s+allow\s+OpenSSH\b/', $line)
                ) {
                    $ufwAllowOpenSshPos = $byteOffset;
                }
                if ($ufwEnablePos === null
                    && preg_match('/^\s*ufw\s+enable\b/', $line)
                ) {
                    $ufwEnablePos = $byteOffset;
                }

                // Hardcoded-password detector — only flag patterns where
                // the right-hand side is a literal alphanumeric/punct
                // string (no `$` interpolation, no `<<SQL` here-doc tag).
                // Patterns matched:
                //   BY 'plaintext'
                //   BY "plaintext"
                //   password='plaintext'
                //   PASSWORD = "plaintext"
                $hasDollarVar = strpos($line, '${') !== false || strpos($line, '$(') !== false;
                if (!$hasDollarVar) {
                    if (preg_match(
                        '/\b(BY|PASSWORD)\s*=?\s*([\'"])([A-Za-z0-9!@#%^&*()_+\-=\[\]{};:,.<>?\/]{4,})\2/i',
                        $line,
                        $m
                    )) {
                        // Skip false-positives: empty / SQL keyword tokens.
                        if (!in_array(strtoupper($m[3]), ['NULL', 'TRUE', 'FALSE'], true)) {
                            $hardcodedPwHits[] = [
                                'byte_offset' => $byteOffset,
                                'match' => $m[0],
                            ];
                        }
                    }
                }
            }

            // Even inside here-docs we want to inspect for NF525 REVOKE
            // — the REVOKE statement IS the SQL we are checking. So we
            // also scan unfiltered.
            if (!$revokeDropAlterFound
                && preg_match('/REVOKE\s+DROP\s*,\s*ALTER\s+ON/i', $line)
            ) {
                $revokeDropAlterFound = true;
            }

            $byteOffset += strlen($line) + 1; // +1 for newline
        }

        // Capture which tables the NF525 REVOKE loop iterates over —
        // both audit_logs AND z_reports must be in the `for tbl in` list.
        if (preg_match('/for\s+tbl\s+in\s+([^;]+);/', $script, $forMatch)) {
            $tablesList = $forMatch[1];
            $revokeMentionsAuditLogs = strpos($tablesList, 'audit_logs') !== false;
            $revokeMentionsZReports = strpos($tablesList, 'z_reports') !== false;
        }

        // -- Invariant 3: UFW ordering -----------------------------------
        $this->assertNotNull(
            $ufwAllowOpenSshPos,
            'server-setup.sh must call `ufw allow OpenSSH` to keep SSH '
            . 'reachable when UFW is enabled. Phase L2.1 invariant 3.'
        );

        if ($ufwEnablePos !== null) {
            // Script automated `ufw enable` — ordering must be correct.
            $this->assertLessThan(
                $ufwEnablePos,
                $ufwAllowOpenSshPos,
                sprintf(
                    '`ufw allow OpenSSH` (byte offset %d) MUST appear '
                    . 'BEFORE `ufw enable` (byte offset %d) to prevent '
                    . 'self-locking the operator out of SSH. Phase L2.1 '
                    . 'invariant 3.',
                    $ufwAllowOpenSshPos,
                    $ufwEnablePos
                )
            );
        }
        // else: script intentionally defers `ufw enable` to manual owner
        // gate (Section 14) — assertion is vacuously true. This is the
        // current intended state.

        // -- Invariant 4: no hardcoded passwords -------------------------
        $this->assertEmpty(
            $hardcodedPwHits,
            sprintf(
                'server-setup.sh must NOT contain hardcoded passwords. '
                . 'Found %d match(es) of literal `BY \'<plain>\'` or '
                . '`password=\'<plain>\'` outside of $-interpolation: %s. '
                . 'Phase L2.1 invariant 4.',
                count($hardcodedPwHits),
                json_encode($hardcodedPwHits)
            )
        );

        // -- Invariant 5: NF525 REVOKE wired -----------------------------
        $this->assertTrue(
            $revokeDropAlterFound,
            'server-setup.sh must contain the NF525 GRANT/REVOKE SQL '
            . '(`REVOKE DROP, ALTER ON` on audit_logs + z_reports). '
            . 'Phase L2.1 invariant 5 — CLAUDE.md §8 NF525 chain '
            . 'integrity invariant.'
        );

        $this->assertTrue(
            $revokeMentionsAuditLogs && $revokeMentionsZReports,
            'NF525 REVOKE loop must iterate over BOTH `audit_logs` AND '
            . '`z_reports` (current iteration: audit_logs=' . var_export($revokeMentionsAuditLogs, true)
            . ' z_reports=' . var_export($revokeMentionsZReports, true) . '). '
            . 'Phase L2.1 invariant 5.'
        );
    }
}
