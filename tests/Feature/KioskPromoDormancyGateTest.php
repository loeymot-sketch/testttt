<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\KioskPromo;
use App\Services\Kiosk\KioskPromoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @FK-ID BORNE-PROMO-01 (P1, ultra-audit 2026-06-10) | LOT D quick-win
 *
 * The kiosk promo chain was a PHANTOM: promo/validate said valid:true,
 * pricing/preview showed the discounted total (€2,00), the catalog banner
 * advertised the code — but order/quote + FrontendOrderService NEVER resolve
 * kiosk_promo_code into a discount (OrderQuoteService:416 copies it as
 * non-financial metadata only), so the customer was charged full price
 * (€7,00) after seeing €2,00. Until the order-path wiring is owner-gated in
 * (G7, NF525-adjacent), the WHOLE display chain must stay dormant:
 * `kiosk.promos_redeemable=false` (default) ⇒ validate refuses, the menu
 * payload stops projecting promo banners, and the pricing preview applies
 * zero promo discount — full price everywhere, no contradiction.
 */
class KioskPromoDormancyGateTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->branch = Branch::factory()->create();

        KioskPromo::create([
            'branch_id'  => $this->branch->id,
            'code'       => 'BORNEAUDIT5',
            'type'       => 'amount',
            'value'      => 5.0,
            'min_cart'   => 0,
            'valid_from' => now()->subDay(),
            'valid_to'   => now()->addDay(),
            'active'     => true,
        ]);
    }

    public function test_default_flag_is_off(): void
    {
        $this->assertFalse(
            (bool) config('kiosk.promos_redeemable'),
            'kiosk.promos_redeemable MUST default to false until G7 wires the order path.'
        );
    }

    public function test_validate_refuses_any_code_while_dormant(): void
    {
        config(['kiosk.promos_redeemable' => false]);

        $result = app(KioskPromoService::class)->validate($this->branch->id, 'BORNEAUDIT5', 7.0);

        $this->assertFalse($result['valid']);
        $this->assertSame(0.0, (float) $result['discount_amount']);
        $this->assertNotNull($result['message'], 'A French explanation message is required.');
    }

    public function test_validate_works_again_when_flag_enabled(): void
    {
        config(['kiosk.promos_redeemable' => true]);

        $result = app(KioskPromoService::class)->validate($this->branch->id, 'BORNEAUDIT5', 7.0);

        $this->assertTrue($result['valid']);
        $this->assertSame(5.0, (float) $result['discount_amount']);
    }
}
