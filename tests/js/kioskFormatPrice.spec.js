import { describe, it, expect } from 'vitest';
import { formatKioskPrice, getPriceOptionsFromStore } from '../../resources/js/helpers/kioskFormatPrice';

describe('kioskFormatPrice', () => {
  it('formats with a direct currency symbol on the right', () => {
    expect(formatKioskPrice(12.5, {
      currencySymbol: 'EUR',
      position: 'right',
      digits: 2,
    })).toBe('12,50\u00A0EUR');
  });

  // [FINITION 2026-11-04] Le réglage arrive de l'API en ENUM numérique (CurrencyPosition : 5 = gauche,
  // 10 = droite) : comparé à la chaîne 'right', il tombait toujours à gauche → la borne affichait
  // « €7,00 » alors que le restaurant est réglé « symbole à droite » (« 7,00 € », comme la caisse).
  it.each([[10, 'right'], ['10', 'right'], ['right', 'right'], [5, 'left'], ['5', 'left'], ['left', 'left'], [undefined, 'right'], [42, 'right']])(
    'normalise la position %s → %s', (brut, attendu) => {
      expect(getPriceOptionsFromStore({ site_default_currency_symbol: '€', site_currency_position: brut }).position).toBe(attendu);
    });

  it('affiche « 7,00 € » avec le réglage servi par l’API (position 10)', () => {
    const opts = getPriceOptionsFromStore({ site_default_currency_symbol: '€', site_currency_position: 10, site_digit_after_decimal_point: '2' });
    expect(formatKioskPrice(7, opts)).toBe('7,00\u00A0€');
    expect(formatKioskPrice(7.4, { ...opts, position: 'left' })).toBe('€7,40');
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
