<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * [2026-05-18 PR-D drift-guard sentinel — F-D-T3]
 *
 * Per ultra-review PR-D F-D-T3, ~78 FormRequest classes contain a naked
 * `return true;` in their `authorize()` method, deferring authz entirely
 * to route middleware (or to nothing, for routes without
 * `permission:*` middleware). V1.0.2 will surgically refactor the 8
 * highest-blast offenders to call `$this->user()?->can('xxx')`.
 *
 * This sentinel does NOT fix the 78 — it locks the BASELINE so a careless
 * refactor cannot grow the count. Any new FormRequest with `return true;`
 * (or any other reverted file) flips this sentinel red.
 *
 * Update the baseline ONLY when:
 *   - A FormRequest moves from `return true;` to an explicit can() check
 *     (count goes DOWN)
 *   - A new FormRequest is added with `return true;` AND there is a route
 *     middleware that authoritatively covers it (count goes UP with
 *     explicit owner-gate doc in plans/v1-0-2-backlog/).
 */
class FormRequestAuthzDriftSentinelTest extends TestCase
{
    /**
     * Baseline = 77 (2026-05-18 post Wave 7 — ChangePasswordRequest still
     * has `return true;` for the authorize body but the underlying
     * password rule is now hardened. Wave 7 commit ec0d49241).
     *
     * Original ultra-review claim: 86 — broader text-match including
     * comment lines. The 77 number reflects an exact `return true;` body
     * count via the methodology this test uses.
     */
    private const RETURN_TRUE_BASELINE = 74;

    public function test_form_request_return_true_count_does_not_grow_past_baseline(): void
    {
        $dir = base_path('app/Http/Requests');
        $this->assertDirectoryExists($dir);

        $count = 0;
        $files = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($rii as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $body = (string) file_get_contents($file->getPathname());
            // Match the authorize() body shape exactly. Excludes commented
            // examples / docblock references / unrelated `return true;` in
            // other methods would still match — accepted noise budget.
            if (preg_match('/public function authorize\(\)\s*:\s*bool\s*\{\s*return true;\s*\}/', $body)
                || preg_match('/public function authorize\(\)\s*\{\s*return true;\s*\}/', $body)) {
                $count++;
                $files[] = basename($file->getPathname());
            }
        }

        $msg = sprintf(
            "Found %d FormRequest classes with `return true;` in authorize() — baseline is %d.\n".
            "If count GROWS, refactor the new file to call \$this->user()?->can('xxx').\n".
            "If count SHRINKS, lower RETURN_TRUE_BASELINE in this sentinel + add a regression test for the refactored authz.\n".
            "Affected files:\n  - %s",
            $count,
            self::RETURN_TRUE_BASELINE,
            implode("\n  - ", $files),
        );

        $this->assertLessThanOrEqual(
            self::RETURN_TRUE_BASELINE,
            $count,
            'FormRequest authz drift detected. ' . $msg,
        );

        // Diagnostic: also report when count has SHRUNK so the operator
        // remembers to lower the baseline post-refactor.
        if ($count < self::RETURN_TRUE_BASELINE) {
            fwrite(STDOUT, "\n[sentinel] FormRequest return-true count is now {$count} "
                . "(< baseline {$count}). Lower RETURN_TRUE_BASELINE accordingly.\n");
        }
    }
}
