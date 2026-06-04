<?php

namespace Tests\Feature\Localization;

use App\Libraries\AppLibrary;
use Tests\TestCase;

/**
 * @FK-ID GOAL-G2-HEAL-02
 * @source Phase G.5 finding G5-F-003 P1 (currency divergence on receipts)
 * @fix-mission GOAL-G2-HEAL-02 (AppLibrary FR-canonical currency format)
 *
 * Locks the AppLibrary::currencyAmountFormat output to the FR-canonical
 * "12,50 €" layout (virgule decimal + U+00A0 nbsp between number and the
 * € symbol). This matches:
 *   - resources/js/helpers/formatPrice.js (admin Intl fr-FR EUR)
 *   - PaymentComponent.vue (D3 LOCK_PAY)
 *   - Cash Overview / receipts / emails / reports / API responses
 *
 * Bit-identical with both `new \NumberFormatter('fr_FR', NumberFormatter::CURRENCY)`
 * AND `new Intl.NumberFormat('fr-FR', {style:'currency',currency:'EUR'})`.
 *
 * Any regression (US "." decimal, glued symbol, missing nbsp) fails the
 * NF525 receipt fiscal compliance check and breaks the
 * frontend ↔ backend currency parity contract.
 */
class CurrencyFrFormatSentinelTest extends TestCase
{
    private const NBSP = "\xC2\xA0";          // U+00A0 — nbsp between number and €
    private const NNBSP = "\xE2\x80\xAF";    // U+202F — narrow no-break space used as thousands separator by Intl/ICU
    private const EURO = "\xE2\x82\xAC";      // U+20AC — €

    public function test_intl_extension_available_on_production(): void
    {
        // The AppLibrary helper preferentially uses ext-intl. Document the
        // requirement explicitly so that prod boot fails loud if missing.
        $this->assertTrue(
            class_exists('NumberFormatter'),
            'ext-intl (NumberFormatter) is required for FR-canonical currency rendering.'
        );
    }

    public function test_currency_amount_format_canonical_examples(): void
    {
        $nbsp = self::NBSP;
        $euro = self::EURO;

        $this->assertSame("12,50{$nbsp}{$euro}", AppLibrary::currencyAmountFormat(12.50));
        $this->assertSame("8,00{$nbsp}{$euro}", AppLibrary::currencyAmountFormat(8));
        $this->assertSame("0,00{$nbsp}{$euro}", AppLibrary::currencyAmountFormat(0));
        $this->assertSame("100,00{$nbsp}{$euro}", AppLibrary::currencyAmountFormat(100.0));
    }

    public function test_currency_amount_format_string_input(): void
    {
        // OrderService + Resources frequently pass numeric strings.
        $this->assertSame("19,00" . self::NBSP . self::EURO, AppLibrary::currencyAmountFormat('19.00'));
        $this->assertSame("19,00" . self::NBSP . self::EURO, AppLibrary::currencyAmountFormat('19'));
    }

    public function test_currency_amount_format_thousands_uses_nnbsp(): void
    {
        // Intl/ICU emits U+202F (narrow no-break space) as the FR thousands
        // separator. Locking it so a runtime/locale upgrade can't silently
        // change layout on printed receipts.
        $this->assertSame(
            "1" . self::NNBSP . "000,50" . self::NBSP . self::EURO,
            AppLibrary::currencyAmountFormat(1000.50)
        );
    }

    public function test_currency_amount_format_negative_amount(): void
    {
        // Refund / cash-back lines may carry negative deltas.
        $this->assertSame(
            "-5,25" . self::NBSP . self::EURO,
            AppLibrary::currencyAmountFormat(-5.25)
        );
    }

    public function test_no_us_decimal_separator_in_output(): void
    {
        // Anti-regression: the OLD AppLibrary used "." + concat (no nbsp)
        // producing "12.50€". The new output MUST never contain a "." or a
        // bare-glued "€" with no nbsp.
        $out = AppLibrary::currencyAmountFormat(12.50);
        $this->assertStringNotContainsString('.', $out);
        $this->assertStringNotContainsString('12.50', $out);
        $this->assertStringNotContainsString('50€', $out);
        $this->assertStringContainsString(',', $out);
        $this->assertStringContainsString(self::NBSP . self::EURO, $out);
    }
}
