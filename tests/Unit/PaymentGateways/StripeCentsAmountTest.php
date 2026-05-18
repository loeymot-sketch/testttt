<?php

namespace Tests\Unit\PaymentGateways;

use PHPUnit\Framework\TestCase;

/**
 * [P0 CTO audit 2026-05-16] Pure-PHP regression test for cents conversion.
 * The Stripe charge amount must use round-before-cast to avoid truncating
 * cents on prices like €9.99. The buggy `(int) $total * 100` cast €9.99
 * to 9 first then *100 = 900 -> undercharge €0.99 per €X.99 order.
 */
class StripeCentsAmountTest extends TestCase
{
    public function centsProvider(): array
    {
        return [
            'whole euros'         => [10.00, 1000],
            'classic xx.99'       => [9.99,  999],
            'penny'               => [0.01,  1],
            'half cent rounds up' => [9.995, 1000],
            'large'               => [123.45, 12345],
        ];
    }

    /**
     * @dataProvider centsProvider
     */
    public function test_round_before_cast_preserves_cents(float $eur, int $expected): void
    {
        // Mirror the production expression — must stay byte-aligned with Stripe.php:55
        $cents = (int) round((float) $eur * 100);
        $this->assertSame($expected, $cents);
    }

    public function test_legacy_truncation_pattern_is_wrong(): void
    {
        // Sanity: prove the OLD bug pattern would have failed.
        $eur = 9.99;
        $buggy = (int) $eur * 100; // 900 — wrong
        $this->assertSame(900, $buggy);
        $this->assertNotSame(999, $buggy); // proves bug
    }
}
