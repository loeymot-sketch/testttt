<?php

namespace Tests\Feature\Frontend;

use App\Http\Controllers\Frontend\LoyaltyController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [A6-P1-1 · GOAL_WEB_ADVERSARIAL_UX_TOTAL 2026-08-05]
 *
 * Le seuil ANNONCÉ au client doit être le seuil RÉELLEMENT utilisable.
 *
 * Avant ce test : `/api/frontend/loyalty/config` renvoyait brut le réglage
 * `loyalty_min_redeem_points` (= 50), alors que les DEUX surfaces qui débitent
 * réellement des points exigent un MULTIPLE du taux (100) :
 *   - LoyaltyController::redeem  → `if ($pointsToRedeem % $rate !== 0)` rejet
 *   - PosRedemptionService       → même règle en caisse
 * Un client à 60 points lisait donc « utilisables dès 50 points » et se voyait
 * refuser sa remise au comptoir. Le seuil publié est désormais le plancher
 * EFFECTIF = premier multiple du taux ≥ réglage.
 */
class LoyaltyConfigEffectiveFloorTest extends TestCase
{
    use RefreshDatabase;

    private function configData(): array
    {
        $response = (new LoyaltyController())->config(Request::create('/api/frontend/loyalty/config'));

        return json_decode($response->getContent(), true)['data'];
    }

    private function setLoyalty(int $minRedeem, int $rate): void
    {
        Settings::group('loyalty_setup')->set([
            'loyalty_min_redeem_points'         => $minRedeem,
            'loyalty_points_for_1_euro_discount' => $rate,
        ]);
    }

    /** Le cas réel de production : réglage 50, taux 100 → on annonce 100, pas 50. */
    public function test_publishes_the_effective_floor_not_the_raw_setting(): void
    {
        $this->setLoyalty(minRedeem: 50, rate: 100);

        $this->assertSame(100, $this->configData()['min_redeem_points']);
    }

    /** Un réglage déjà multiple du taux est publié tel quel (aucune inflation). */
    public function test_leaves_a_setting_already_multiple_of_the_rate_untouched(): void
    {
        $this->setLoyalty(minRedeem: 300, rate: 100);

        $this->assertSame(300, $this->configData()['min_redeem_points']);
    }

    /** Un réglage plus HAUT que le taux mais non multiple monte au multiple suivant. */
    public function test_rounds_a_non_multiple_setting_up_to_the_next_multiple(): void
    {
        $this->setLoyalty(minRedeem: 250, rate: 200);

        $this->assertSame(400, $this->configData()['min_redeem_points']);
    }

    /** Le plancher publié est toujours réellement débitable : jamais 0, jamais sous le taux. */
    public function test_floor_is_never_below_one_redeemable_unit(): void
    {
        $this->setLoyalty(minRedeem: 0, rate: 100);

        $this->assertSame(100, $this->configData()['min_redeem_points']);
    }
}
