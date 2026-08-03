import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

describe('BackendMenuComponent hides legacy items parent nav row', () => {
    const sfcPath = resolve(
        process.cwd(),
        'resources/js/components/layouts/backend/BackendMenuComponent.vue',
    );
    const sfc = readFileSync(sfcPath, 'utf-8');

    it('imports V1_HIDDEN_BACKEND_MENU_URLS from config', () => {
        expect(sfc).toMatch(/V1_HIDDEN_BACKEND_MENU_URLS/);
    });

    it('uses hiddenBackendMenuUrls computed', () => {
        expect(sfc).toMatch(/hiddenBackendMenuUrls/);
    });

    it('menusForSidebar excludes dead-only legacy-hidden parents', () => {
        expect(sfc).toMatch(/menusForSidebar/);
    });

    it('menu.catalog virtual child replaces product_list reference', () => {
        expect(sfc).toMatch(/menu\.catalog|language:\s*['"]catalog['"]/);
    });
});
