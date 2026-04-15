<?php

namespace Tests\Unit\Services\Pricing;

use App\Enums\TaxType;
use App\Services\Pricing\TaxCalculator;
use PHPUnit\Framework\TestCase;

class TaxCalculatorTest extends TestCase
{
    public function test_percentage_tax_rounded(): void
    {
        $calc = new TaxCalculator;
        $this->assertSame(2.5, $calc->lineTaxAmount(100.0, TaxType::PERCENTAGE, 2.5, true));
    }

    public function test_fixed_tax_not_rounded_when_disabled(): void
    {
        $calc = new TaxCalculator;
        $this->assertSame(1.5, $calc->lineTaxAmount(0.0, TaxType::FIXED, 1.5, false));
    }
}
