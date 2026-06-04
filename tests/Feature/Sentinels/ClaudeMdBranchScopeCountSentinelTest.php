<?php

namespace Tests\Feature\Sentinels;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * [WJ-7 WI-7 doc-drift sentinel — Wave J 2026-05-19]
 *
 * CI guardrail: `CLAUDE.md §9 Branch Isolation` MUST stay synchronized
 * with the live BranchScope adoption baseline in `app/Models/*.php`.
 *
 * Backstory: WI-7 audit (2026-05-19) found a P0 hard contradiction in
 * §9 — the doc claimed "11 models" + "User exempted" while the codebase
 * declared BranchScope on 20 models AND User IS scoped (Customer is the
 * recursion-exempt one per `BranchScopeCoverageSentinelTest::EXEMPTED_MODELS`).
 *
 * Without this sentinel, a future BranchScope coverage wave (V1.0.2
 * heal C-P0-D) would shrink the exemption list and update the test
 * baseline, but CLAUDE.md §9 would drift silently. New contributors
 * reading §9 as the canon would believe inaccurate facts.
 *
 * Pattern mirrors `BranchScopeCoverageSentinelTest` (count drift) and
 * `FormRequestAuthzDriftSentinelTest` (baseline lock).
 *
 * Fail mode: §9 model count diverges from grep count. Heal: edit §9 OR
 * add/remove BranchScope declaration to converge — the human writes
 * code, the test catches the doc drift.
 *
 * @group doc-drift
 * @group sentinel
 * @group claude-md
 */
class ClaudeMdBranchScopeCountSentinelTest extends TestCase
{
    public function test_claude_md_section_9_branch_scope_count_matches_codebase(): void
    {
        $claudeMdPath = base_path('CLAUDE.md');
        $this->assertFileExists($claudeMdPath, 'CLAUDE.md MUST exist at project root');

        $contents = file_get_contents($claudeMdPath);
        $this->assertNotEmpty($contents, 'CLAUDE.md MUST be non-empty');

        // Extract the claimed model count from §9. The canonical sentence is:
        //   "`BranchScope` global appliqué sur **20 models** (baseline locked..."
        $matched = preg_match(
            '/BranchScope`?\s+global\s+appliqu[ée]\s+sur\s+\*\*(\d+)\s+models\*\*/u',
            $contents,
            $matches
        );

        $this->assertSame(
            1,
            $matched,
            "CLAUDE.md §9 MUST contain a phrase matching:\n"
            . "  '`BranchScope` global appliqué sur **N models**'\n"
            . "  where N is the integer count.\n"
            . "Current §9 paragraph was edited without preserving the canonical\n"
            . "phrasing — restore it so this drift sentinel can compare counts."
        );

        $claimedCount = (int) $matches[1];

        // Now count the actual `addGlobalScope(new BranchScope` declarations
        // in app/Models/*.php (top-level only, matching the sister sentinel).
        $modelsDir = app_path('Models');
        $finder = (new Finder())->files()->in($modelsDir)->name('*.php')->depth(0);

        $declared = 0;
        $declaringClasses = [];
        foreach ($finder as $file) {
            $source = file_get_contents($file->getRealPath());
            if (preg_match('/addGlobalScope\s*\(\s*new\s+BranchScope/', $source)) {
                $declared++;
                $declaringClasses[] = $file->getBasename('.php');
            }
        }
        sort($declaringClasses);

        $this->assertSame(
            $declared,
            $claimedCount,
            sprintf(
                "CLAUDE.md §9 claims `BranchScope` global on **%d models** but\n"
                . "the codebase declares `addGlobalScope(new BranchScope)` on **%d models**.\n\n"
                . "Models that actually declare BranchScope (top-level app/Models/*.php):\n  - %s\n\n"
                . "Heal: either edit CLAUDE.md §9 to the correct count, or add/remove\n"
                . "BranchScope declaration in the offending model. This sentinel locks\n"
                . "the doc-code coherence at PR time (WJ-7 WI-7).",
                $claimedCount,
                $declared,
                implode("\n  - ", $declaringClasses)
            )
        );
    }

    public function test_claude_md_section_9_lists_customer_not_user_as_exempt(): void
    {
        $contents = file_get_contents(base_path('CLAUDE.md'));

        // Per `BranchScopeCoverageSentinelTest::EXEMPTED_MODELS`, the
        // documented Sanctum-recursion-exempt model is Customer, NOT User.
        // §9 MUST mention Customer in its exemption block, MUST NOT claim
        // "User model exempté".

        $this->assertStringContainsString(
            'Customer',
            $contents,
            "CLAUDE.md §9 MUST mention `Customer` as the Sanctum-recursion\n"
            . "exemption (per BranchScopeCoverageSentinelTest::EXEMPTED_MODELS)."
        );

        // The exact stale phrase from the pre-WI-7 §9 must NOT come back.
        $this->assertStringNotContainsString(
            'User model exempté',
            $contents,
            "CLAUDE.md §9 contains the stale claim 'User model exempté' — User IS\n"
            . "scoped (app/Models/User.php declares addGlobalScope(new BranchScope())).\n"
            . "Customer is the recursion-exempt model. WJ-7 WI-7 healed this drift."
        );
    }
}
