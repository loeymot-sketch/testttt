/**
 * [POS-CATEGORY-FIRST 2026-06-23 /goal] Pure unit-tests for the POS
 * "category-first" browse-view resolver.
 *
 * Owner direction (/goal): the cashier's first POS screen must show the GRID
 * OF CATEGORIES — not the full product list (cashier "gets lost" when every
 * product is dumped at once). Picking a category drills into that category's
 * products; a back control returns to the category grid, and adding a line
 * auto-returns to the grid ("après chaque prise de commande").
 *
 * Mirrors the pattern of tests/js/posDineInFlag.spec.js &
 * tests/js/posLoyaltyMainPageCta.spec.js — extract the view decision as pure
 * functions and exhaustively cover the (categoryId × searchName) matrix so a
 * future regression that re-dumps all products on the landing screen gets
 * caught before it ships.
 *
 * The product grid / category-filter getters in PosComponent.vue are NOT
 * changed by this feature — only WHICH region renders (category grid vs
 * product grid) is gated on this resolver.
 */
import { describe, it, expect } from 'vitest';
import {
    resolvePosBrowseMode,
    browseCategoryTiles,
    activeBrowseCategory,
} from '../../resources/js/helpers/posBrowseView.js';

describe('resolvePosBrowseMode — landing shows categories, drill-in shows products', () => {
    it('empty category + empty search ⇒ categories (the first screen is the category grid)', () => {
        expect(resolvePosBrowseMode({ categoryId: '', searchName: '' })).toBe('categories');
    });

    it('null/undefined category + no search ⇒ categories (defensive landing)', () => {
        expect(resolvePosBrowseMode({ categoryId: null, searchName: '' })).toBe('categories');
        expect(resolvePosBrowseMode({ categoryId: undefined, searchName: undefined })).toBe('categories');
        expect(resolvePosBrowseMode({})).toBe('categories');
    });

    it('category sentinel id 0 (the "Toutes" pill) ⇒ still categories, never products', () => {
        expect(resolvePosBrowseMode({ categoryId: 0, searchName: '' })).toBe('categories');
        expect(resolvePosBrowseMode({ categoryId: '0', searchName: '' })).toBe('categories');
    });

    it('a real category selected (id > 0) ⇒ products (drilled into the category)', () => {
        expect(resolvePosBrowseMode({ categoryId: 12, searchName: '' })).toBe('products');
        expect(resolvePosBrowseMode({ categoryId: '7', searchName: '' })).toBe('products');
    });

    it('an active search ⇒ products even with no category (search spans the catalogue)', () => {
        expect(resolvePosBrowseMode({ categoryId: '', searchName: 'tacos' })).toBe('products');
        expect(resolvePosBrowseMode({ categoryId: 0, searchName: 'cayenne' })).toBe('products');
    });

    it('whitespace-only search is treated as empty ⇒ categories', () => {
        expect(resolvePosBrowseMode({ categoryId: '', searchName: '   ' })).toBe('categories');
    });

    it('category AND search both set ⇒ products', () => {
        expect(resolvePosBrowseMode({ categoryId: 5, searchName: 'cola' })).toBe('products');
    });
});

describe('browseCategoryTiles — the grid renders every REAL category, never the id=0 sentinel', () => {
    const cats = [
        { id: 0, name: 'Toutes les catégories', featured: true },
        { id: 3, name: 'Sandwichs', featured: true },
        { id: 8, name: 'Tacos', featured: false },
        { id: 11, name: 'Boissons', featured: true },
    ];

    it('drops the id=0 "Toutes" sentinel', () => {
        const tiles = browseCategoryTiles(cats);
        expect(tiles.map((c) => c.id)).toEqual([3, 8, 11]);
    });

    it('keeps non-featured categories too (the grid is the full hub, not the pill allowlist)', () => {
        const tiles = browseCategoryTiles(cats);
        expect(tiles.some((c) => c.id === 8)).toBe(true); // Tacos is featured:false yet still shown
    });

    it('filters null/garbage entries and string-id sentinels defensively', () => {
        const tiles = browseCategoryTiles([
            null,
            { id: '0', name: 'sentinel-as-string' },
            { id: 4, name: 'Bols' },
            undefined,
            { name: 'no-id' },
        ]);
        expect(tiles.map((c) => c.id)).toEqual([4]);
    });

    it('returns [] for non-array input', () => {
        expect(browseCategoryTiles(null)).toEqual([]);
        expect(browseCategoryTiles(undefined)).toEqual([]);
        expect(browseCategoryTiles('nope')).toEqual([]);
    });
});

describe('activeBrowseCategory — resolves the drilled-in category (for the back-bar header)', () => {
    const cats = [
        { id: 0, name: 'Toutes les catégories' },
        { id: 3, name: 'Sandwichs' },
        { id: 8, name: 'Tacos' },
    ];

    it('returns the matching category object for a numeric or string id', () => {
        expect(activeBrowseCategory(cats, 8)).toEqual({ id: 8, name: 'Tacos' });
        expect(activeBrowseCategory(cats, '3')).toEqual({ id: 3, name: 'Sandwichs' });
    });

    it('returns null on the landing sentinel / empty / no match', () => {
        expect(activeBrowseCategory(cats, '')).toBeNull();
        expect(activeBrowseCategory(cats, 0)).toBeNull();
        expect(activeBrowseCategory(cats, 999)).toBeNull();
        expect(activeBrowseCategory(null, 8)).toBeNull();
    });
});
