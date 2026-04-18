import { describe, expect, it } from 'vitest';
import { kioskPainCatalogForItem } from '../../resources/js/helpers/kioskPainCatalog';

describe('kioskPainCatalogForItem', () => {
  it('returns [] when item has no bread-like attribute', () => {
    const item = {
      itemAttributes: [{ id: 1, name: 'Sauce', role: 'sauce' }],
      variations: { 1: [{ id: 10, name: 'Classique' }] },
    };
    expect(kioskPainCatalogForItem(item)).toEqual([]);
  });

  it('falls back to french names when role is missing', () => {
    const item = {
      itemAttributes: [{ id: 7, name: 'Pain' }],
      variations: {
        7: [
          { id: 100, name: 'Classique' },
          { id: 101, name: 'Galette' },
        ],
      },
    };
    const rows = kioskPainCatalogForItem(item);
    expect(rows.map((row) => row.name)).toEqual(['Classique', 'Galette']);
  });

  it('still matches renamed english or arabic labels when role is set', () => {
    const item = {
      itemAttributes: [{ id: 7, name: 'Khobz', role: 'bread' }],
      variations: {
        7: [{ id: 100, name: 'Maison' }],
      },
    };
    const rows = kioskPainCatalogForItem(item);
    expect(rows).toHaveLength(1);
    expect(rows[0]).toMatchObject({ id: 100, attrId: 7 });
  });
});
