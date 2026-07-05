import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';

import KdsOrderLine from '../../resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue';
import { renderItemSymbolic } from '../../resources/js/helpers/kdsSymbolic.js';

// [KITCHEN-SYMBOLS 2026-06-28] DOM-level proof that the KDS card actually paints
// the symbolic shorthand the line cook reads (the helper is unit-tested; this
// pins the Vue rendering of the new line types).

const stubs = { mocks: { $t: (k) => k } };

function renderLines(item) {
    return renderItemSymbolic(item).lines.map((line) =>
        mount(KdsOrderLine, { props: { line }, global: stubs }).text(),
    );
}

describe('KdsOrderLine — symbolic line types paint to the DOM', () => {
    it('renders the symbolic-main line with qty and the pipe shorthand', () => {
        const item = {
            item_name: 'Tacos M',
            quantity: 2,
            composition_snapshot: {
                lines: [
                    { attribute_name: 'Viande 1', variation_name: 'Viande Hachée' },
                    { attribute_name: 'Sauce', variation_name: 'Samouraï' },
                ],
                extras: [{ extra_name: 'Cheddar' }],
                addons: [{ addon_name: 'Frites Moyennes', role: 'menu_frites' }],
            },
        };
        const texts = renderLines(item);
        expect(texts[0]).toContain('2');
        expect(texts[0]).toContain('G | TAC | M | K | SAM');
        // Owner order: MENU (ligne 2) PUIS suppléments.
        expect(texts[1]).toContain('MENU');
        expect(texts[2]).toContain('+ Cheddar');
    });
});
