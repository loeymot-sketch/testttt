<?php

/**
 * [audit-360 W5 2026-06-22 — CURRENCY-DECIMAL config:cache guard]
 *
 * AppLibrary::flatAmountFormat / convertAmountFormat read env('CURRENCY_DECIMAL_POINT')
 * with NO default. In production after `php artisan config:cache` (mandated by the deploy
 * checklist), env() returns null for any var not in the server environment, so
 * number_format($amount, null, '.', '') rounds money to an INTEGER ("12.50" → "13").
 * Fed by ~31 resources (item/order/coupon/tax/balance) → every displayed/exported price
 * loses its decimals in prod. The fix defaults to 2 decimals (mirroring :289).
 *
 * This sentinel forces the env var ABSENT (the config:cache condition) and asserts the
 * decimals survive. Without the `?? 2` default it returns "13"/"9" and fails.
 *
 * @group sentinel
 */

namespace Tests\Feature\Sentinels;

use App\Libraries\AppLibrary;
use Tests\TestCase;

class CurrencyDecimalConfigCacheGuardSentinelTest extends TestCase
{
    private $previous;

    protected function setUp(): void
    {
        parent::setUp();
        // Simulate config:cache prod: the .env var is NOT in the runtime environment.
        $this->previous = getenv('CURRENCY_DECIMAL_POINT');
        putenv('CURRENCY_DECIMAL_POINT');
        unset($_ENV['CURRENCY_DECIMAL_POINT'], $_SERVER['CURRENCY_DECIMAL_POINT']);
    }

    protected function tearDown(): void
    {
        if ($this->previous !== false) {
            putenv('CURRENCY_DECIMAL_POINT=' . $this->previous);
        }
        parent::tearDown();
    }

    public function test_flat_amount_format_preserves_two_decimals_when_env_absent(): void
    {
        $this->assertSame('12.50', AppLibrary::flatAmountFormat(12.5), 'must keep 2 decimals (not "13") when CURRENCY_DECIMAL_POINT env is unset (config:cache prod).');
        $this->assertSame('8.50', AppLibrary::flatAmountFormat(8.5));
        $this->assertSame('1.00', AppLibrary::flatAmountFormat(1));
    }

    public function test_convert_amount_format_preserves_decimals_when_env_absent(): void
    {
        $this->assertSame(12.5, AppLibrary::convertAmountFormat(12.5), 'must not truncate to 13.0 when the env is unset.');
        $this->assertSame(8.5, AppLibrary::convertAmountFormat(8.5));
    }
}
