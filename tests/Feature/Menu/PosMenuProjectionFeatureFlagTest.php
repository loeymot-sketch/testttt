<?php

namespace Tests\Feature\Menu;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Sentinel — Mission #1 Vague 2 action 2.1.
 *
 * Asserts that the PosMenuProjection service correctly dispatches between
 * legacy / shadow_compare / unified modes based on config flags and
 * kill-switch.
 *
 * Audit: reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_1_CATALOG_SYNC_2026-05-02.md §D Vague 2
 * Plan : plans/PLAN_CV1-CATALOG-CONVERGENCE-001_2026-05-02.md tasks 2.1, 2.2, 2.6
 *
 * Contract once unskipped:
 *
 *   1. Default config (all flags false) → mode() === 'legacy', forBranch
 *      returns the legacy builder result verbatim.
 *
 *   2. unified_projection.shadow_compare = true → mode() === 'shadow_compare',
 *      forBranch still returns legacy but a [catalog.shadow-diff] log entry
 *      is written if the unified payload differs structurally.
 *
 *   3. unified_projection.enabled = true → mode() === 'unified', forBranch
 *      returns the adapted unified payload, structurally compatible with
 *      legacy clients.
 *
 *   4. kill_switch = true overrides every other flag and forces legacy.
 *
 *   5. If MenuProjectionService::forChannel throws, shadow_compare logs
 *      [catalog.shadow-compare-failed] but the legacy response is still
 *      returned (no live impact).
 */
class PosMenuProjectionFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_mode_is_legacy(): void
    {
        $this->markTestSkipped('Pending plan task 2.1 (PLAN_CV1-CATALOG-CONVERGENCE-001).');
    }

    public function test_shadow_compare_returns_legacy_and_logs_diff(): void
    {
        $this->markTestSkipped('Pending plan task 2.5 (PLAN_CV1-CATALOG-CONVERGENCE-001).');
    }

    public function test_unified_mode_returns_adapted_payload(): void
    {
        $this->markTestSkipped('Pending plan task 2.2 (PLAN_CV1-CATALOG-CONVERGENCE-001).');
    }

    public function test_kill_switch_forces_legacy(): void
    {
        $this->markTestSkipped('Pending plan task 2.6 (PLAN_CV1-CATALOG-CONVERGENCE-001).');
    }

    public function test_unified_failure_does_not_break_legacy(): void
    {
        $this->markTestSkipped('Pending plan task 2.5 (PLAN_CV1-CATALOG-CONVERGENCE-001).');
    }
}
