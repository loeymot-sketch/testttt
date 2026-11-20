import { describe, expect, it } from 'vitest';
import { normalizeCartForApi } from '../../resources/js/store/modules/posCart';
import { mainOrderLineTotal } from '../../resources/js/helpers/posCartLineMath';

describe('POS manual supplement cart contract', () => {
    const manualLine = {
        line_type: 'manual_supplement',
        manual_label: 'Olives',
        manual_amount: 1.25,
        item_id: null,
        quantity: 2,
        item_variations: [],
        item_extras: [],
        pos_line_addons: [],
    };

    it('keeps the deliberate null catalogue id and the cashier TTC intent through normalization', () => {
        const [normalized] = normalizeCartForApi([manualLine]);
        expect(normalized.line_type).toBe('manual_supplement');
        expect(normalized.item_id).toBeNull();
        expect(normalized.manual_label).toBe('Olives');
        expect(normalized.manual_amount).toBe(1.25);
    });

    it('uses the entered amount for the local line display only; server remains pricing authority', () => {
        expect(mainOrderLineTotal(manualLine, 2)).toBe(2.5);
    });
});
