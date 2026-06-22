<?php

namespace Tests\Feature\Sentinels;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * @FK-ID FK-023/FK-042 | @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md | @fix-mission CV1-M06-POS-REVENUE-GUARDS | @reason POS kiosk cash collection must use a dedicated endpoint, not KDS change-status.
 */
class PosCashEndpointSentinelTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_has_dedicated_collect_kiosk_cash_route(): void
    {
        $this->assertTrue(
            Route::has('admin.pos.collect-kiosk-cash'),
            'Expected route name admin.pos.collect-kiosk-cash for POS kiosk cash collection.'
        );
    }
}
