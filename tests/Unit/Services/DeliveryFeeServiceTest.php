<?php

namespace Tests\Unit\Services;

use App\Services\Delivery\DeliveryFeeService;
use PHPUnit\Framework\TestCase;

class DeliveryFeeServiceTest extends TestCase
{
    public function test_foodking_v1_delivery_fee_rule(): void
    {
        $service = new DeliveryFeeService();

        $this->assertSame(5.0, $service->fromDistanceKm(0));
        $this->assertSame(5.0, $service->fromDistanceKm(5));
        $this->assertSame(10.0, $service->fromDistanceKm(5.01));
        $this->assertSame(10.0, $service->fromDistanceKm(10));
        $this->assertSame(15.0, $service->fromDistanceKm(10.01));
        $this->assertSame(0.0, $service->fromDistanceKm(-1));
        $this->assertSame(0.0, $service->fromDistanceKm('not-a-number'));
    }
}
