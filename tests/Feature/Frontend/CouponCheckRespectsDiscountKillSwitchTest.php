<?php

namespace Tests\Feature\Frontend;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [A2 cycle 3 · GOAL_WEB_ADVERSARIAL_UX_TOTAL 2026-08-05]
 *
 * Le site ne doit JAMAIS promettre une remise que la commande refusera.
 *
 * Défaut prouvé en reproduction : `POST /coupon-checking` validait le coupon et renvoyait une
 * remise (le site affichait « ✓ CODE · −3,24 € appliqué » et un total remisé sur le bouton),
 * alors que `FrontendOrderService` refuse toute remise quand le kill-switch
 * `pos.manual_discount_enabled` est à false : « Les remises (coupon) sont désactivées en V1. »
 * Le client arrivait donc au dernier clic sur un mur, sans autre issue que de vider lui-même le
 * champ promo. Promesse écrite non tenue + vente bloquée.
 *
 * La validation consulte désormais le MÊME interrupteur que la commande.
 */
class CouponCheckRespectsDiscountKillSwitchTest extends TestCase
{
    use RefreshDatabase;

    private function checkCoupon(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/frontend/coupon/coupon-checking', [
            'code'  => 'PEUIMPORTE',
            'total' => 25.00,
        ], ['X-API-Key' => config('apikey.key') ?: env('MIX_API_KEY')]);
    }

    /** Remises coupées : on refuse AVANT de promettre quoi que ce soit. */
    public function test_coupon_check_is_refused_when_discounts_are_disabled(): void
    {
        config(['pos.manual_discount_enabled' => false]);

        $response = $this->checkCoupon();

        $this->assertSame(422, $response->status());
        $this->assertStringContainsString(
            'désactiv',
            (string) $response->json('message'),
            'Le refus doit être explicite et en français, pas un coupon silencieusement accepté.'
        );
    }

    /**
     * Remises actives : le kill-switch ne doit RIEN changer au comportement historique —
     * on retombe sur la validation normale (ici : coupon inexistant → refus du service).
     * L'important est que ce ne soit PAS le message du kill-switch.
     */
    public function test_coupon_check_is_unchanged_when_discounts_are_enabled(): void
    {
        config(['pos.manual_discount_enabled' => true]);

        $response = $this->checkCoupon();

        $this->assertNotSame(
            'désactivées',
            (string) $response->json('message'),
            'Le kill-switch ne doit pas court-circuiter la validation quand les remises sont actives.'
        );
        $this->assertStringNotContainsString('désactiv', (string) $response->json('message'));
    }
}
