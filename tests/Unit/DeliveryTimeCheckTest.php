<?php

namespace Tests\Unit;

use App\Libraries\AppLibrary;
use Carbon\Carbon;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [VG-2026-10-21 M6-001] `AppLibrary::deliveryTimeCheck()` alimente `delivery_time`
 * de `KDSOrderDetailsResource` (écran cuisine, `kds-order`, `kds-order/sync`,
 * `history-today`). Le site envoie `delivery_time = 'ASAP'` pour une livraison
 * « dès que possible » (`funnel.jsx`) : `[$a, $b] = explode(' - ', 'ASAP')` levait
 * « Undefined array key 1 » → 500 sur TOUT l'écran cuisine tant que la commande
 * restait ouverte (aucune voie de secours : la synchro passe par la même ressource).
 *
 * Second défaut de la même fonction : un créneau qui traverse minuit
 * (« 23:47 - 00:17 ») n'était JAMAIS « Now », car la fin (00:17 du jour) précède
 * le début. Test Unit pur (pas de base) : réglages simulés, horloge figée.
 */
class DeliveryTimeCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Settings::shouldReceive('group')->andReturn(new class {
            public function get($key)
            {
                return 30;
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function horloge(string $hm): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 ' . $hm . ':00'));
    }

    public function test_asap_never_throws_and_reads_now(): void
    {
        $this->horloge('12:00');
        $this->assertSame('Now', AppLibrary::deliveryTimeCheck('ASAP'));
        $this->assertSame('Now', AppLibrary::deliveryTimeCheck('asap'));
    }

    public function test_unparseable_value_returns_empty_instead_of_throwing(): void
    {
        $this->horloge('12:00');
        $this->assertSame('', AppLibrary::deliveryTimeCheck('n\'importe quoi'));
        $this->assertSame('', AppLibrary::deliveryTimeCheck('12:00'));
    }

    public function test_empty_value_returns_empty(): void
    {
        $this->assertSame('', AppLibrary::deliveryTimeCheck(null));
        $this->assertSame('', AppLibrary::deliveryTimeCheck(''));
    }

    public function test_slot_inside_window_reads_now(): void
    {
        $this->horloge('12:10');
        $this->assertSame('Now', AppLibrary::deliveryTimeCheck('12:30 - 13:00'), '20 min avant le début : dans la fenêtre de 30 min');
        $this->horloge('12:59');
        $this->assertSame('Now', AppLibrary::deliveryTimeCheck('12:30 - 13:00'));
    }

    public function test_slot_outside_window_is_formatted(): void
    {
        $this->horloge('10:00');
        $this->assertSame('12:30 - 13:00', AppLibrary::deliveryTimeCheck('12:30 - 13:00'));
        $this->horloge('13:01');
        $this->assertSame('12:30 - 13:00', AppLibrary::deliveryTimeCheck('12:30 - 13:00'));
    }

    public function test_slot_crossing_midnight_reads_now_on_both_sides(): void
    {
        $this->horloge('23:50');
        $this->assertSame('Now', AppLibrary::deliveryTimeCheck('23:47 - 00:17'), 'avant minuit, dans le créneau');
        $this->horloge('00:10');
        $this->assertSame('Now', AppLibrary::deliveryTimeCheck('23:47 - 00:17'), 'après minuit, dans le créneau');
        $this->horloge('12:00');
        $this->assertSame('23:47 - 00:17', AppLibrary::deliveryTimeCheck('23:47 - 00:17'), 'loin du créneau');
    }

    public function test_window_opening_before_midnight_for_a_slot_starting_after_midnight(): void
    {
        $this->horloge('23:50');
        $this->assertSame('Now', AppLibrary::deliveryTimeCheck('00:10 - 00:40'), 'la fenêtre de 30 min ouvre à 23:40 la veille');
    }
}
