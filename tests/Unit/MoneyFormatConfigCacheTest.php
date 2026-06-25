<?php

namespace Tests\Unit;

use App\Libraries\AppLibrary;
use Tests\TestCase;

/**
 * [MONEY-FIX 2026-06-25] Régression : sous `php artisan config:cache` (mandaté en
 * prod par les boot-guards), env() renvoie null au runtime. Sans garde-fou,
 * number_format($x, null) arrondissait les montants à l'ENTIER → totaux de
 * rapports/Z faux (« combien a rapporté le service » falsifié). On verrouille
 * que les 3 formateurs gardent 2 décimales même quand env(CURRENCY_DECIMAL_POINT)
 * est null. Test Unit pur (pas de RefreshDatabase) → DB-safe.
 */
class MoneyFormatConfigCacheTest extends TestCase
{
    public function test_money_formatters_keep_decimals_when_env_decimal_point_is_null(): void
    {
        $orig = getenv('CURRENCY_DECIMAL_POINT');
        putenv('CURRENCY_DECIMAL_POINT');
        unset($_ENV['CURRENCY_DECIMAL_POINT'], $_SERVER['CURRENCY_DECIMAL_POINT']);

        try {
            $this->assertNull(env('CURRENCY_DECIMAL_POINT'), 'pré-condition : simule config:cache');

            $this->assertSame('8.80', AppLibrary::flatAmountFormat(8.80), 'flatAmountFormat ne doit pas arrondir à l\'entier');
            $this->assertSame(7.9, AppLibrary::convertAmountFormat(7.90), 'convertAmountFormat doit garder les décimales');
            $this->assertSame('1,427.75', AppLibrary::reportCurrencyAmountFormat(1427.75), 'reportCurrencyAmountFormat (totaux de rapport) ne doit pas arrondir');
        } finally {
            if ($orig !== false) {
                putenv("CURRENCY_DECIMAL_POINT={$orig}");
                $_ENV['CURRENCY_DECIMAL_POINT'] = $orig;
                $_SERVER['CURRENCY_DECIMAL_POINT'] = $orig;
            }
        }
    }
}
