import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createStore } from 'vuex';
import IngredientAvailabilityToggleComponent from '../../resources/js/components/admin/ingredients/IngredientAvailabilityToggleComponent.vue';

function mountToggle({ props = {}, pending = false, toggleAvailability = vi.fn().mockResolvedValue({ data: { data: null } }) } = {}) {
    const globalId = props.globalId || 'attribute:1';
    const store = createStore({
        modules: {
            ingredients: {
                namespaced: true,
                state: () => ({
                    lastTogglingGlobalId: pending ? globalId : null,
                }),
                actions: {
                    toggleAvailability,
                },
            },
        },
    });

    const wrapper = mount(IngredientAvailabilityToggleComponent, {
        props: {
            globalId,
            isAvailable: true,
            reason: null,
            ...props,
        },
        global: {
            plugins: [store],
            mocks: {
                $t: (key) => key,
            },
        },
    });

    return { wrapper, toggleAvailability };
}

describe('IngredientAvailabilityToggleComponent a11y', () => {
    beforeEach(() => {
        vi.spyOn(window, 'prompt').mockReturnValue('No stock');
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('exposes a switch role with aria-checked synced to isAvailable', () => {
        const { wrapper } = mountToggle({ props: { isAvailable: false, reason: 'No stock' } });
        const toggle = wrapper.get('[role="switch"]');

        expect(toggle.attributes('aria-checked')).toBe('false');
    });

    it('toggles when Space is pressed', async () => {
        const { wrapper, toggleAvailability } = mountToggle();

        await wrapper.get('[role="switch"]').trigger('keydown.space');
        await flushPromises();

        expect(toggleAvailability).toHaveBeenCalledOnce();
        expect(wrapper.emitted('toggled')).toBeTruthy();
    });

    it('toggles when Enter is pressed', async () => {
        const { wrapper, toggleAvailability } = mountToggle();

        await wrapper.get('[role="switch"]').trigger('keydown.enter');
        await flushPromises();

        expect(toggleAvailability).toHaveBeenCalledOnce();
        expect(wrapper.emitted('toggled')).toBeTruthy();
    });

    it('sets aria-busy while the toggle is pending', () => {
        const { wrapper } = mountToggle({ pending: true });

        expect(wrapper.get('[role="switch"]').attributes('aria-busy')).toBe('true');
    });
});
