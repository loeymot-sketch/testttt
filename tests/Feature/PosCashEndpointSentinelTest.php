<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * @FK-ID FK-023/FK-042
 * @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md
 * @fix-mission CV1-LOT-P07-KIOSK-CASH-DECOUPLE
 */
class PosCashEndpointSentinelTest extends TestCase
{
    public function test_pos_kiosk_cash_collection_uses_dedicated_pos_route(): void
    {
        $route = Route::getRoutes()->getByName('admin.pos.collect-kiosk-cash');

        $this->assertNotNull($route, 'Expected dedicated POS kiosk cash collection route.');
        $this->assertSame('api/admin/pos/collect-kiosk-cash/{order}', $route->uri());
        $this->assertStringContainsString("OrderService::class)->collectKioskCash", file_get_contents(base_path('routes/api.php')));
    }
}
