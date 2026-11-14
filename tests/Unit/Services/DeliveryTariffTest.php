<?php

namespace Tests\Unit\Services;

use App\Services\Delivery\DeliveryTariff;
use Tests\TestCase;

/**
 * Barème propriétaire 2026-10-20 : Hénin-Beaumont 3,90 · ≤5 km 4,90 · ≤8 km 5,90 · ≤10 km 6,90 ·
 * au-delà refusé (Uber Eats). Les bornes sont INCLUSIVES (5,00 km = 4,90 ; 5,01 km = 5,90).
 *
 * @group delivery
 */
class DeliveryTariffTest extends TestCase
{
    private DeliveryTariff $tariff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tariff = new DeliveryTariff();
    }

    public function test_home_city_is_flat_3_90_whatever_the_distance(): void
    {
        foreach ([0.0, 1.2, 4.9, 7.0] as $km) {
            $q = $this->tariff->quote($km, 'Hénin-Beaumont', '62110');
            $this->assertTrue($q['allowed']);
            $this->assertSame(390, $q['fee_cents']);
            $this->assertSame(3.9, $q['fee']);
            $this->assertSame('home', $q['tier']);
        }
    }

    public function test_home_city_is_recognised_by_postal_code_or_by_any_spelling(): void
    {
        $this->assertSame('home', $this->tariff->quote(2, null, '62110')['tier']);
        $this->assertSame('home', $this->tariff->quote(2, 'HENIN BEAUMONT', null)['tier']);
        $this->assertSame('home', $this->tariff->quote(2, 'henin-beaumont', null)['tier']);
        $this->assertSame('home', $this->tariff->quote(2, '  Hénin–Beaumont ', null)['tier']);
    }

    public function test_other_cities_follow_the_radius_tiers_with_inclusive_bounds(): void
    {
        $grid = [
            [0.1, 490], [3.0, 490], [5.0, 490],
            [5.01, 590], [7.9, 590], [8.0, 590],
            [8.01, 690], [10.0, 690],
        ];
        foreach ($grid as [$km, $cents]) {
            $q = $this->tariff->quote($km, 'Oignies', '62590');
            $this->assertTrue($q['allowed'], "{$km} km doit être livrable");
            $this->assertSame($cents, $q['fee_cents'], "{$km} km");
        }
    }

    public function test_beyond_ten_km_is_refused_and_uber_eats_is_suggested(): void
    {
        $q = $this->tariff->quote(10.01, 'Lens', '62300');
        $this->assertFalse($q['allowed']);
        $this->assertSame(0, $q['fee_cents']);
        $this->assertTrue($q['suggest_uber_eats']);
        $this->assertSame('outside', $q['tier']);
    }

    public function test_home_city_is_never_refused_by_distance(): void
    {
        $q = $this->tariff->quote(11.0, 'Hénin-Beaumont', '62110');
        $this->assertTrue($q['allowed'], 'Une adresse de la commune maison reste livrable.');
    }

    public function test_invalid_distance_is_refused_not_priced(): void
    {
        foreach ([-1, NAN, INF, null, 'abc'] as $bad) {
            $q = $this->tariff->quote($bad, 'Oignies', '62590');
            $this->assertFalse($q['allowed']);
            $this->assertSame('invalid', $q['tier']);
        }
    }
}
