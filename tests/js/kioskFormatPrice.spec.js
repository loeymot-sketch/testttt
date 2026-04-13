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
    expect(formatted).toContain('7.00');
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
