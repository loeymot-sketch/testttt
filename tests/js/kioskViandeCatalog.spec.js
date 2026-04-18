import { describe, it, expect } from 'vitest';
import {
  kioskViandeCatalogForItem,
  kioskSumPaidViandesSurcharge,
} from '../../resources/js/helpers/kioskViandeCatalog';

function buildItem({ variations = [], extras = [], attrId = 7 } = {}) {
  return {
    itemAttributes: [{ id: attrId, name: 'Viande', role: 'meat' }],
    variations: { [String(attrId)]: variations },
    extras,
  };
}

describe('kioskViandeCatalogForItem', () => {
  it('returns empty array for nullish item', () => {
    expect(kioskViandeCatalogForItem(null)).toEqual([]);
    expect(kioskViandeCatalogForItem(undefined)).toEqual([]);
  });

  it('lists variations as free (price=0, source=variation)', () => {
    const item = buildItem({
      variations: [
        { id: 1, name: 'Poulet' },
        { id: 2, name: 'Boeuf' },
      ],
    });
    const rows = kioskViandeCatalogForItem(item);
    expect(rows).toHaveLength(2);
    expect(rows[0]).toMatchObject({ id: 1, name: 'Poulet', price: 0, source: 'variation', attrId: 7 });
    expect(rows[1]).toMatchObject({ id: 2, name: 'Boeuf', price: 0, source: 'variation' });
  });

  it('skips deleted variations (status=10)', () => {
    const item = buildItem({
      variations: [
        { id: 1, name: 'Poulet', status: 1 },
        { id: 2, name: 'Boeuf', status: 10 },
      ],
    });
    const rows = kioskViandeCatalogForItem(item);
    expect(rows.map((r) => r.name)).toEqual(['Poulet']);
  });

  it('appends paid viande extras with price > 0 and source=extra', () => {
    const item = buildItem({
      variations: [{ id: 1, name: 'Poulet' }],
      extras: [
        { id: 99, name: 'Double Steak', group_label: 'viande', convert_price: 2.5 },
        { id: 100, name: 'Bacon', convert_price: 1 },
      ],
    });
    const rows = kioskViandeCatalogForItem(item);
    expect(rows).toHaveLength(2);
    expect(rows[1]).toMatchObject({
      id: 99,
      name: 'Double Steak',
      price: 2.5,
      source: 'extra',
      attrId: null,
    });
  });

  it('does not include free extras (price=0) even if labeled viande', () => {
    const item = buildItem({
      extras: [{ id: 99, name: 'Galette', group_label: 'viande', convert_price: 0 }],
    });
    const rows = kioskViandeCatalogForItem(item);
    expect(rows).toEqual([]);
  });

  it('returns empty when item has no viande attribute and no viande extras', () => {
    const item = { itemAttributes: [{ id: 1, name: 'Sauce' }], variations: {}, extras: [] };
    expect(kioskViandeCatalogForItem(item)).toEqual([]);
  });

  it('still matches renamed english or arabic labels when role is set', () => {
    const item = {
      itemAttributes: [{ id: 7, name: 'Lahma', role: 'meat' }],
      variations: { 7: [{ id: 1, name: 'Poulet' }] },
      extras: [],
    };
    const rows = kioskViandeCatalogForItem(item);
    expect(rows).toHaveLength(1);
    expect(rows[0]).toMatchObject({ id: 1, source: 'variation', attrId: 7 });
  });

  it('still surfaces paid viande extras even if no viande attribute exists', () => {
    const item = {
      itemAttributes: [],
      variations: {},
      extras: [{ id: 5, name: 'Suppl. viande', convert_price: 1.5 }],
    };
    const rows = kioskViandeCatalogForItem(item);
    expect(rows).toHaveLength(1);
    expect(rows[0]).toMatchObject({ id: 5, source: 'extra', price: 1.5 });
  });

  it('deduplicates same variation id silently', () => {
    const item = buildItem({
      variations: [
        { id: 1, name: 'Poulet' },
        { id: 1, name: 'Poulet (doublon)' },
      ],
    });
    const rows = kioskViandeCatalogForItem(item);
    expect(rows).toHaveLength(1);
  });
});

describe('kioskSumPaidViandesSurcharge', () => {
  it('returns 0 for nullish or empty input', () => {
    expect(kioskSumPaidViandesSurcharge(null)).toBe(0);
    expect(kioskSumPaidViandesSurcharge([])).toBe(0);
  });

  it('sums price * count only for source="extra"', () => {
    const meta = [
      { source: 'variation', price: 0, count: 2 },
      { source: 'extra', price: 2.5, count: 1 },
      { source: 'extra', price: 1.5, count: 2 },
    ];
    expect(kioskSumPaidViandesSurcharge(meta)).toBeCloseTo(2.5 + 3, 5);
  });

  it('ignores rows with missing price/count', () => {
    const meta = [
      { source: 'extra', price: 2 },
      { source: 'extra', count: 3 },
      { source: 'extra' },
    ];
    expect(kioskSumPaidViandesSurcharge(meta)).toBe(0);
  });

  it('ignores null entries', () => {
    expect(kioskSumPaidViandesSurcharge([null, { source: 'extra', price: 1, count: 2 }])).toBe(2);
  });
});
