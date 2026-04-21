import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

import ReceiptDuplicataMarker from '../../resources/js/components/admin/pos/ReceiptDuplicataMarker.vue';

const mountWith = (orderProps = {}) => mount(ReceiptDuplicataMarker, {
    props: { order: orderProps },
    global: {
        mocks: {
            $t: (key, params) => key + (params ? ':' + JSON.stringify(params) : ''),
        },
    },
});

describe('ReceiptDuplicataMarker (W8.C-P3 NF525)', () => {
    it('does not render when receipt_print_count is 0 (original print)', () => {
        const wrapper = mountWith({ receipt_print_count: 0 });

        expect(wrapper.find('.receipt-duplicata-marker').exists()).toBe(false);
    });

    it('does not render when receipt_print_count is 1 (still original, premier print)', () => {
        const wrapper = mountWith({ receipt_print_count: 1 });

        expect(wrapper.find('.receipt-duplicata-marker').exists()).toBe(false);
    });

    it('renders DUPLICATA when receipt_print_count is 2 (premiere reimpression)', () => {
        const wrapper = mountWith({ receipt_print_count: 2 });

        expect(wrapper.find('.receipt-duplicata-marker').exists()).toBe(true);
        expect(wrapper.text()).toContain('label.duplicata');
    });

    it('renders DUPLICATA #N when receipt_print_count is 4 (quatrieme print)', () => {
        const wrapper = mountWith({ receipt_print_count: 4 });

        expect(wrapper.find('.receipt-duplicata-marker').exists()).toBe(true);
        expect(wrapper.text()).toContain('"n":3');
    });

    it('handles missing receipt_print_count gracefully (legacy orders pre-W8.C-P3)', () => {
        const wrapper = mountWith({});

        expect(wrapper.find('.receipt-duplicata-marker').exists()).toBe(false);
    });

    it('handles non-numeric receipt_print_count', () => {
        const wrapper = mountWith({ receipt_print_count: '3' });

        expect(wrapper.find('.receipt-duplicata-marker').exists()).toBe(true);
    });

    it('exposes role=status and aria-live=polite for a11y', () => {
        const wrapper = mountWith({ receipt_print_count: 2 });
        const marker = wrapper.find('.receipt-duplicata-marker');

        expect(marker.attributes('role')).toBe('status');
        expect(marker.attributes('aria-live')).toBe('polite');
    });
});
