import { describe, it, expect } from 'vitest';
import { formatKioskPrice, getPriceOptionsFromStore } from '../../resources/js/helpers/kioskFormatPrice';

describe('kioskFormatPrice', () => {
  it('formats with a direct currency symbol on the right', () => {
    expect(formatKioskPrice(12.5, {
      currencySymbol: 'EUR',
      position: 'right',
      digits: 2,
    })).toBe('12,50 EUR');
  });

  it('falls back safely when locale/currency are invalid', () => {
    const formatted = formatKioskPrice(7, { locale: 'bad-locale', currency: 'BAD' });
    expect(formatted).toContain('BAD');
    expect(formatted).toMatch(/7[.,]00/);
  });

  // [UIUX-W4 K1 2026-06-11] Le backend envoie l'enum CurrencyPosition
  // (LEFT=5, RIGHT=10 — app/Enums/CurrencyPosition.php), pas la chaîne
  // 'left'/'right'. Le comparateur strict `position === 'right'` rendait
  // « €2,00 » au lieu de « 2,00 € » sur toute la borne.
  it('maps numeric enum CurrencyPosition::RIGHT (10) to symbol on the right', () => {
    expect(formatKioskPrice(2, { currencySymbol: '€', position: 10 })).toBe('2,00 €');
  });

  it('maps numeric enum CurrencyPosition::LEFT (5) to symbol on the left', () => {
    expect(formatKioskPrice(2, { currencySymbol: '€', position: 5 })).toBe('€2,00');
  });

  it('tolerates the enum sent as a string ("10"/"5")', () => {
    expect(formatKioskPrice(2, { currencySymbol: '€', position: '10' })).toBe('2,00 €');
    expect(formatKioskPrice(2, { currencySymbol: '€', position: '5' })).toBe('€2,00');
  });

  it('still accepts legacy string positions', () => {
    expect(formatKioskPrice(2, { currencySymbol: '€', position: 'right' })).toBe('2,00 €');
    expect(formatKioskPrice(2, { currencySymbol: '€', position: 'left' })).toBe('€2,00');
  });

  it('defaults unknown positions to right (FR convention)', () => {
    expect(formatKioskPrice(2, { currencySymbol: '€', position: undefined })).toBe('2,00 €');
  });

  it('normalizes the numeric enum coming from globalState lists', () => {
    expect(getPriceOptionsFromStore({
      site_default_currency_symbol: '€',
      site_currency_position: 10,
      site_digit_after_decimal_point: '2',
    })).toEqual({
      currencySymbol: '€',
      position: 'right',
      digits: 2,
    });
  });

  it('extracts price options from global state lists', () => {
    expect(getPriceOptionsFromStore({
      site_default_currency_symbol: '€',
      site_currency_position: 'right',
      site_digit_after_decimal_point: '3',
    })).toEqual({
      currencySymbol: '€',
      position: 'right',
      digits: 3,
    });
  });
});
