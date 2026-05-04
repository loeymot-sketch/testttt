import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

describe('ItemCategoryCreateComponent — advanced section', () => {
    const sfc = readFileSync(
        resolve(
            process.cwd(),
            'resources/js/components/admin/settings/ItemCategory/ItemCategoryCreateComponent.vue',
        ),
        'utf-8',
    );

    it('declares showAdvanced data flag default false', () => {
        expect(sfc).toMatch(/showAdvanced:\s*false/);
    });

    it('renders an advanced section toggle button', () => {
        expect(sfc).toMatch(/data-testid="item-category-form-advanced-toggle"/);
    });

    it('advanced body is conditionally shown via showAdvanced', () => {
        expect(sfc).toMatch(/v-show="showAdvanced"[\s\S]*data-testid="item-category-form-advanced-body"/);
    });

    it('keeps essential fields outside advanced section', () => {
        expect(sfc).toMatch(/label\.name/);
        expect(sfc).toMatch(/label\.status/);
        expect(sfc.indexOf('data-testid="admin-category-form-name"')).toBeLessThan(
            sfc.indexOf('data-testid="item-category-form-advanced-toggle"'),
        );
    });

    it('moved advanced fields under advanced-body', () => {
        const advancedBodyMark = 'data-testid="item-category-form-advanced-body"';
        const bodyStart = sfc.indexOf(advancedBodyMark);
        const footerIdx = sfc.indexOf('modal-btns');
        expect(bodyStart).toBeGreaterThan(-1);
        expect(footerIdx).toBeGreaterThan(bodyStart);
        const advancedThroughFooter = sfc.slice(bodyStart, footerIdx);
        expect(advancedThroughFooter).toMatch(/wizard_template/);
        expect(advancedThroughFooter).toMatch(/has_menu/);
        expect(advancedThroughFooter).toMatch(/kiosk_upsell/);
    });
});
