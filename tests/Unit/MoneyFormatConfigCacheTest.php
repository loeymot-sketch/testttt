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
            // [ONB-07 2026-08-28] Attendu passé de `1,427.75` à `1 427,75`.
            //
            // Le but de CE test est que les décimales survivent à `config:cache` —
            // le littéral anglo-saxon y était incident, pas voulu. Le formateur
            // rendait des montants au format `1,234.56` sur un PDF français, quand
            // l'écran affiche `1 234,56 €` : « 1,234.56 » se lit « 1,23 » pour un
            // œil français, soit un facteur mille sur un document comptable.
            //
            // L'intention d'origine est conservée et même renforcée : on vérifie
            // toujours les deux décimales, et désormais aussi la convention.
            $this->assertSame(
                "1\u{202F}427,75",
                AppLibrary::reportCurrencyAmountFormat(1427.75),
                'reportCurrencyAmountFormat (totaux de rapport) ne doit pas arrondir'
            );
        } finally {
            if ($orig !== false) {
                putenv("CURRENCY_DECIMAL_POINT={$orig}");
                $_ENV['CURRENCY_DECIMAL_POINT'] = $orig;
                $_SERVER['CURRENCY_DECIMAL_POINT'] = $orig;
            }
        }
    }

    /**
     * [FR-DATE-SAFE 2026-06-26] Même classe : sous config:cache, env(DATE_FORMAT)/
     * env(TIME_FORMAT) renvoient null → Carbon::format(null) casse la date/heure de
     * CHAQUE écran (rapports, historique, KDS, tickets). On verrouille les défauts
     * FR-safe (« d-m-Y » + « H:i » 24h, ADR-007) quand l'env est vide.
     */
    public function test_date_formatters_fall_back_to_fr_format_when_env_is_null(): void
    {
        $origDate = getenv('DATE_FORMAT');
        $origTime = getenv('TIME_FORMAT');
        putenv('DATE_FORMAT');
        putenv('TIME_FORMAT');
        unset($_ENV['DATE_FORMAT'], $_SERVER['DATE_FORMAT'], $_ENV['TIME_FORMAT'], $_SERVER['TIME_FORMAT']);

        try {
            $this->assertNull(env('DATE_FORMAT'), 'pré-condition : simule config:cache');
            $this->assertNull(env('TIME_FORMAT'), 'pré-condition : simule config:cache');

            $stamp = '2026-06-26 14:21:00';
            $this->assertSame('26-06-2026', AppLibrary::date($stamp), 'date défaut FR d-m-Y');
            $this->assertSame('14:21', AppLibrary::time($stamp), 'time défaut FR 24h H:i (pas de « PM » anglais)');
            $this->assertSame('14:21, 26-06-2026', AppLibrary::datetime($stamp), 'datetime défaut FR 24h');
            // jumeaux increaseDate (delivery_date KDS) + deliveryTime (créneau) — même classe
            $this->assertSame('27-06-2026', AppLibrary::increaseDate($stamp, 1), 'increaseDate défaut FR d-m-Y (delivery_date)');
            $this->assertSame('14:21 - 14:51', AppLibrary::deliveryTime('14:21 - 14:51'), 'deliveryTime défaut FR 24h (créneau HH:MM - HH:MM)');
        } finally {
            if ($origDate !== false) { putenv("DATE_FORMAT={$origDate}"); $_ENV['DATE_FORMAT'] = $origDate; $_SERVER['DATE_FORMAT'] = $origDate; }
            if ($origTime !== false) { putenv("TIME_FORMAT={$origTime}"); $_ENV['TIME_FORMAT'] = $origTime; $_SERVER['TIME_FORMAT'] = $origTime; }
        }
    }
}
