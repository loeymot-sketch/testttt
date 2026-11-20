<?php

namespace Tests\Unit\Services;

use App\Exceptions\Delivery\OutsideDeliveryZoneException;
use App\Services\Delivery\DeliveryFeeService;
use Tests\TestCase;

/**
 * [LIVRAISON 2026-10-20] Barème propriétaire (config/delivery.php) : Hénin-Beaumont 3,90 ·
 * ≤5 km 4,90 · ≤8 km 5,90 · ≤10 km 6,90 · au-delà refusé. Remplace la règle V1 « max(5, ceil(d/5)*5) ».
 */
class DeliveryFeeServiceTest extends TestCase
{
    public function test_foodking_v1_delivery_fee_rule(): void
    {
        $service = new DeliveryFeeService();

        // Hors commune : bornes INCLUSIVES.
        $this->assertSame(4.9, $service->fromDistanceKm(0, null, 'Oignies', '62590'));
        $this->assertSame(4.9, $service->fromDistanceKm(5, null, 'Oignies', '62590'));
        $this->assertSame(5.9, $service->fromDistanceKm(5.01, null, 'Oignies', '62590'));
        $this->assertSame(5.9, $service->fromDistanceKm(8, null, 'Oignies', '62590'));
        $this->assertSame(6.9, $service->fromDistanceKm(8.01, null, 'Oignies', '62590'));
        $this->assertSame(6.9, $service->fromDistanceKm(10, null, 'Oignies', '62590'));

        // Hénin-Beaumont (ville ou code postal) : forfait unique 3,90 quelle que soit la distance.
        $this->assertSame(3.9, $service->fromDistanceKm(0, null, 'Hénin-Beaumont', null));
        $this->assertSame(3.9, $service->fromDistanceKm(7, null, null, '62110'));
    }

    public function test_beyond_ten_km_is_refused_with_uber_eats_suggestion(): void
    {
        try {
            (new DeliveryFeeService())->fromDistanceKm(10.01, null, 'Lens', '62300');
            $this->fail('OutsideDeliveryZoneException attendue au-delà de 10 km.');
        } catch (OutsideDeliveryZoneException $e) {
            $this->assertTrue($e->suggestUberEats);
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_unreadable_distance_is_refused_never_priced_at_zero(): void
    {
        $service = new DeliveryFeeService();

        foreach ([-1, 'not-a-number'] as $bad) {
            try {
                $service->fromDistanceKm($bad, null, 'Lens', '62300');
                $this->fail('Une distance illisible ne doit jamais produire un prix.');
            } catch (OutsideDeliveryZoneException $e) {
                $this->assertFalse($e->suggestUberEats);
            }
        }
    }
}
