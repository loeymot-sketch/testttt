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

  // [abuse-heal 2026-06-18] Test-realism: the backend persists site_currency_position as the
  // NUMERIC CurrencyPosition enum (LEFT=5, RIGHT=10), not the strings 'left'/'right'. The runtime
  // already handles both (String(pos) === '5'/'left'), but no case proved the REAL numeric contract.
  // These lock that the formatter renders FR suffix for RIGHT=10 and prefix for LEFT=5.
  it('treats numeric RIGHT (enum 10) as the FR currency suffix', () => {
    expect(formatKioskPrice(12.5, {
      currencySymbol: '€',
      position: 10,
      digits: 2,
    })).toBe('12,50 €');
  });

  it('treats numeric LEFT (enum 5) as the currency prefix', () => {
    expect(formatKioskPrice(12.5, {
      currencySymbol: '€',
      position: 5,
      digits: 2,
    })).toBe('€12,50');
  });

  it('falls back safely when locale/currency are invalid', () => {
    const formatted = formatKioskPrice(7, { locale: 'bad-locale', currency: 'BAD' });
    expect(formatted).toContain('BAD');
    expect(formatted).toMatch(/7[.,]00/);
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
