import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import axios from 'axios';
import { mount, flushPromises } from '@vue/test-utils';
import { createStore } from 'vuex';
import IngredientAvailabilityToggleComponent from '../../resources/js/components/admin/ingredients/IngredientAvailabilityToggleComponent.vue';
import { ingredients } from '../../resources/js/store/modules/ingredients';

vi.mock('axios', () => ({
    default: {
        put: vi.fn(),
    },
}));

function mountToggle(props = {}) {
    const store = createStore({
        modules: {
            ingredients: {
                ...ingredients,
                state: () => ({
                    list: [{
                        global_id: 'attribute:1',
                        type: 'attribute',
                        name: 'Steak',
                        is_available: true,
                        unavailable_reason: null,
                        used_by_count: 2,
                    }],
                    loading: false,
                    error: null,
                    lastTogglingGlobalId: null,
                }),
            },
        },
    });

    return mount(IngredientAvailabilityToggleComponent, {
        props: {
            globalId: 'attribute:1',
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
}

describe('IngredientAvailabilityToggleComponent optimistic behavior', () => {
    beforeEach(() => {
        vi.mocked(axios.put).mockReset();
        vi.spyOn(window, 'prompt').mockReturnValue('No stock');
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('prompts for a reason and calls the API when toggling ON to OFF', async () => {
        vi.mocked(axios.put).mockResolvedValue({
            data: {
                data: {
                    global_id: 'attribute:1',
                    type: 'attribute',
                    name: 'Steak',
                    is_available: false,
                    unavailable_reason: 'No stock',
                    used_by_count: 2,
                },
            },
        });
        const wrapper = mountToggle();

        await wrapper.find('button').trigger('click');
        await flushPromises();

        expect(window.prompt).toHaveBeenCalledWith('label.ingredient.toggle_prompt_reason', '');
        expect(axios.put).toHaveBeenCalledWith('/admin/ingredients/attribute%3A1/availability', {
            is_available: false,
            reason: 'No stock',
        });
        expect(wrapper.emitted('toggled')).toBeTruthy();
    });

    it('updates the visible switch state before the API resolves', async () => {
        let resolveRequest;
        vi.mocked(axios.put).mockImplementation(() => new Promise((resolve) => {
            resolveRequest = resolve;
        }));
        const wrapper = mountToggle();

        await wrapper.find('button').trigger('click');

        expect(wrapper.find('button').attributes('aria-checked')).toBe('false');

        resolveRequest({ data: { data: { global_id: 'attribute:1', is_available: false } } });
        await flushPromises();
    });

    it('rolls back visible state when the API rejects', async () => {
        vi.mocked(axios.put).mockRejectedValue(new Error('Network down'));
        const wrapper = mountToggle();

        await wrapper.find('button').trigger('click');
        await flushPromises();

        expect(wrapper.find('button').attributes('aria-checked')).toBe('true');
        expect(wrapper.emitted('error')).toBeTruthy();
    });
});
