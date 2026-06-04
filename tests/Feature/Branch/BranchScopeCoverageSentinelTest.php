<?php

namespace Tests\Feature\Branch;

use App\Models\Scopes\BranchScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * [GOAL-CMS-2026-05-18 C-P0-E sentinel — Round 2 T-1.3.1 Arch F2 + F1]
 *
 * CI guardrail: every Eloquent model whose underlying table carries a
 * `branch_id` column MUST declare `addGlobalScope(new BranchScope)` —
 * unless it is in the documented exception list below.
 *
 * Round 2 surfaced 11 gap models with `branch_id` that did NOT declare
 * BranchScope, exposing cross-branch read leak (V1 single-tenant
 * unexposed but V2 SaaS hard fail). Round 1 MGMT-P0-B also confirmed
 * that catalog tables (ItemAttribute / ItemExtra without `branch_id`)
 * are GLOBAL by design — this sentinel does NOT flag them.
 *
 * Fail mode without this sentinel: a future contributor adds a new
 * model with `branch_id` column but forgets BranchScope → query path
 * returns cross-tenant rows. Sentinel locks the discipline at PR time.
 *
 * @group branch-scope
 * @group sentinel
 */
class BranchScopeCoverageSentinelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Documented exception list — models that intentionally do NOT declare
     * BranchScope despite having a `branch_id` column. Each MUST have a
     * one-line reason.
     *
     * Pattern mirrors `68b63c090 WAVE 8 — FormRequest authz drift sentinel`
     * baseline-lock approach: count GROWS → CI fails. Count SHRINKS → remove
     * the exemption + add a per-model regression test.
     *
     * V1.0.2 BACKLOG: heal each of the BASELINE_V1_2026-05-18 exemptions and
     * remove them from this list one at a time. Tracked in GOAL-CMS-2026-05-18
     * C-P0-D (Round 2 T-1.3.1 Arch F1).
     */
    private const EXEMPTED_MODELS = [
        // Intentional / architectural exemptions
        'App\\Models\\Branch'        => 'self-reference (BranchScope on Branch would be circular)',
        'App\\Models\\Customer'      => 'Sanctum customer-token recursion risk (per CLAUDE.md §9)',

        // V1.0.2 BACKLOG — temporary baseline exemptions (heal target per
        // Round 2 T-1.3.1 Arch F1 + Round 3 T-1.1.1 DBA F4 for OrderDiscountLog).
        // Each is V1-low-risk (single-tenant) + V2-SaaS-blocker.
        'App\\Models\\FrontendDiningTable'   => 'BASELINE_V1_2026-05-18 — V1.0.2 heal C-P0-D',
        'App\\Models\\ZReport'               => 'BASELINE_V1_2026-05-18 — V1.0.2 heal C-P0-D (fiscal, manual scope today)',
        'App\\Models\\AuditLog'              => 'BASELINE_V1_2026-05-18 — V1.0.2 heal C-P0-D (fiscal, manual scope today)',
        'App\\Models\\OrderDiscountLog'      => 'BASELINE_V1_2026-05-18 — V1.0.2 heal C-P0-D (Round 3 T-1.1.1 DBA F4)',
        'App\\Models\\Message'               => 'BASELINE_V1_2026-05-18 — V1.0.2 heal C-P0-D',
        'App\\Models\\DiningTableAuditLog'   => 'BASELINE_V1_2026-05-18 — V1.0.2 heal C-P0-D',
        'App\\Models\\KioskPromo'            => 'BASELINE_V1_2026-05-18 — V1.0.2 heal C-P0-D',
        'App\\Models\\UpsellRule'            => 'BASELINE_V1_2026-05-18 — V1.0.2 heal C-P0-D',
        'App\\Models\\ActionLog'             => 'BASELINE_V1_2026-05-18 — V1.0.2 heal C-P0-D',
        'App\\Models\\DomainEvent'           => 'BASELINE_V1_2026-05-18 — V1.0.2 heal C-P0-D (Round 2 DBA-002)',
    ];

    public function test_every_model_with_branch_id_column_declares_branch_scope(): void
    {
        $modelsDir = app_path('Models');
        $finder = (new Finder())->files()->in($modelsDir)->name('*.php')->depth(0);

        $offenders = [];

        foreach ($finder as $file) {
            $className = $this->classNameFromFile($file->getRealPath());
            if (! $className || ! class_exists($className)) {
                continue;
            }

            // Skip abstract / trait / non-Eloquent
            $ref = new \ReflectionClass($className);
            if ($ref->isAbstract() || ! $ref->isSubclassOf(\Illuminate\Database\Eloquent\Model::class)) {
                continue;
            }

            // Skip exempted models
            if (array_key_exists($className, self::EXEMPTED_MODELS)) {
                continue;
            }

            try {
                $instance = $ref->newInstance();
            } catch (\Throwable $e) {
                continue; // can't instantiate (constructor requires args, etc.)
            }

            $table = $instance->getTable();
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

            // Read the source — does it declare BranchScope in booted/boot?
            $source = file_get_contents($file->getRealPath());
            $hasBranchScope = (bool) preg_match(
                '/addGlobalScope\s*\(\s*new\s+BranchScope/',
                $source
            );

            if (! $hasBranchScope) {
                $offenders[] = $className;
            }
        }

        $this->assertEmpty(
            $offenders,
            sprintf(
                "Models with `branch_id` column but NO BranchScope declaration:\n  - %s\n"
                . "Either add `static::addGlobalScope(new BranchScope)` to the model's `booted()` "
                . "method, or add to EXEMPTED_MODELS with a one-line reason.",
                implode("\n  - ", $offenders)
            )
        );
    }

    private function classNameFromFile(string $path): ?string
    {
        $relative = str_replace(app_path() . '/', '', $path);
        $relative = preg_replace('/\.php$/', '', $relative);
        return 'App\\' . str_replace('/', '\\', $relative);
    }
}
