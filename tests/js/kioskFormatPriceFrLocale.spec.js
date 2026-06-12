import { describe, it, expect } from 'vitest';
import { formatKioskPrice, getPriceOptionsFromStore } from '../../resources/js/helpers/kioskFormatPrice';

/**
 * @FK-ID F-BV-01 (P2, ultra-audit 2026-06-10) | LOT G — FR price format borne
 *
 * settings.site_currency_position is the NUMERIC CurrencyPosition enum
 * (LEFT=5, RIGHT=10 — app/Enums/CurrencyPosition.php). The helper compared it
 * to the STRING 'right' (10 === 'right' → always false) so virtually the
 * whole kiosk displayed anglo-style "€1,50" instead of FR "1,50 €", with two
 * different formats inside the same customer journey (the cash screen uses
 * Intl fr-FR and was the only correct one).
 */
describe('kioskFormatPrice — FR locale (F-BV-01)', () => {
    it('maps numeric RIGHT (10) from settings to symbol-after format', () => {
        const opts = getPriceOptionsFromStore({
            site_default_currency_symbol: '€',
            site_currency_position: 10,
            site_digit_after_decimal_point: '2',
        });
        expect(formatKioskPrice(1.5, opts)).toBe('1,50 €');
    });

    it('maps numeric RIGHT passed as string "10" too', () => {
        const opts = getPriceOptionsFromStore({
            site_default_currency_symbol: '€',
            site_currency_position: '10',
            site_digit_after_decimal_point: '2',
        });
        expect(formatKioskPrice(7, opts)).toBe('7,00 €');
    });

    it('maps numeric LEFT (5) to symbol-before format', () => {
        const opts = getPriceOptionsFromStore({
            site_default_currency_symbol: '$',
            site_currency_position: 5,
            site_digit_after_decimal_point: '2',
        });
        expect(formatKioskPrice(2, opts)).toBe('$2,00');
    });

    it('keeps legacy string positions working', () => {
        expect(formatKioskPrice(1.5, { currencySymbol: '€', position: 'right' })).toBe('1,50 €');
        expect(formatKioskPrice(1.5, { currencySymbol: '€', position: 'left' })).toBe('€1,50');
    });

    it('defaults to FR right-side when position is missing', () => {
        const opts = getPriceOptionsFromStore({ site_default_currency_symbol: '€' });
        expect(formatKioskPrice(3.2, opts)).toBe('3,20 €');
    });
});
