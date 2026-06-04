<?php

/**
 * [GOAL_SECOND_DEGREE_INDIRECT 2026-06-01 — CREDBAL-NET-01 heal]
 *
 * The Credit Balance Excel export (a customer store-credit LIABILITY register)
 * was built from UserService::list($request) with the SAME request the UI sends
 * — which carries paginate=1 / per_page=10. UserService::list returns a
 * paginated set when paginate==1, so the export silently truncated to the first
 * page (10 rows), under-reporting outstanding credit liability.
 *
 * Heal: the export forces a non-paginated fetch (paginate=0) regardless of the
 * UI pagination params, so the register is always complete.
 *
 * @group sentinel
 * @group reports
 */

namespace Tests\Feature\Admin;

use App\Exports\CreditBalanceReportExport;
use App\Http\Requests\PaginateRequest;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditBalanceExportFullFetchSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_export_returns_all_rows_not_just_the_first_paginated_page(): void
    {
        // 15 users with store credit > one page (per_page=10).
        User::factory()->count(15)->create(['balance' => 12.50]);

        $expectedTotal = User::query()->count();
        $this->assertGreaterThan(10, $expectedTotal, 'precondition: more than one page of users');

        // UI sends paginate=1 / per_page=10 — the export MUST ignore that.
        $request = PaginateRequest::create('/', 'GET', ['paginate' => 1, 'per_page' => 10]);
        $export  = new CreditBalanceReportExport(app(UserService::class), $request);

        $rows = $export->collection();

        $this->assertSame(
            $expectedTotal,
            $rows->count(),
            'Credit-balance export must include the FULL register, not the first paginated page (10).'
        );
    }
}
