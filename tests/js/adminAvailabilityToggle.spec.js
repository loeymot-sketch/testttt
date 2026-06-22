import { describe, it, expect, vi, beforeEach } from 'vitest';
import axios from 'axios';
import { mount, flushPromises } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';
import AvailabilityToggleComponent from '../../resources/js/components/admin/items/AvailabilityToggleComponent.vue';
import { itemAvailability } from '../../resources/js/store/modules/itemAvailability';

vi.mock('axios', () => ({
    default: {
        post: vi.fn(),
    },
}));

const messages = {
    en: {
        label: {
            availability: 'Availability',
            available: 'Available',
            out_of_stock: 'Out of stock',
            toggle_unavailable: 'Mark unavailable',
            toggle_available: 'Mark available',
            availability_changed: 'Availability updated',
        },
    },
};

function mountToggle(props = {}) {
    const store = createStore({
        modules: { itemAvailability },
    });
    const i18n = createI18n({ legacy: true, locale: 'en', messages });
    return mount(AvailabilityToggleComponent, {
        global: { plugins: [store, i18n] },
        props: {
            itemId: 42,
            branchId: null,
            isAvailable: true,
            ...props,
        },
    });
}

describe('AvailabilityToggleComponent (admin)', () => {
    beforeEach(() => {
        vi.mocked(axios.post).mockReset();
        vi.mocked(axios.post).mockResolvedValue({ data: { ok: true } });
    });

    it('POSTs toggle with expected payload when marking unavailable', async () => {
        const wrapper = mountToggle({ isAvailable: true });
        await wrapper.find('button').trigger('click');
        await flushPromises();
        expect(axios.post).toHaveBeenCalledWith('admin/menu/availability/toggle', {
            item_id: 42,
            branch_id: null,
            is_available: false,
            unavailable_reason: null,
        });
    });

    it('emits availability-changed after successful toggle', async () => {
        const wrapper = mountToggle({ isAvailable: true });
        await wrapper.find('button').trigger('click');
        await flushPromises();
        expect(wrapper.emitted('availability-changed')).toBeTruthy();
        expect(wrapper.emitted('availability-changed')[0]).toEqual([{ itemId: 42, isAvailable: false }]);
    });

    it('stores error message on failed response', async () => {
        vi.mocked(axios.post).mockRejectedValue({
            response: { data: { message: 'Forbidden' } },
        });
        const wrapper = mountToggle();
        const store = wrapper.vm.$store;
        await wrapper.find('button').trigger('click');
        await flushPromises();
        expect(store.state.itemAvailability.lastError).toBe('Forbidden');
    });
});
