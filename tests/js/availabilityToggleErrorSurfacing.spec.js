import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createStore } from 'vuex';
import AvailabilityToggleComponent from '../../resources/js/components/admin/items/AvailabilityToggleComponent.vue';
import appService from '../../resources/js/services/appService';

vi.mock('../../resources/js/services/appService', () => ({
    default: {
        alertError: vi.fn(),
    },
}));

function mountToggle(dispatch) {
    const store = createStore({
        modules: {
            itemAvailability: {
                namespaced: true,
                state: () => ({ pending: {} }),
                actions: {
                    toggle: dispatch,
                },
            },
        },
    });

    return mount(AvailabilityToggleComponent, {
        props: {
            itemId: 1,
            branchId: 7,
            isAvailable: true,
        },
        global: {
            plugins: [store],
            mocks: {
                $t: (key) => key,
            },
        },
    });
}

describe('AvailabilityToggleComponent error surfacing', () => {
    beforeEach(() => {
        vi.mocked(appService.alertError).mockClear();
    });

    it('emits toggle-error event when store dispatch rejects', async () => {
        const dispatch = vi.fn().mockRejectedValue(new Error('Network down'));
        const wrapper = mountToggle(dispatch);

        await wrapper.find('button').trigger('click');
        await flushPromises();

        expect(dispatch).toHaveBeenCalled();
        expect(wrapper.emitted('toggle-error')).toBeTruthy();
        expect(wrapper.vm.lastErrorMessage).toBe('Network down');
    });

    it('calls alertError and reverts optimistic state on failed toggle', async () => {
        const dispatch = vi.fn().mockRejectedValue({
            response: { data: { message: 'Forbidden' } },
        });
        const wrapper = mountToggle(dispatch);

        await wrapper.find('button').trigger('click');
        await flushPromises();

        expect(appService.alertError).toHaveBeenCalledWith('Forbidden');
        expect(wrapper.vm.displayIsAvailable).toBe(true);
    });

    it('does not emit error on successful toggle', async () => {
        const dispatch = vi.fn().mockResolvedValue({});
        const wrapper = mountToggle(dispatch);

        await wrapper.find('button').trigger('click');
        await flushPromises();

        expect(wrapper.emitted('toggle-error')).toBeFalsy();
        expect(appService.alertError).not.toHaveBeenCalled();
    });
});
