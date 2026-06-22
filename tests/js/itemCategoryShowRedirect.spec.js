import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * importing settingRoutes pulls lazy Vue SFC paths that Vite cannot resolve here;
 * mirror catalogStudioRouting.spec.js — assert on router source instead.
 */
describe('admin.settings.itemCategory.show redirect to Catalog Studio', () => {
    const root = resolve(__dirname, '..', '..');
    const settingRoutesPath = resolve(root, 'resources/js/router/modules/settingRoutes.js');

    it('itemCategory.show route is defined', () => {
        const src = readFileSync(settingRoutesPath, 'utf8');
        expect(src).toContain('name: "admin.settings.itemCategory.show"');
        expect(src).toContain('path: "show/:id"');
    });

    it('itemCategory.show redirects to admin.items.studio with item_category_id', () => {
        const src = readFileSync(settingRoutesPath, 'utf8');
        const marker = 'name: "admin.settings.itemCategory.show"';
        const i = src.indexOf(marker);
        expect(i).toBeGreaterThan(-1);
        const window = src.slice(i, i + 400);
        expect(window).toMatch(/redirect:\s*\([^)]*\)\s*=>/);
        expect(window).toContain('name: "admin.items.studio"');
        expect(window).toContain('query: { item_category_id: to.params.id }');
    });

    it('itemCategory.show route block does not use ItemCategoryShowComponent', () => {
        const src = readFileSync(settingRoutesPath, 'utf8');
        const marker = 'name: "admin.settings.itemCategory.show"';
        const i = src.indexOf(marker);
        expect(i).toBeGreaterThan(-1);
        const window = src.slice(i - 120, i + 400);
        expect(window).not.toContain('ItemCategoryShowComponent');
    });

    it('lazy import of ItemCategoryShowComponent removed from router (zombie route only)', () => {
        const src = readFileSync(settingRoutesPath, 'utf8');
        expect(src).not.toMatch(/ItemCategoryShowComponent\s*=\s*\(\)\s*=>/);
    });
});
