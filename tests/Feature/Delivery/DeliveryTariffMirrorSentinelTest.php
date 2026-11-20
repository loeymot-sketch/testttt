<?php

namespace Tests\Feature\Delivery;

use Tests\TestCase;

/**
 * Le miroir d'affichage JS du barème (resources/js/helpers/deliveryCharge.js) ne doit JAMAIS diverger de
 * config/delivery.php : un aperçu faux en caisse ou sur le site (« 5 € » affiché, 9 € facturés) a déjà
 * existé quand le barème a changé d'un seul côté. Ce test relit le fichier JS et compare.
 *
 * @group sentinel
 * @group delivery
 */
class DeliveryTariffMirrorSentinelTest extends TestCase
{
    public function test_js_preview_constants_match_the_server_configuration(): void
    {
        $js = file_get_contents(base_path('resources/js/helpers/deliveryCharge.js'));

        $this->assertMatchesRegularExpression('/HOME_FEE\s*=\s*([\d.]+)/', $js);
        preg_match('/HOME_FEE\s*=\s*([\d.]+)/', $js, $m);
        $this->assertEquals((float) config('delivery.home_fee'), (float) $m[1], 'HOME_FEE JS ≠ config/delivery.php');

        preg_match('/MAX_KM\s*=\s*([\d.]+)/', $js, $m);
        $this->assertEquals((float) config('delivery.max_km'), (float) $m[1], 'MAX_KM JS ≠ config/delivery.php');

        preg_match_all('/\{\s*maxKm:\s*([\d.]+),\s*fee:\s*([\d.]+)\s*\}/', $js, $tiers, PREG_SET_ORDER);
        $jsTiers = array_map(fn ($t) => [(float) $t[1], (float) $t[2]], $tiers);
        $cfgTiers = array_map(fn ($t) => [(float) $t['max_km'], (float) $t['fee']], config('delivery.tiers'));
        $this->assertEquals($cfgTiers, $jsTiers, 'Paliers JS ≠ config/delivery.php');

        preg_match("/HOME_CITY\s*=\s*'([^']+)'/u", $js, $m);
        $this->assertSame(config('delivery.home_city'), $m[1]);
        preg_match("/HOME_POSTAL_CODES\s*=\s*\[([^\]]*)\]/", $js, $m);
        $this->assertSame(config('delivery.home_postal_codes'), array_map(fn ($c) => trim($c, " '\""), explode(',', $m[1])));
    }
}
