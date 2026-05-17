<?php

namespace Tests\Feature\Delivery;

use App\Enums\Status;
use App\Models\Branch;
use App\Services\BranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * [Sprint H3 DEL-7 2026-05-17] BranchService::showByLatLong silently
 * excluded branches without a `zone` polygon — customers landing on an
 * un-configured branch hit out-of-service-area with no operator signal.
 * The patch keeps the exclusion (correctness) but logs a warning with
 * the excluded count so ops can surface config defects. CLAUDE.md §9.
 */
class BranchZoneFallbackTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function test_warning_logged_when_active_branches_have_no_zone(): void
    {
        // 1 branch WITH a polygon zone
        Branch::factory()->create([
            'status' => Status::ACTIVE,
            'zone'   => json_encode([
                ['lat' => 48.0, 'lng' => 2.0],
                ['lat' => 48.1, 'lng' => 2.0],
                ['lat' => 48.1, 'lng' => 2.1],
                ['lat' => 48.0, 'lng' => 2.1],
            ]),
        ]);
        // 2 branches WITHOUT a zone (silent exclusion target)
        Branch::factory()->count(2)->create([
            'status' => Status::ACTIVE,
            'zone'   => null,
        ]);

        Log::spy();

        $service = app(BranchService::class);
        $request = Request::create('/branch-by-latlong', 'POST', [
            'latitude'  => 49.0, // Outside polygon — triggers throw, that's OK
            'longitude' => 3.0,
        ]);

        try {
            $service->showByLatLong($request);
        } catch (\Throwable $e) {
            // Out-of-service-area expected — irrelevant to this assertion
        }

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context) {
                return str_contains($message, '[DEL-7]')
                    && ($context['event'] ?? null) === 'branch.zone.missing_polygon'
                    && ($context['excluded_count'] ?? 0) === 2
                    && ($context['with_zone_count'] ?? 0) === 1;
            })
            ->atLeast()
            ->once();
    }

    /** @test */
    public function test_no_warning_when_all_active_branches_have_zone(): void
    {
        Branch::factory()->create([
            'status' => Status::ACTIVE,
            'zone'   => json_encode([
                ['lat' => 48.0, 'lng' => 2.0],
                ['lat' => 48.1, 'lng' => 2.1],
            ]),
        ]);

        Log::spy();

        $service = app(BranchService::class);
        $request = Request::create('/branch-by-latlong', 'POST', [
            'latitude'  => 49.0,
            'longitude' => 3.0,
        ]);

        try {
            $service->showByLatLong($request);
        } catch (\Throwable $e) {
            // OK
        }

        Log::shouldNotHaveReceived('warning', [\Mockery::pattern('/DEL-7/')]);
    }
}
