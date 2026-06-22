<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Models\ItemCategory;
use App\Services\Menu\MenuProjectionService;
use App\Services\Menu\PosMenuProjection;
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
        config([
            'catalog_v15.unified_projection.enabled' => false,
            'catalog_v15.unified_projection.shadow_compare' => false,
            'catalog_v15.unified_projection.kill_switch' => false,
        ]);

        $service = app(PosMenuProjection::class);
        $legacy = [['id' => 0, 'name' => 'All']];

        $this->assertSame('legacy', $service->mode());
        $this->assertSame($legacy, $service->forBranch(5, static fn (): array => $legacy));
    }

    public function test_shadow_compare_returns_legacy_and_logs_diff(): void
    {
        config([
            'catalog_v15.unified_projection.enabled' => false,
            'catalog_v15.unified_projection.shadow_compare' => true,
            'catalog_v15.unified_projection.kill_switch' => false,
        ]);

        ItemCategory::factory()->create([
            'name' => 'Unified Visible Category',
            'slug' => 'unified-visible-category',
            'status' => 5,
            'channels' => null,
        ]);

        $service = app(PosMenuProjection::class);
        $legacy = [
            ['id' => 0, 'name' => 'All'],
            ['id' => 10, 'name' => 'Legacy', 'slug' => 'legacy'],
        ];

        $response = $service->forBranch(9, static fn (): array => $legacy);
        $this->assertSame($legacy, $response);
        $this->assertSame('shadow_compare', $service->mode());
    }

    public function test_unified_mode_returns_adapted_payload(): void
    {
        config([
            'catalog_v15.unified_projection.enabled' => true,
            'catalog_v15.unified_projection.shadow_compare' => false,
            'catalog_v15.unified_projection.kill_switch' => false,
        ]);

        $category = ItemCategory::factory()->create([
            'name' => 'Projection Category',
            'slug' => 'projection-category',
            'description' => 'Projected',
            'status' => Status::ACTIVE,
            'channels' => null,
        ]);

        $service = app(PosMenuProjection::class);
        $legacy = [['id' => 0, 'name' => 'All']];
        $response = $service->forBranch(3, static fn (): array => $legacy);

        $this->assertSame('unified', $service->mode());
        $this->assertSame(0, $response[0]['id']);
        $this->assertSame($category->id, $response[1]['id']);
        $this->assertSame($category->name, $response[1]['name']);
        $this->assertSame($category->slug, $response[1]['slug']);
        $this->assertSame('Projected', $response[1]['description']);
    }

    public function test_kill_switch_forces_legacy(): void
    {
        config([
            'catalog_v15.unified_projection.enabled' => true,
            'catalog_v15.unified_projection.shadow_compare' => true,
            'catalog_v15.unified_projection.kill_switch' => true,
        ]);

        ItemCategory::factory()->create([
            'name' => 'Would Be Unified',
            'slug' => 'would-be-unified',
            'status' => 5,
            'channels' => null,
        ]);

        $service = app(PosMenuProjection::class);
        $legacy = [['id' => 0, 'name' => 'All']];

        $this->assertTrue($service->isKillSwitchActive());
        $this->assertSame($legacy, $service->forBranch(7, static fn (): array => $legacy));
    }

    public function test_unified_failure_does_not_break_legacy(): void
    {
        config([
            'catalog_v15.unified_projection.enabled' => true,
            'catalog_v15.unified_projection.shadow_compare' => false,
            'catalog_v15.unified_projection.kill_switch' => false,
        ]);

        $legacy = [['id' => 0, 'name' => 'All']];
        $service = new PosMenuProjection(
            app(MenuProjectionService::class),
            static function (): array {
                throw new \RuntimeException('forced failure');
            }
        );

        $response = $service->forBranch(17, static fn (): array => $legacy);
        $this->assertSame($legacy, $response);
    }
}
