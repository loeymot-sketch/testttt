import { describe, it, expect } from 'vitest';
import {
  sortCategoriesForKioskDisplay,
  kioskCategoryDisplayTier,
} from '../../resources/js/helpers/kioskCategoryOrder';

describe('kioskCategoryOrder', () => {
  it('puts dessert and drinks after mains (sandwichs / burgers)', () => {
    const input = [
      { id: 1, name: 'Boissons', sort: 1 },
      { id: 2, name: 'Sandwichs', sort: 1 },
      { id: 3, name: 'Desserts', sort: 1 },
      { id: 4, name: 'Burgers', sort: 1 },
    ];
    const out = sortCategoriesForKioskDisplay(input).map((c) => c.name);
    const lastMain = Math.max(out.indexOf('Sandwichs'), out.indexOf('Burgers'));
    expect(lastMain).toBeLessThan(out.indexOf('Boissons'));
    expect(lastMain).toBeLessThan(out.indexOf('Desserts'));
  });

  it('uses sort within the same tier', () => {
    const input = [
      { id: 1, name: 'Burgers', sort: 20 },
      { id: 2, name: 'Tacos', sort: 10 },
    ];
    const out = sortCategoriesForKioskDisplay(input).map((c) => c.name);
    expect(out).toEqual(['Tacos', 'Burgers']);
  });

  it('kioskCategoryDisplayTier classifies known families', () => {
    expect(kioskCategoryDisplayTier({ name: 'Menu Sandwichs' })).toBe(0);
    expect(kioskCategoryDisplayTier({ name: 'Nos boissons' })).toBe(2);
    expect(kioskCategoryDisplayTier({ name: 'Glaces' })).toBe(2);
  });

  it('treats « Sandwich froid » label like other sandwich families (tier 0) — ligne froid = virtuelle borne', () => {
    expect(kioskCategoryDisplayTier({ name: 'Sandwich froid' })).toBe(0);
    expect(kioskCategoryDisplayTier({ slug: 'sandwich-froid' })).toBe(0);
  });
});
