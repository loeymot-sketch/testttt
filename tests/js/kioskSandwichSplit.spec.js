import { describe, it, expect } from 'vitest';
import {
  expandKioskSidebarCategories,
  filterSandwichItemsForKioskColumn,
} from '../../resources/js/helpers/kioskSandwichSplit';

describe('kioskSandwichSplit', () => {
  const cats = [
    { id: 1, name: 'Burgers', slug: 'nos-burgers' },
    { id: 2, name: 'Nos Sandwichs', slug: 'nos-sandwichs' },
    { id: 3, name: 'Boissons', slug: 'nos-boissons' },
  ];
  const coldSlugs = ['sandwich-froid', 'panini'];

  it('expandKioskSidebarCategories appends virtual cold row and tags signature row', () => {
    const out = expandKioskSidebarCategories(cats, {
      parentSlug: 'nos-sandwichs',
      coldSlugs,
      coldLabel: 'Sandwich froid',
    });
    expect(out).toHaveLength(4);
    const sig = out.find((r) => r.kioskRowKey === '2-sig');
    const cold = out.find((r) => r.kioskRowKey === '2-sf');
    expect(sig?.kioskSandwichSub).toBeNull();
    expect(cold?.name).toBe('Sandwich froid');
    expect(cold?.kioskSandwichSub).toBe('cold');
    expect(cold?.isVirtualSandwichFroidRow).toBe(true);
    expect(out[out.length - 1]).toBe(cold);
  });

  it('expandKioskSidebarCategories is no-op when coldSlugs empty', () => {
    const out = expandKioskSidebarCategories(cats, { parentSlug: 'nos-sandwichs', coldSlugs: [] });
    expect(out).toHaveLength(3);
    expect(out.every((r) => !r.isVirtualSandwichFroidRow)).toBe(true);
  });

  it('filterSandwichItemsForKioskColumn splits by slug', () => {
    const items = [
      { id: 1, slug: 'le-terminator' },
      { id: 2, slug: 'sandwich-froid' },
      { id: 3, slug: 'panini' },
    ];
    const sig = filterSandwichItemsForKioskColumn(items, null, coldSlugs);
    expect(sig.map((i) => i.id)).toEqual([1]);
    const cold = filterSandwichItemsForKioskColumn(items, 'cold', coldSlugs);
    expect(cold.map((i) => i.id)).toEqual([2, 3]);
  });
});
