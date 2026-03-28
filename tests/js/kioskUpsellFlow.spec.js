import { describe, it, expect } from 'vitest';
import { shouldSkipKioskUpsellScreen } from '../../resources/js/helpers/kioskUpsellFlow';

describe('shouldSkipKioskUpsellScreen', () => {
  const cats = [
    { id: 1, kiosk_upsell_skip_after_cart: true },
    { id: 2, kiosk_upsell_skip_after_cart: false },
  ];

  it('returns false for empty cart', () => {
    expect(shouldSkipKioskUpsellScreen([], cats)).toBe(false);
  });

  it('returns true when every line is in a skip category', () => {
    expect(shouldSkipKioskUpsellScreen(
      [{ item_category_id: 1 }, { item_category_id: 1 }],
      cats,
    )).toBe(true);
  });

  it('returns false when any line is not in a skip category', () => {
    expect(shouldSkipKioskUpsellScreen(
      [{ item_category_id: 1 }, { item_category_id: 2 }],
      cats,
    )).toBe(false);
  });

  it('returns false when category id is unknown', () => {
    expect(shouldSkipKioskUpsellScreen([{ item_category_id: 99 }], cats)).toBe(false);
  });
});
