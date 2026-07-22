<?php

namespace Tests\Unit\Services\Pricing;

use App\Services\Pricing\PricingService;
use Tests\TestCase;

/**
 * [test-e2e/borne E-001 fix 2026-05-10] Unit coverage for
 * PricingService::menuRoleAdjustedAddonPrice — kiosk menu-formula ratio
 * resolution.
 *
 * The frontend `kioskPricing.js` applies `config('kiosk.menu_pricing')`
 * ratios to the parent "Menu (Frites + Boisson)" addon. This helper
 * mirrors the same math server-side so SSOT holds. Coverage targets:
 *  - All three menu_* roles resolve to the matching ratio'd price
 *  - Legacy roles ('drink', 'side', ...) and empty role pass through
 *    untouched (no ratio applied — backward compat with non-kiosk callers)
 *  - Unknown menu_* roles default to ratio=1.0 (forward-compat: a future
 *    `menu_xl` would be charged at full price until backend learns it)
 *  - Negative/NaN ratios are clamped to 1.0 (defense vs config typo)
 */
class MenuRoleAdjustedAddonPriceTest extends TestCase
{
    /**
     * Default ratios from config/kiosk.php — keep in sync.
     */
    private const RATIO_FULL = 1.0;
    private const RATIO_FRIES = 0.76; // [G-PRIX 2026-07-22]
    private const RATIO_DRINK = 0.76; // [G-PRIX 2026-07-22]

    public function test_menu_boisson_role_applies_drink_ratio(): void
    {
        $svc = new PricingService();
        $this->assertSame(
            round(3.0 * self::RATIO_DRINK, 2),
            $svc->menuRoleAdjustedAddonPrice('menu_boisson', 3.0)
        );
    }

    public function test_menu_frites_role_applies_fries_ratio(): void
    {
        $svc = new PricingService();
        $this->assertSame(
            round(3.0 * self::RATIO_FRIES, 2),
            $svc->menuRoleAdjustedAddonPrice('menu_frites', 3.0)
        );
    }

    public function test_menu_full_role_applies_full_ratio(): void
    {
        $svc = new PricingService();
        $this->assertSame(
            round(3.0 * self::RATIO_FULL, 2),
            $svc->menuRoleAdjustedAddonPrice('menu_full', 3.0)
        );
    }

    public function test_legacy_role_drink_passes_through_unchanged(): void
    {
        $svc = new PricingService();
        $this->assertSame(3.0, $svc->menuRoleAdjustedAddonPrice('drink', 3.0));
    }

    public function test_empty_role_passes_through_unchanged(): void
    {
        $svc = new PricingService();
        $this->assertSame(3.0, $svc->menuRoleAdjustedAddonPrice('', 3.0));
    }

    public function test_unknown_menu_role_defaults_to_full_price(): void
    {
        $svc = new PricingService();
        $this->assertSame(3.0, $svc->menuRoleAdjustedAddonPrice('menu_unknown_variant', 3.0));
    }

    public function test_role_is_case_insensitive(): void
    {
        $svc = new PricingService();
        $this->assertSame(
            round(3.0 * self::RATIO_DRINK, 2),
            $svc->menuRoleAdjustedAddonPrice('MENU_BOISSON', 3.0)
        );
    }

    public function test_zero_full_price_returns_zero(): void
    {
        $svc = new PricingService();
        $this->assertSame(0.0, $svc->menuRoleAdjustedAddonPrice('menu_boisson', 0.0));
    }

    public function test_negative_ratio_in_config_clamps_to_one(): void
    {
        $svc = new PricingService();
        config(['kiosk.menu_pricing.drink_ratio' => -0.5]);
        $this->assertSame(3.0, $svc->menuRoleAdjustedAddonPrice('menu_boisson', 3.0));
    }

    public function test_ratio_result_rounded_to_two_decimals(): void
    {
        $svc = new PricingService();
        // 7.77 × 0.76 = 5.9052 → rounded to 5.91 [G-PRIX 2026-07-22]
        $this->assertSame(5.91, $svc->menuRoleAdjustedAddonPrice('menu_boisson', 7.77));
    }

    /**
     * E-001 regression: Salade Royale + Coca formule had a backend total
     * of 22.50€ vs frontend cart 23.70€ (delta = 1.20€ = 3.00 × drinkRatio).
     * Post-fix the backend must compute 1.20€ for the role='menu_boisson'
     * addon row carrying addonItem.price=3.00€.
     */
    public function test_e001_regression_salade_royale_menu_boisson(): void
    {
        $svc = new PricingService();
        // Addon id=148 on item 393 has addonItem.price=3.00€.
        // menuChoice='boisson' → role='menu_boisson' → drinkRatio=0.76 → 2.28€ [G-PRIX 2026-07-22].
        $expected = 2.28;
        $this->assertSame(
            $expected,
            $svc->menuRoleAdjustedAddonPrice('menu_boisson', 3.0)
        );
    }
}
