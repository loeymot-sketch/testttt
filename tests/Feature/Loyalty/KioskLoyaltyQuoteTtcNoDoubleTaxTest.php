<?php

/**
 * [TERRAIN-HEAL 2026-07-16 · LOYAL-409-TTC] Régression : en mode TTC (défaut FR),
 * OrderQuoteService::withKioskLoyaltyDiscount ajoutait `+ totalTax` au total alors que
 * accumulatedSubtotal inclut DÉJÀ la TVA → total du quote gonflé → sealForCommit 409 =
 * TOUTE commande borne avec redemption fidélité cassée (« le garde ne marchait pas »).
 * Ce sentinel verrouille : total TTC = accumulatedSubtotal + delivery - discount (sans re-TVA).
 *
 * @group loyalty
 * @group sentinel
 */

namespace Tests\Feature\Loyalty;

use App\Models\User;
use App\Services\Order\OrderQuoteService;
use App\Services\Pricing\PricingResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use ReflectionMethod;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

class KioskLoyaltyQuoteTtcNoDoubleTaxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        Settings::group('loyalty_setup')->set([
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points'          => 50,
        ]);
    }

    public function test_ttc_loyalty_quote_total_does_not_double_count_tax(): void
    {
        config(['pricing.tax_inclusive_prices' => true]); // TTC = défaut FR

        $user = User::factory()->create([
            'loyalty_code'   => 'TESTLOY1',
            'loyalty_points' => 100000,
            'status'         => 1,
        ]);

        // PricingResult TTC : accumulatedSubtotal = 10,00 € TTC (TVA 0,91 € DÉJÀ dedans).
        $pricing = new PricingResult([], [], 10.00, 10.00, 0.91, 0.0, 0.0, 10.00, []);

        $request = Request::create('/api/frontend/order/quote', 'POST', [
            'loyalty_code' => 'TESTLOY1',
            'discount'     => 1.00, // 1,00 € = 100 pts
            'coupon_id'    => 0,
        ]);

        $m = new ReflectionMethod(OrderQuoteService::class, 'withKioskLoyaltyDiscount');
        $m->setAccessible(true);
        /** @var PricingResult $out */
        $out = $m->invoke(app(OrderQuoteService::class), $request, $pricing);

        // Correct TTC : 10,00 - 1,00 = 9,00 €. Le bug donnait 10,00 + 0,91 - 1,00 = 9,91 €.
        $this->assertSame(9.00, $out->total, 'Total TTC ne doit PAS re-compter la TVA (9,00 attendu, 9,91 = bug double-TVA).');
        $this->assertSame(1.00, $out->discount);
    }

    public function test_ht_mode_still_adds_tax(): void
    {
        config(['pricing.tax_inclusive_prices' => false]); // legacy HT

        $user = User::factory()->create([
            'loyalty_code'   => 'TESTLOY2',
            'loyalty_points' => 100000,
            'status'         => 1,
        ]);

        // HT : accumulatedSubtotal = 10,00 € HT + TVA 0,91 € à ajouter.
        $pricing = new PricingResult([], [], 10.00, 10.00, 0.91, 0.0, 0.0, 10.91, []);

        $request = Request::create('/q', 'POST', [
            'loyalty_code' => 'TESTLOY2', 'discount' => 1.00, 'coupon_id' => 0,
        ]);

        $m = new ReflectionMethod(OrderQuoteService::class, 'withKioskLoyaltyDiscount');
        $m->setAccessible(true);
        $out = $m->invoke(app(OrderQuoteService::class), $request, $pricing);

        // HT : 10,00 + 0,91 - 1,00 = 9,91 €.
        $this->assertSame(9.91, $out->total, 'Mode HT legacy doit garder + totalTax.');
    }
}
