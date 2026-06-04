<?php

namespace Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;

/**
 * [P0-6 CTO audit 2026-05-16] Regression sentinel for Stripe cents cast.
 *
 * BUG: `(int) $order->total * 100` truncates decimals BEFORE multiplying by 100
 *       due to PHP operator precedence (cast binds tighter than *).
 *       → €9.99 → 9 * 100 = 900 cents = €9.00 (revenue loss €0.99/order).
 *       → NF525 receipt total != Stripe charge total.
 *
 * FIX:  `(int) round((float) $order->total * 100)` rounds the cents value
 *       AFTER the multiplication, then casts to int.
 *
 * This sentinel guarantees the call site at Stripe.php:51 stays correct.
 */
class StripeCentsCastTest extends TestCase
{
    public function test_math_formula_produces_correct_cents_for_x99_amounts(): void
    {
        $cases = [
            // [total, expected cents]
            [9.99, 999],     // The classic truncation bug case
            [10.50, 1050],
            [0.01, 1],       // Smallest meaningful amount
            [0.99, 99],
            [100.00, 10000],
            [1.005, 101],    // Round-half-up
        ];

        foreach ($cases as [$total, $expectedCents]) {
            $cents = (int) round((float) $total * 100);
            $this->assertSame(
                $expectedCents,
                $cents,
                "€{$total} must convert to {$expectedCents} cents (got {$cents})"
            );
        }
    }

    public function test_buggy_formula_demonstrably_truncates(): void
    {
        // Document what the BUG did, so a future engineer reverting the fix
        // immediately sees evidence of the revenue-loss class.
        $total = 9.99;
        $buggy = (int) $total * 100;
        $this->assertSame(900, $buggy, 'Bug formula produces 900 cents for €9.99 — €0.99 loss');
        $this->assertNotSame(999, $buggy, 'Bug formula does NOT produce correct 999 cents');
    }

    public function test_stripe_gateway_uses_round_cast_pattern_at_callsite(): void
    {
        $stripePath = __DIR__ . '/../../../app/Http/PaymentGateways/Gateways/Stripe.php';
        $this->assertFileExists($stripePath, 'Stripe.php must exist at the audited path');

        $code = file_get_contents($stripePath);

        // Must contain the correct cast pattern at the 'amount' key.
        $this->assertMatchesRegularExpression(
            '/\'amount\'\s*=>\s*\(int\)\s*round\(\(float\)\s*\$order->total\s*\*\s*100\)/',
            $code,
            'Stripe.php must use `(int) round((float) $order->total * 100)` at amount key (P0-6 regression sentinel)'
        );

        // Must NOT contain the buggy truncation pattern at the 'amount' key.
        $this->assertDoesNotMatchRegularExpression(
            '/\'amount\'\s*=>\s*\(int\)\s*\$order->total\s*\*\s*100[^\)]/',
            $code,
            'Stripe.php must NOT use the truncation pattern `(int) $order->total * 100` (P0-6 bug class)'
        );
    }
}
