<?php

namespace Tests\Unit\Services\Pricing;

use App\Enums\TaxType;
use App\Services\Pricing\TaxCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for PricingService::TaxCalculator.
 *
 * @see app/Services/Pricing/TaxCalculator.php
 * @see docs/PRICING_SSOT.md
 */
class TaxCalculatorTest extends TestCase
{
    public function test_percentage_tax_rounded(): void
    {
        $calc = new TaxCalculator;
        $this->assertSame(2.5, $calc->lineTaxAmount(100.0, TaxType::PERCENTAGE, 2.5, true));
    }

    public function test_percentage_tax_unrounded(): void
    {
        $calc = new TaxCalculator;
        $this->assertEqualsWithDelta(
            1.155,
            $calc->lineTaxAmount(11.55, TaxType::PERCENTAGE, 10.0, false),
            0.0001
        );
    }

    public function test_percentage_tax_rounds_to_two_decimals(): void
    {
        $calc = new TaxCalculator;
        $this->assertSame(1.16, $calc->lineTaxAmount(11.55, TaxType::PERCENTAGE, 10.0, true));
    }

    public function test_fixed_tax_not_rounded_when_disabled(): void
    {
        $calc = new TaxCalculator;
        $this->assertSame(1.5, $calc->lineTaxAmount(0.0, TaxType::FIXED, 1.5, false));
    }

    public function test_fixed_tax_ignores_subtotal(): void
    {
        $calc = new TaxCalculator;
        // Fixed tax is the rate verbatim, regardless of line subtotal.
        $this->assertSame(2.0, $calc->lineTaxAmount(999.99, TaxType::FIXED, 2.0, true));
    }

    public function test_zero_rate_produces_zero_tax(): void
    {
        $calc = new TaxCalculator;
        $this->assertSame(0.0, $calc->lineTaxAmount(100.0, TaxType::PERCENTAGE, 0.0, true));
        $this->assertSame(0.0, $calc->lineTaxAmount(100.0, TaxType::FIXED, 0.0, true));
    }

    public function test_zero_subtotal_with_percentage_is_zero(): void
    {
        $calc = new TaxCalculator;
        $this->assertSame(0.0, $calc->lineTaxAmount(0.0, TaxType::PERCENTAGE, 20.0, true));
    }

    public function test_high_precision_rounding_boundary(): void
    {
        $calc = new TaxCalculator;
        // 9.995 rounds up to 10.00 (banker's/standard rounding via round()).
        $this->assertSame(10.00, $calc->lineTaxAmount(99.95, TaxType::PERCENTAGE, 10.0, true));
    }

    public function test_negative_rate_produces_negative_tax_percentage(): void
    {
        // Contract: calculator is agnostic to business rules; upstream layer rejects negatives.
        $calc = new TaxCalculator;
        $this->assertSame(-5.0, $calc->lineTaxAmount(100.0, TaxType::PERCENTAGE, -5.0, true));
    }

    public function test_unknown_tax_type_falls_back_to_percentage_branch(): void
    {
        // Current impl: any $taxType !== FIXED uses percentage formula. Contract pinned here
        // so any future refactor that adds a new TaxType MUST update the test too.
        $calc = new TaxCalculator;
        $this->assertSame(10.0, $calc->lineTaxAmount(100.0, 999, 10.0, true));
    }
}
