<?php

namespace Tests\Feature\Loyalty;

use App\Events\SettingsUpdated;
use App\Http\Requests\LoyaltySetupRequest;
use App\Services\LoyaltySetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [W-REM T-R3.3 F3-03 2026-06-12] LoyaltySetupService::update() ne
 * dispatchait PAS SettingsUpdated — contrairement à Currency/Tax/Company/
 * OrderSetup (pattern Wave 5G R9). Conséquence : un changement de barème
 * fidélité en admin ne se propageait pas live aux surfaces POS/Kiosk
 * abonnées à `private-branch.{id}` (elles gardaient l'ancien barème
 * jusqu'au reload complet).
 */
class LoyaltySetupSettingsUpdatedTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_dispatches_settings_updated_with_loyalty_setup_key(): void
    {
        Event::fake([SettingsUpdated::class]);

        $request = LoyaltySetupRequest::create('/api/admin/setting/loyalty-setup', 'PUT', [
            'loyalty_points_per_euro'             => 2,
            'loyalty_points_for_1_euro_discount'  => 200,
            'loyalty_min_redeem_points'           => 150,
        ]);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        app(LoyaltySetupService::class)->update($request);

        Event::assertDispatched(SettingsUpdated::class, function (SettingsUpdated $event) {
            return in_array('loyalty_setup', $event->changedKeys, true);
        });

        // Le set a bien eu lieu (le dispatch ne remplace pas l'écriture).
        $this->assertSame(2, (int) Settings::group('loyalty_setup')->get('loyalty_points_per_euro'));
    }
}
