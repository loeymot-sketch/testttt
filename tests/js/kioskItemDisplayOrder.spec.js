import { describe, it, expect } from 'vitest';
import {
  sortKioskItemsForDisplay,
  kioskItemSizeRank,
  compareKioskItemsDisplay,
} from '../../resources/js/helpers/kioskItemDisplayOrder';

describe('kioskItemDisplayOrder', () => {
  it('sorts by lowest price first', () => {
    const items = [
      { id: 1, name: 'A', convert_price: 12 },
      { id: 2, name: 'B', convert_price: 5 },
      { id: 3, name: 'C', convert_price: 8 },
    ];
    expect(sortKioskItemsForDisplay(items).map((i) => i.id)).toEqual([2, 3, 1]);
  });

  it('at same price orders taco-style M then L then XL then XXL', () => {
    const items = [
      { id: 1, name: 'Tacos XXL', convert_price: 8 },
      { id: 2, name: 'Tacos M', convert_price: 8 },
      { id: 3, name: 'Tacos XL', convert_price: 8 },
      { id: 4, name: 'Tacos L', convert_price: 8 },
    ];
    expect(sortKioskItemsForDisplay(items).map((i) => i.name)).toEqual([
      'Tacos M',
      'Tacos L',
      'Tacos XL',
      'Tacos XXL',
    ]);
  });

  it('kioskItemSizeRank increases with size', () => {
    expect(kioskItemSizeRank('Tacos M')).toBeLessThan(kioskItemSizeRank('Tacos L'));
    expect(kioskItemSizeRank('Tacos L')).toBeLessThan(kioskItemSizeRank('Tacos XL'));
    expect(kioskItemSizeRank('Tacos XL')).toBeLessThan(kioskItemSizeRank('Tacos XXL'));
  });

  it('uses admin sort when price and size tie', () => {
    const a = { id: 1, name: 'X', convert_price: 5, sort: 20 };
    const b = { id: 2, name: 'Y', convert_price: 5, sort: 10 };
    expect(compareKioskItemsDisplay(a, b)).toBeGreaterThan(0);
    expect(sortKioskItemsForDisplay([a, b]).map((i) => i.id)).toEqual([2, 1]);
  });
});
