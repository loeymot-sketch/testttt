<?php

namespace Tests\Feature\Sentinels;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL LOYALTY_UNIFIED_SYNC L3 2026-06-11 — SENTINEL PERMANENT]
 *
 * Le drift fidélité 10-vs-1 pt/€ (backend créditait 10× ce que les apps
 * clientes promettaient — e2e loyalty-global 2026-06-10) ne doit JAMAIS
 * revenir silencieusement. Ce sentinel lit les 3 sources :
 *   1. fallbacks backend (resource + controller + listener)
 *   2. miroir mobile  mobile/data/loyalty.js
 *   3. miroir web     /Users/1millnonstop/Downloads/web/data/loyalty.js
 * et FAIL si les barèmes divergent. Le miroir web vit hors repo : absent
 * (CI d'une autre machine) → skip marqué, jamais un faux vert.
 */
class LoyaltyRateParitySentinelTest extends TestCase
{
    use RefreshDatabase;

    private const CANON_EARN = 1;     // pt par €
    private const CANON_REDEEM = 100; // pts pour 1 €
    private const CANON_MIN = 100;    // minimum d'utilisation

    public function test_backend_fallbacks_hold_the_canonical_rates(): void
    {
        $resource = file_get_contents(app_path('Http/Resources/LoyaltySetupResource.php'));
        $this->assertStringContainsString("?? 1)", $resource, 'resource earn fallback');
        $this->assertStringContainsString("?? 100)", $resource, 'resource min fallback');

        $controller = file_get_contents(app_path('Http/Controllers/Frontend/LoyaltyController.php'));
        $this->assertStringContainsString("get('loyalty_points_per_euro', 1)", $controller);
        $this->assertStringContainsString("get('loyalty_min_redeem_points', 100)", $controller);

        $listener = file_get_contents(app_path('Listeners/AwardLoyaltyPointsOnDelivery.php'));
        $this->assertStringContainsString("get('loyalty_points_per_euro', 1)", $listener);
        $this->assertStringContainsString('round($orderTotal * $rate)', $listener, 'earn rounding = round');
    }

    public function test_mobile_mirror_matches_canon(): void
    {
        $path = base_path('mobile/data/loyalty.js');
        $this->assertFileExists($path);
        $src = file_get_contents($path);

        $this->assertMatchesRegularExpression('/earn_ratio\s*:\s*' . self::CANON_EARN . '\b/', $src, 'mobile earn_ratio');
        $this->assertMatchesRegularExpression('/redeem_ratio\s*:\s*' . self::CANON_REDEEM . '\b/', $src, 'mobile redeem_ratio');
        $this->assertMatchesRegularExpression('/min_redeem_points\s*:\s*' . self::CANON_MIN . '\b/', $src, 'mobile min');
    }

    public function test_web_mirror_matches_canon(): void
    {
        $path = '/Users/1millnonstop/Downloads/web/data/loyalty.js';
        if (! file_exists($path)) {
            $this->markTestSkipped('web standalone repo absent on this machine — parity not verifiable here');
        }
        $src = file_get_contents($path);

        $this->assertMatchesRegularExpression('/earn_ratio\s*:\s*' . self::CANON_EARN . '\b/', $src, 'web earn_ratio');
        $this->assertMatchesRegularExpression('/redeem_ratio\s*:\s*' . self::CANON_REDEEM . '\b/', $src, 'web redeem_ratio');
        $this->assertMatchesRegularExpression('/min_redeem_points\s*:\s*' . self::CANON_MIN . '\b/', $src, 'web min');
    }
}
