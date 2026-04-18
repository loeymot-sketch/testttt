import { describe, it, expect } from 'vitest';
import {
  kioskIsSauceExtra,
  kioskIsViandePaidExtra,
  partitionKioskExtras,
} from '../../resources/js/helpers/kioskExtrasPartition';

describe('kioskIsSauceExtra', () => {
  it('detects sauce via group_label (priority)', () => {
    expect(kioskIsSauceExtra({ name: 'Algerienne', group_label: 'sauce' })).toBe(true);
    expect(kioskIsSauceExtra({ name: 'Mayo', group_label: 'SAUCE' })).toBe(true);
  });

  it('detects sauce via name fallback when no group_label', () => {
    expect(kioskIsSauceExtra({ name: 'Sauce Biggy' })).toBe(true);
  });

  it('returns false when group_label is non-sauce, even if name says sauce', () => {
    expect(kioskIsSauceExtra({ name: 'Sauce Pizza', group_label: 'topping' })).toBe(false);
  });

  it('returns false for non-sauce extras', () => {
    expect(kioskIsSauceExtra({ name: 'Cheddar' })).toBe(false);
    expect(kioskIsSauceExtra(null)).toBe(false);
  });
});

describe('kioskIsViandePaidExtra', () => {
  it('detects via group_label viande/meat/protein when price > 0', () => {
    expect(kioskIsViandePaidExtra({ name: 'Steak', group_label: 'Viande', convert_price: 2 })).toBe(true);
    expect(kioskIsViandePaidExtra({ name: 'Chicken', group_label: 'meat', convert_price: 1.5 })).toBe(true);
    expect(kioskIsViandePaidExtra({ name: 'Whey', group_label: 'protein', price: 1 })).toBe(true);
  });

  it('rejects when price is 0 even with viande group', () => {
    expect(kioskIsViandePaidExtra({ name: 'Steak', group_label: 'viande', convert_price: 0 })).toBe(false);
  });

  it('detects via name viande when price > 0', () => {
    expect(kioskIsViandePaidExtra({ name: 'Suppl. viande', convert_price: 2 })).toBe(true);
  });

  it('rejects unrelated paid extras (bacon, cheddar)', () => {
    expect(kioskIsViandePaidExtra({ name: 'Bacon', convert_price: 1 })).toBe(false);
    expect(kioskIsViandePaidExtra({ name: 'Cheddar', convert_price: 1 })).toBe(false);
  });
});

describe('partitionKioskExtras', () => {
  it('returns empty buckets for null/undefined items', () => {
    expect(partitionKioskExtras(null)).toEqual({
      garnitures: [],
      supplements: [],
      fritesUpgrades: [],
      viandesPaid: [],
    });
    expect(partitionKioskExtras({})).toEqual({
      garnitures: [],
      supplements: [],
      fritesUpgrades: [],
      viandesPaid: [],
    });
  });

  it('classifies free non-sauce extras as garnitures', () => {
    const item = {
      extras: [
        { id: 1, name: 'Salade', convert_price: 0 },
        { id: 2, name: 'Tomate', price: 0 },
      ],
    };
    const out = partitionKioskExtras(item);
    expect(out.garnitures.map((r) => r.name)).toEqual(['Salade', 'Tomate']);
    expect(out.supplements).toEqual([]);
    expect(out.fritesUpgrades).toEqual([]);
    expect(out.viandesPaid).toEqual([]);
  });

  it('never classifies sauces in any bucket', () => {
    const item = {
      extras: [
        { id: 1, name: 'Algerienne', group_label: 'sauce', convert_price: 0 },
        { id: 2, name: 'Sauce Biggy', convert_price: 0.5 },
      ],
    };
    const out = partitionKioskExtras(item);
    expect(out.garnitures).toEqual([]);
    expect(out.supplements).toEqual([]);
    expect(out.fritesUpgrades).toEqual([]);
    expect(out.viandesPaid).toEqual([]);
  });

  it('routes paid frites upgrades to fritesUpgrades when item has_menu', () => {
    const item = {
      has_menu: true,
      extras: [
        { id: 10, name: 'Frites Cheddar', convert_price: 1 },
        { id: 11, name: 'Frites Cheddar Crispy', convert_price: 2 },
        { id: 12, name: 'Bacon', convert_price: 1.5 },
      ],
    };
    const out = partitionKioskExtras(item);
    expect(out.fritesUpgrades.map((r) => r.name)).toEqual([
      'Frites Cheddar',
      'Frites Cheddar Crispy',
    ]);
    expect(out.supplements.map((r) => r.name)).toEqual(['Bacon']);
  });

  it('routes paid viandes to viandesPaid (not supplements)', () => {
    const item = {
      extras: [
        { id: 20, name: 'Double Steak', group_label: 'viande', convert_price: 2.5 },
        { id: 21, name: 'Suppl. viande poulet', convert_price: 1.5 },
        { id: 22, name: 'Bacon', convert_price: 1 },
      ],
    };
    const out = partitionKioskExtras(item);
    expect(out.viandesPaid.map((r) => r.name).sort()).toEqual([
      'Double Steak',
      'Suppl. viande poulet',
    ]);
    expect(out.supplements.map((r) => r.name)).toEqual(['Bacon']);
  });

  it('buckets are mutually exclusive (no double-count)', () => {
    const item = {
      has_menu: true,
      extras: [
        { id: 1, name: 'Salade', convert_price: 0 },
        { id: 2, name: 'Algerienne', group_label: 'sauce', convert_price: 0 },
        { id: 3, name: 'Frites Cheddar', convert_price: 1 },
        { id: 4, name: 'Double Steak', group_label: 'viande', convert_price: 2 },
        { id: 5, name: 'Bacon', convert_price: 1 },
      ],
    };
    const out = partitionKioskExtras(item);
    const allIds = [
      ...out.garnitures,
      ...out.supplements,
      ...out.fritesUpgrades,
      ...out.viandesPaid,
    ].map((r) => r.id);
    const unique = new Set(allIds);
    expect(unique.size).toBe(allIds.length);
    expect(allIds.sort()).toEqual([1, 3, 4, 5]);
  });

  it('skips null entries gracefully', () => {
    const item = { extras: [null, undefined, { id: 1, name: 'Salade', convert_price: 0 }] };
    const out = partitionKioskExtras(item);
    expect(out.garnitures.map((r) => r.id)).toEqual([1]);
  });
});
