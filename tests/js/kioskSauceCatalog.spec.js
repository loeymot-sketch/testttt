import { describe, it, expect } from 'vitest';
import { kioskSauceVariationRowsForItem } from '../../resources/js/helpers/kioskSauceCatalog';

describe('kioskSauceVariationRowsForItem', () => {
  it('returns [] when item has no sauce-like attribute', () => {
    const item = {
      itemAttributes: [{ id: 1, name: 'Pain' }],
      variations: { 1: [{ id: 10, name: 'Classique' }] },
    };
    expect(kioskSauceVariationRowsForItem(item)).toEqual([]);
  });

  it('extracts sauce variations using attribute id key', () => {
    const item = {
      itemAttributes: [{ id: 7, name: 'Sauce' }],
      variations: {
        7: [
          { id: 100, name: 'Algérienne' },
          { id: 101, name: 'Blanche' },
        ],
      },
    };
    const rows = kioskSauceVariationRowsForItem(item);
    expect(rows.map((r) => r.name)).toEqual(['Algérienne', 'Blanche']);
    expect(rows[0].rowKey).toBe('sauce-var-100');
  });

  it('filters out disabled variations (status 10)', () => {
    const item = {
      itemAttributes: [{ id: 3, name: 'Sauce frites' }],
      variations: {
        3: [
          { id: 1, name: 'Ketchup', status: 1 },
          { id: 2, name: 'Legacy', status: 10 },
        ],
      },
    };
    const rows = kioskSauceVariationRowsForItem(item);
    expect(rows.map((r) => r.id)).toEqual([1]);
  });

  it('supports attributes-as-object', () => {
    const item = {
      itemAttributes: { a: { id: 2, name: 'Condiment' } },
      variations: { 2: [{ id: 9, name: 'Harissa' }] },
    };
    expect(kioskSauceVariationRowsForItem(item)).toEqual([]);
  });

  it('still matches renamed english or arabic labels when role is set', () => {
    const item = {
      itemAttributes: [
        { id: 2, name: 'Sos', role: 'sauce' },
        { id: 9, name: 'Dip', role: 'condiment' },
      ],
      variations: { 2: [{ id: 9, name: 'Harissa' }] },
    };
    const rows = kioskSauceVariationRowsForItem(item);
    expect(rows).toHaveLength(1);
    expect(rows[0].emoji).toBeTruthy();
  });
});
