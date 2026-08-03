import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

describe('CatalogStudioComponent reads item_category_id from route query', () => {
    const sfcPath = resolve(process.cwd(), 'resources/js/components/admin/items/CatalogStudioComponent.vue');

    it('reads item_category_id from $route.query in mounted before refreshData', () => {
        const sfc = readFileSync(sfcPath, 'utf-8');
        expect(sfc).toMatch(/\$route[\s\S]*query[\s\S]*item_category_id/);
        expect(sfc).toContain('selectCategory(numericId)');
        expect(sfc.indexOf('queryCategoryId')).toBeLessThan(sfc.indexOf('refreshData()'));
    });

    it('only applies numeric positive ids (invalid query leaves selection untouched in code path)', () => {
        const sfc = readFileSync(sfcPath, 'utf-8');
        expect(sfc).toContain('parseInt(queryCategoryId, 10)');
        expect(sfc).toContain('if (queryCategoryId)');
        expect(sfc).toMatch(/numericId\s*>\s*0/);
    });
});
